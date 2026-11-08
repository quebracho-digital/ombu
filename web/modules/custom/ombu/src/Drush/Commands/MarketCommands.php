<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ombu\Embeddings;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\ombu\SellerProbe;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the market.
 */
final class MarketCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The public 8004scan API.
   */
  private const API = 'https://api.8004scan.io/api/v1';

  /**
   * Milliseconds between calls to 8004scan, set by the survey's --delay.
   */
  private int $delayMs = 2100;

  /**
   * The seller probe the survey asks each agent's endpoint with.
   */
  private ?SellerProbe $probe = NULL;

  public function __construct(
    #[Autowire(service: 'ombu.circle_importer')]
    private readonly CircleCatalogImporter $importer,
    #[Autowire(service: 'ombu.embeddings')]
    private readonly Embeddings $embeddings,
    private readonly QueueFactory $queueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Imports the Circle Agent Marketplace catalogue as listings.
   */
  #[CLI\Command(name: 'ombu:import-circle')]
  #[CLI\Option(name: 'max', description: 'Stop after this many resources (0 = all).')]
  #[CLI\Usage(name: 'drush ombu:import-circle --max=50', description: 'Import the first 50.')]
  public function importCircle(array $options = ['max' => 0]): void {
    $counts = $this->importer->import((int) $options['max']);
    $this->logger()->success(json_encode($counts));
  }

  /**
   * Queues the listings whose text has no current vector.
   *
   * Then run the queue: each text is embedded once (Embeddings).
   */
  #[CLI\Command(name: 'ombu:embed')]
  #[CLI\Usage(name: 'drush ombu:embed && drush queue:run ombu_embed', description: 'Embed every listing that needs it.')]
  public function embed(): void {
    if (!$this->embeddings->available() || !$this->embeddings->model()) {
      throw new \RuntimeException('Needs the ombu_listing_vector table (MariaDB 11.7+, drush updb) and a default AI provider for embeddings.');
    }
    $queue = $this->queueFactory->get(Embeddings::QUEUE);
    $queued = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'listing')->execute();
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $listing) {
        if ($listing instanceof ProductInterface && $this->embeddings->stale($listing)) {
          $queue->createItem($listing->id());
          $queued++;
        }
      }
      $storage->resetCache($chunk);
    }
    $this->logger()->success("$queued listings queued; " . $queue->numberOfItems() . ' in the queue.');
  }

  /**
   * Surveys the 8004scan agents that claim x402 and count the importable ones.
   *
   * Reads every candidate (x402_supported, is_testnet=false,
   * is_endpoint_verified=true), follows its detail, asks its web endpoint for
   * a 402 with accepts (SellerProbe: public addresses only), matches its x402
   * wallet and owner to existing listings, and checks the feedbacks of the
   * agents that answer 402 for a proofOfPayment that resolves.
   *
   * Read-only: no entities are written. Every failure is counted with its
   * reason, so a low number can be told apart from a broken survey.
   */
  #[CLI\Command(name: 'ombu:8004scan-survey')]
  #[CLI\Option(name: 'delay', description: 'Milliseconds between calls to 8004scan (anonymous limit: 30 a minute, so 2100).')]
  #[CLI\Usage(name: 'drush ombu:8004scan-survey', description: 'Survey 8004scan candidates and print a table.')]
  public function survey8004scan(array $options = ['delay' => 2100]): void {
    if (!class_exists(PageFetcher::class)) {
      throw new \RuntimeException('The survey needs ai_provider_universal_factcheck (SellerProbe, PageFetcher).');
    }
    $this->delayMs = (int) $options['delay'];
    $this->probe = new SellerProbe($this->httpClient);

    $this->logger()->warning('Starting 8004scan survey...');
    $reasons = [];

    $candidates = $this->getCandidates();
    $this->logger()->warning('Candidates: ' . count($candidates) . '.');
    if (!$candidates) {
      return;
    }

    $agents = [];
    foreach ($candidates as $candidate) {
      try {
        $agents[] = $this->api('/agents/' . $candidate['chain_id'] . '/' . $candidate['token_id']);
      }
      catch (\RuntimeException $e) {
        $this->tally($reasons, 'detail: ' . $e->getMessage());
      }
    }
    $this->logger()->warning('Details fetched: ' . count($agents) . ' of ' . count($candidates) . '.');

    $results = $this->probeAgents($agents, $reasons);
    $this->matchWallets($results, $this->getListingWallets());
    $this->checkFeedbacks($results, $reasons);
    $this->printTable($results, $reasons, count($candidates));
  }

  /**
   * Every candidate, following the cursor (the API pages 20 by default).
   */
  private function getCandidates(): array {
    $candidates = [];
    $query = [
      'x402_supported' => 'true',
      'is_testnet' => 'false',
      'is_endpoint_verified' => 'true',
      'limit' => 100,
    ];
    $pages = 0;
    do {
      $page = $this->api('/agents', $query);
      $candidates = array_merge($candidates, $page['items'] ?? []);
      $query['cursor'] = (string) ($page['next_cursor'] ?? '');
      $pages++;
    } while (!empty($page['has_more']) && $query['cursor'] !== '' && $pages < 50);
    return $candidates;
  }

  /**
   * One GET to 8004scan, paced to the anonymous limit. Throws with the reason.
   */
  private function api(string $path, array $query = []): array {
    usleep($this->delayMs * 1000);
    try {
      $response = $this->httpClient->request('GET', self::API . $path, [
        'query' => $query,
        'timeout' => 30,
      ]);
    }
    catch (RequestException $e) {
      $status = $e->getResponse()?->getStatusCode();
      throw new \RuntimeException($status ? "HTTP $status" : 'unreachable', 0, $e);
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body)) {
      throw new \RuntimeException('HTTP ' . $response->getStatusCode() . ', not JSON');
    }
    return $body;
  }

  /**
   * Asks each agent's web endpoint for its 402, through SellerProbe.
   */
  private function probeAgents(array $agents, array &$reasons): array {
    $results = [];
    foreach ($agents as $agent) {
      $result = [
        'chain_id' => $agent['chain_id'] ?? '',
        'token_id' => $agent['token_id'] ?? '',
        'agent_id' => $agent['agent_id'] ?? '',
        'name' => $agent['name'] ?? '',
        'owner_address' => strtolower((string) ($agent['owner_address'] ?? '')),
        'agent_wallet' => strtolower((string) ($agent['agent_wallet'] ?? '')),
        'endpoint_url' => NULL,
        'has_402' => FALSE,
        'accepts' => [],
        'wallet_match' => FALSE,
        'wallet_match_id' => NULL,
        'feedback_count' => 0,
        'feedback_score' => 0,
        'proof_count' => 0,
        'feedbacks' => [],
      ];

      $endpoint = $this->findEndpoint($agent);
      if ($endpoint === NULL) {
        $this->tally($reasons, 'probe: no http(s) endpoint');
        $results[] = $result;
        continue;
      }
      $result['endpoint_url'] = $endpoint;

      try {
        [, $result['accepts']] = $this->probe->requirements($endpoint, 'GET');
        $result['has_402'] = TRUE;
        $this->tally($reasons, 'probe: 402 with accepts');
      }
      catch (\RuntimeException $e) {
        $this->tally($reasons, 'probe: ' . $this->shortReason($e->getMessage()));
      }
      $results[] = $result;
    }
    return $results;
  }

  /**
   * The endpoint to ask: services.web first (the HTTP one), then agent_url.
   *
   * Services is an object keyed by protocol: {"web": {"endpoint": "..."}}.
   */
  private function findEndpoint(array $agent): ?string {
    $candidates = [$agent['services']['web']['endpoint'] ?? NULL, $agent['agent_url'] ?? NULL];
    foreach ($candidates as $url) {
      if (!is_string($url) || $url === '') {
        continue;
      }
      if (!str_contains($url, '://')) {
        $url = 'https://' . $url;
      }
      if (preg_match('#^https?://#i', $url)) {
        return $url;
      }
    }
    return NULL;
  }

  /**
   * Feedbacks of each agent that answers 402, and whether they prove a payment.
   */
  private function checkFeedbacks(array &$results, array &$reasons): void {
    foreach ($results as &$result) {
      if (!$result['has_402']) {
        continue;
      }
      try {
        $page = $this->api('/feedbacks', ['agent_id' => $result['agent_id'], 'limit' => 50]);
      }
      catch (\RuntimeException $e) {
        $this->tally($reasons, 'feedbacks: ' . $e->getMessage());
        continue;
      }
      $scores = [];
      foreach ($page['items'] ?? [] as $feedback) {
        if (!empty($feedback['is_revoked'])) {
          $this->tally($reasons, 'feedback: revoked');
          continue;
        }
        $result['feedbacks'][] = $feedback;
        if (is_numeric($feedback['score'] ?? NULL)) {
          $scores[] = (float) $feedback['score'];
        }
        $proof = $this->feedbackProof($feedback);
        $this->tally($reasons, 'feedback proof: ' . $proof['status']);
        if ($proof['proof'] !== NULL) {
          $result['proof_count']++;
        }
      }
      $result['feedback_count'] = count($result['feedbacks']);
      $result['feedback_score'] = $scores ? round(array_sum($scores) / count($scores), 1) : 0;
    }
    unset($result);
  }

  /**
   * The proofOfPayment of a feedback, read from its off-chain file.
   *
   * The file is the offchain_data inline, or the feedback_uri over https (the
   * same SSRF guard as the seller probe). ipfs:// and ar:// are not fetched.
   *
   * @return array{status: string, proof: ?array}
   */
  private function feedbackProof(array $feedback): array {
    $files = [];
    if (is_array($feedback['offchain_data'] ?? NULL)) {
      $files[] = $feedback['offchain_data'];
    }
    $uri = $feedback['feedback_uri'] ?? NULL;
    if (is_string($uri) && $uri !== '') {
      if (!preg_match('#^https?://#i', $uri)) {
        return ['status' => 'uri not fetched (' . (parse_url($uri, PHP_URL_SCHEME) ?: 'unknown') . ')', 'proof' => NULL];
      }
      try {
        $decoded = json_decode($this->probe->fetch($uri), TRUE);
        $files[] = is_array($decoded) ? $decoded : [];
      }
      catch (\Throwable $e) {
        return ['status' => 'uri error: ' . $this->shortReason($e->getMessage()), 'proof' => NULL];
      }
    }
    foreach ($files as $file) {
      $proof = $file['proofOfPayment'] ?? NULL;
      if (is_array($proof) && $this->wellFormedProof($proof)) {
        return ['status' => 'resolves', 'proof' => $proof];
      }
    }
    return ['status' => 'no proof', 'proof' => NULL];
  }

  /**
   * The fields a payment proof must carry to point at a transaction.
   */
  private function wellFormedProof(array $proof): bool {
    return preg_match('/^0x[0-9a-fA-F]{40}$/', (string) ($proof['fromAddress'] ?? '')) === 1
      && preg_match('/^0x[0-9a-fA-F]{40}$/', (string) ($proof['toAddress'] ?? '')) === 1
      && preg_match('/^0x[0-9a-fA-F]{64}$/', (string) ($proof['txHash'] ?? '')) === 1
      && is_numeric($proof['chainId'] ?? NULL);
  }

  /**
   * Every existing listing wallet (field_seller_address and payTo), lowercase.
   *
   * @return array
   *   ['address' => listing_id] for EVM addresses.
   */
  private function getListingWallets(): array {
    $wallets = [];
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'listing')->execute();
    foreach ($storage->loadMultiple($ids) as $listing) {
      if (!$listing instanceof ProductInterface) {
        continue;
      }
      // field_seller_address.
      $address = $listing->get('field_seller_address')->value;
      if ($address && preg_match('/^0x[0-9a-fA-F]{40}$/i', $address)) {
        $wallets[strtolower($address)] = $listing->id();
      }
      // payTo in field_accepts.
      $accepts = json_decode((string) $listing->get('field_accepts')->value, TRUE);
      if (is_array($accepts)) {
        foreach ($accepts as $accept) {
          $payTo = $accept['payTo'] ?? NULL;
          if ($payTo && preg_match('/^0x[0-9a-fA-F]{40}$/i', (string) $payTo)) {
            $key = strtolower((string) $payTo);
            if (!isset($wallets[$key])) {
              $wallets[$key] = $listing->id();
            }
          }
        }
      }
    }
    return $wallets;
  }

  /**
   * Matches the agent's x402 wallet, then its owner, to an existing listing.
   */
  private function matchWallets(array &$results, array $listingWallets): void {
    foreach ($results as &$result) {
      foreach (array_unique(array_filter([$result['agent_wallet'], $result['owner_address']])) as $wallet) {
        if (isset($listingWallets[$wallet])) {
          $result['wallet_match'] = TRUE;
          $result['wallet_match_id'] = $listingWallets[$wallet];
          break;
        }
      }
    }
    unset($result);
  }

  /**
   * Prints the survey: the counts, every failure reason, and the matches.
   */
  private function printTable(array $results, array $reasons, int $candidates): void {
    $with402 = array_filter($results, fn($r) => $r['has_402']);
    $matching = array_filter($with402, fn($r) => $r['wallet_match']);
    $proofs = array_sum(array_column($with402, 'proof_count'));
    $feedbacks = array_sum(array_column($with402, 'feedback_count'));

    $this->logger()->warning('=== 8004scan Survey Results ===');
    $this->logger()->warning("Candidates: $candidates; with a 402 and accepts: " . count($with402));
    $this->logger()->warning('Wallet matches an existing listing: ' . count($matching));
    $this->logger()->warning("Feedbacks of those agents: $feedbacks; with a proofOfPayment that resolves: $proofs");

    arsort($reasons);
    $this->logger()->warning('Reasons (every result is counted once):');
    foreach ($reasons as $reason => $n) {
      $this->logger()->warning(sprintf('  %4d  %s', $n, $reason));
    }

    if ($matching) {
      $this->logger()->warning('Agents with a 402 that match an existing listing:');
      foreach ($matching as $agent) {
        $this->logger()->warning(sprintf(
          '  %s (%s) -> listing #%d, wallet %s, score %.1f, %d feedbacks',
          $agent['name'],
          $agent['agent_id'],
          $agent['wallet_match_id'],
          $agent['agent_wallet'] ?: $agent['owner_address'],
          $agent['feedback_score'],
          $agent['feedback_count']
        ));
      }
    }

    $chains = [];
    foreach ($with402 as $r) {
      $chain = $r['chain_id'] ?: 'unknown';
      $chains[$chain] = ($chains[$chain] ?? 0) + 1;
    }
    if ($chains) {
      $chainStr = implode(', ', array_map(fn($c, $n) => "$c: $n", array_keys($chains), $chains));
      $this->logger()->warning("Agents with 402 by chain: $chainStr");
    }

    // The stop rule from the task: fewer than 5 passing the 402 means Leo decides.
    if (count($with402) < 5) {
      $this->logger()->error('Fewer than 5 agents pass the 402 probe (' . count($with402) . "). Work should stop for Leo's review.");
    }
  }

  /**
   * Counts one outcome under its reason.
   */
  private function tally(array &$reasons, string $reason): void {
    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
  }

  /**
   * A failure message short enough for a table row, with its URLs hidden.
   */
  private function shortReason(string $message): string {
    $message = preg_replace('#https?://\S+#', '<url>', $message) ?? $message;
    return mb_substr($message, 0, 70);
  }

}

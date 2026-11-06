<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the market.
 */
final class MarketCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'ombu.circle_importer')]
    private readonly \Drupal\ombu\CircleCatalogImporter $importer,
    #[Autowire(service: 'ombu.embeddings')]
    private readonly \Drupal\ombu\Embeddings $embeddings,
    private readonly \Drupal\Core\Queue\QueueFactory $queueFactory,
    private readonly \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager,
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
    $queue = $this->queueFactory->get(\Drupal\ombu\Embeddings::QUEUE);
    $queued = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'listing')->execute();
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $listing) {
        if ($listing instanceof \Drupal\commerce_product\Entity\ProductInterface && $this->embeddings->stale($listing)) {
          $queue->createItem($listing->id());
          $queued++;
        }
      }
      $storage->resetCache($chunk);
    }
    $this->logger()->success("$queued listings queued; " . $queue->numberOfItems() . ' in the queue.');
  }

  /**
   * Survey the 8004scan API to measure how many agents are importable.
   *
   * Reads the ~77 candidates (x402_supported, is_testnet=false,
   * is_endpoint_verified=true), fetches each agent's detail, probes its
   * endpoint with a simple HTTP request (no 402 guard: just status 402),
   * matches wallets to existing listings, and inspects feedbacks for
   * proofOfPayment.
   *
   * This is a read-only command: no entities are written.
   */
  #[CLI\Command(name: 'ombu:8004scan-survey')]
  #[CLI\Usage(name: 'drush ombu:8004scan-survey', description: 'Survey 8004scan candidates and print a table.')]
  public function survey8004scan(): void {
    $api = 'https://api.8004scan.io/api/v1';
    $this->logger()->warning('Starting 8004scan survey...');

    // Step 1: Get the candidate list.
    $candidates = $this->getCandidates($api);
    if (empty($candidates)) {
      $this->logger()->warning('No candidates returned from /agents.');
      return;
    }
    $this->logger()->warning('Found ' . count($candidates) . ' candidates.');

    // Step 2: Fetch details for each candidate.
    $agents = [];
    $detailErrors = 0;
    foreach ($candidates as $i => $agent) {
      if (($i + 1) % 10 === 0) {
        $this->logger()->warning("Fetched details: $i/" . count($candidates) . " (errors: $detailErrors)");
      }
      $detail = $this->getAgentDetail($api, $agent['chain_id'], $agent['token_id']);
      if ($detail === NULL) {
        $detailErrors++;
        continue;
      }
      $agents[] = $detail;
    }
    $this->logger()->warning("Agent details fetched: " . count($agents) . " (errors: $detailErrors)");

    // Step 3: Probe each agent's endpoint and gather metrics.
    $results = $this->probeAgents($agents);

    // Step 4: Match wallets to existing listings.
    $listingWallets = $this->getListingWallets();
    $this->matchWallets($results, $listingWallets);

    // Step 5: Check feedbacks for proofOfPayment.
    $feedbackResults = $this->checkFeedbacks($api, $results);

    // Step 6: Print the table.
    $this->printTable($results, $feedbackResults);
  }

  /**
   * Get the list of candidates from /agents.
   */
  private function getCandidates(string $api): array {
    try {
      $response = $this->httpClient->request('GET', "$api/agents", [
        'query' => [
          'x402_supported' => 'true',
          'is_testnet' => 'false',
          'is_endpoint_verified' => 'true',
        ],
        'timeout' => 30,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
      return $body['items'] ?? [];
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to fetch candidates: @e', ['@e' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Get the detail of a single agent.
   */
  private function getAgentDetail(string $api, string $chainId, string $tokenId): ?array {
    try {
      $response = $this->httpClient->request('GET', "$api/agents/$chainId/$tokenId", [
        'timeout' => 30,
      ]);
      return json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Probe each agent's endpoint for 402 and gather metrics.
   */
  private function probeAgents(array $agents): array {
    $results = [];

    foreach ($agents as $agent) {
      $result = [
        'chain_id' => $agent['chain_id'] ?? '',
        'token_id' => $agent['token_id'] ?? '',
        'agent_id' => $agent['agent_id'] ?? '',
        'name' => $agent['name'] ?? '',
        'owner_address' => strtolower($agent['owner_address'] ?? $agent['agent_wallet'] ?? ''),
        'has_endpoint' => FALSE,
        'endpoint_url' => NULL,
        'has_402' => FALSE,
        'accepts' => [],
        'wallet_match' => FALSE,
        'wallet_match_id' => NULL,
        'has_feedback' => FALSE,
        'feedback_score' => 0,
        'feedback_count' => 0,
        'proofOfPayment' => FALSE,
        'probe_error' => NULL,
      ];

      // Get the endpoint URL from services or agent_url.
      $endpoint = $this->findEndpoint($agent);
      if ($endpoint === NULL) {
        continue;
      }

      $result['has_endpoint'] = TRUE;
      $result['endpoint_url'] = $endpoint;

      // Probe the endpoint for 402 using plain HTTP (no SSRF guard needed
      // for a survey: we just check status code).
      try {
        $response = $this->httpClient->request('GET', $endpoint, [
          'timeout' => 15,
          'allow_redirects' => FALSE,
          'http_errors' => FALSE,
          'headers' => ['Accept' => 'application/json'],
        ]);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 402) {
          $body = json_decode((string) $response->getBody(), TRUE);
          // Prefer PAYMENT-REQUIRED header (x402 v2), fall back to body.
          $header = $response->getHeaderLine('PAYMENT-REQUIRED');
          if ($header !== '') {
            $headerDecoded = json_decode(base64_decode($header, TRUE), TRUE);
            $body = $headerDecoded ?: $body;
          }
          if (is_array($body['accepts'] ?? NULL)) {
            $result['has_402'] = TRUE;
            $result['accepts'] = $body['accepts'];
          }
        }
      }
      catch (\Exception $e) {
        $result['probe_error'] = $e->getMessage();
      }

      // Check feedbacks for this agent.
      $result['feedbacks'] = $this->getAgentFeedbacks($api, $result['agent_id']);
      $result['has_feedback'] = !empty($result['feedbacks']);
      $result['feedback_count'] = count($result['feedbacks']);
      if (!empty($result['feedbacks'])) {
        $totalScore = array_sum(array_column($result['feedbacks'], 'score'));
        $result['feedback_score'] = round($totalScore / count($result['feedbacks']), 1);
      }

      $results[] = $result;
    }

    return $results;
  }

  /**
   * Find the HTTP(S) endpoint URL from an agent detail.
   */
  private function findEndpoint(array $agent): ?string {
    // agent_url is the top-level endpoint.
    if (!empty($agent['agent_url']) && str_starts_with($agent['agent_url'], 'http')) {
      return $agent['agent_url'];
    }
    // services may contain multiple endpoints.
    if (!empty($agent['services']) && is_array($agent['services'])) {
      foreach ($agent['services'] as $service) {
        if (isset($service['endpoint']) && str_starts_with((string) $service['endpoint'], 'http')) {
          return (string) $service['endpoint'];
        }
      }
    }
    // agent_url without http prefix: try https.
    if (!empty($agent['agent_url'])) {
      return 'https://' . ltrim($agent['agent_url'], 'http:/');
    }
    return NULL;
  }

  /**
   * Get feedbacks for an agent.
   */
  private function getAgentFeedbacks(string $api, string $agentId): array {
    try {
      $response = $this->httpClient->request('GET', "$api/feedbacks", [
        'query' => [
          'agent_id' => $agentId,
          'limit' => 10,
        ],
        'timeout' => 15,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
      return $body['items'] ?? [];
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Get all existing listing wallets (field_seller_address and payTo).
   *
   * @return array
   *   ['address' => listing_id] for EVM addresses (lowercase).
   */
  private function getListingWallets(): array {
    $wallets = [];
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'listing')->execute();
    foreach ($storage->loadMultiple($ids) as $listing) {
      if (!$listing instanceof \Drupal\commerce_product\Entity\ProductInterface) {
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
   * Match agent wallets to existing listing wallets.
   */
  private function matchWallets(array &$results, array $listingWallets): void {
    foreach ($results as &$result) {
      if (empty($result['owner_address'])) {
        continue;
      }
      if (isset($listingWallets[$result['owner_address']])) {
        $result['wallet_match'] = TRUE;
        $result['wallet_match_id'] = $listingWallets[$result['owner_address']];
      }
    }
  }

  /**
   * Check feedbacks for proofOfPayment on each agent.
   */
  private function checkFeedbacks(string $api, array &$results): array {
    $proofCount = 0;
    $noProof = 0;
    foreach ($results as &$result) {
      if (!$result['has_endpoint'] || !$result['has_feedback']) {
        continue;
      }
      $hasProof = FALSE;
      foreach ($result['feedbacks'] as $fb) {
        // The prompt says proofOfPayment lives in the off-chain file
        // pointed to by feedback_uri / offchain_data. We check both.
        $offchain = $fb['offchain_data'] ?? NULL;
        $feedbackUri = $fb['feedback_uri'] ?? NULL;
        if ((is_string($offchain) && !empty($offchain)) || (is_string($feedbackUri) && !empty($feedbackUri))) {
          $hasProof = TRUE;
          $proofCount++;
          continue 2;
        }
        $noProof++;
      }
      if ($hasProof) {
        $result['proofOfPayment'] = TRUE;
      }
    }
    return ['proof_count' => $proofCount, 'no_proof' => $noProof];
  }

  /**
   * Print the survey results table.
   */
  private function printTable(array $results, array $feedbackResults): void {
    $total = count($results);
    $hasEndpoint = count(array_filter($results, fn($r) => $r['has_endpoint']));
    $has402 = count(array_filter($results, fn($r) => $r['has_402']));
    $has402AndNoProbeError = count(array_filter($results, fn($r) => $r['has_endpoint'] && $r['has_402'] && empty($r['probe_error'])));
    $walletMatch = count(array_filter($results, fn($r) => $r['has_402'] && $r['wallet_match']));
    $hasFeedback = count(array_filter($results, fn($r) => $r['has_feedback']));
    $hasFeedbackWithProof = count(array_filter($results, fn($r) => $r['proofOfPayment']));
    $noEndpoint = count(array_filter($results, fn($r) => !$r['has_endpoint']));

    $this->logger()->warning('=== 8004scan Survey Results ===');
    $this->logger()->warning("Candidates with agent details: $total");
    $this->logger()->warning("With HTTP(S) endpoint: $hasEndpoint");
    $this->logger()->warning("No usable endpoint: $noEndpoint");
    $this->logger()->warning("Responds 402 with accepts: $has402AndNoProbeError");
    $this->logger()->warning("Wallet matches existing listing: $walletMatch");
    $this->logger()->warning("Has feedbacks: $hasFeedback");
    $this->logger()->warning("Has feedbacks with proofOfPayment: $hasFeedbackWithProof");

    // Print matching agents.
    $matching = array_filter($results, fn($r) => $r['has_402'] && $r['wallet_match']);
    if ($matching) {
      $this->logger()->warning('Agents matching existing listings:');
      foreach ($matching as $agent) {
        $this->logger()->warning(sprintf(
          '  %s (%s) -> listing #%d, wallet %s, score %.1f, %d feedbacks',
          $agent['name'],
          $agent['agent_id'],
          $agent['wallet_match_id'],
          $agent['owner_address'],
          $agent['feedback_score'],
          $agent['feedback_count']
        ));
      }
    }

    // If fewer than 5 agents pass 402, stop and inform Leo.
    if ($has402 < 5) {
      $this->logger()->error("Less than 5 agents pass the 402 probe ($has402). Work should stop for Leo's review.");
    }

    // Print breakdown by chain.
    $chains = [];
    foreach ($results as $r) {
      if ($r['has_402']) {
        $chain = $r['chain_id'] ?: 'unknown';
        $chains[$chain] = ($chains[$chain] ?? 0) + 1;
      }
    }
    if ($chains) {
      $chainStr = implode(', ', array_map(fn($c, $n) => "$c: $n", array_keys($chains), $chains));
      $this->logger()->warning("Agents with 402 by chain: $chainStr");
    }
  }

}

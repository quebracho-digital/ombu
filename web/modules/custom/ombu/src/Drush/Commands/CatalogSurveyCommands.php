<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\ombu\SellerProbe;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\Networks;
use Quebracho\X402\TokenBlacklist;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Surveys the x402 discovery catalogues (CDP Bazaar, PayAI): how many of
 * their resources an importer could take.
 *
 * Read-only: no entity is written. For a random sample of unique resources it
 * asks the endpoint for its 402 (SellerProbe), checks the live accepts against
 * the catalogue's (amount, payTo, asset, network), and asks the token contract
 * whether payTo is frozen (quebracho/x402 TokenBlacklist). Every failure is
 * counted with its reason.
 */
final class CatalogSurveyCommands extends DrushCommands {

  use AutowireTrait;

  private const SOURCES = [
    'bazaar' => 'https://api.cdp.coinbase.com/platform/v2/x402/discovery/resources',
    'payai' => 'https://facilitator.payai.network/discovery/resources',
  ];

  /**
   * Mainnet USDC checked on chain: chain id => [asset, Alchemy subdomain].
   *
   * Arc mainnet (eip155:5042) and Solana are not asked: no verified contract
   * or function here, so they are reported as "unchecked", never as clean.
   */
  private const USDC = [
    1 => ['0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 'eth-mainnet'],
    8453 => ['0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'base-mainnet'],
    137 => ['0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 'polygon-mainnet'],
    42161 => ['0xaf88d065e77c8cc2239b62233e7a4bdd5c8f9a31', 'arb-mainnet'],
    10 => ['0x0b2c639c533813f4aa9d7837caf62653d097ff85', 'opt-mainnet'],
    43114 => ['0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e', 'avax-mainnet'],
  ];

  public function __construct(
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Surveys Bazaar and PayAI and prints the counts per reason.
   */
  #[CLI\Command(name: 'ombu:catalog-survey')]
  #[CLI\Option(name: 'source', description: 'bazaar, payai or all.')]
  #[CLI\Option(name: 'pages', description: 'Catalogue pages of 100 to read per source.')]
  #[CLI\Option(name: 'sample', description: 'Unique resources to probe per source.')]
  #[CLI\Option(name: 'seed', description: 'Random seed, so a run can be repeated.')]
  #[CLI\Usage(name: 'drush ombu:catalog-survey --pages=20 --sample=150', description: 'The 2026-11-06 measurement, with validation.')]
  public function survey(array $options = ['source' => 'all', 'pages' => 20, 'sample' => 150, 'seed' => 7]): void {
    $key = (string) getenv('ALCHEMY_API_KEY');
    if ($key === '') {
      throw new \RuntimeException('ALCHEMY_API_KEY is not set (.ddev/.env): the blacklist needs a node.');
    }
    $tokens = [];
    foreach (self::USDC as $chain => [$asset, $sub]) {
      Networks::add($chain, $asset, 'USD Coin', '2', 6, "eip155:$chain", "https://$sub.g.alchemy.com/v2/$key");
      $tokens["eip155:$chain|$asset"] = 'isBlacklisted';
    }
    $factory = new HttpFactory();
    $blacklist = new TokenBlacklist($this->httpClient, $factory, $factory, FALSE, $tokens);
    $probe = new SellerProbe($this->httpClient);
    mt_srand((int) $options['seed']);

    $sources = $options['source'] === 'all' ? array_keys(self::SOURCES) : [$options['source']];
    foreach ($sources as $source) {
      if (!isset(self::SOURCES[$source])) {
        throw new \InvalidArgumentException("Unknown source: $source.");
      }
      $resources = $this->fetch($source, (int) $options['pages']);
      $urls = array_keys($resources);
      shuffle($urls);
      $urls = array_slice($urls, 0, (int) $options['sample']);

      $tally = [];
      $hosts = [];
      foreach ($urls as $url) {
        $verdict = $this->check($resources[$url], $url, $probe, $blacklist);
        $tally[$verdict] = ($tally[$verdict] ?? 0) + 1;
        if ($verdict === 'importable' || $verdict === 'importable: payTo unchecked') {
          $hosts[parse_url($url, PHP_URL_HOST)] = TRUE;
        }
      }
      arsort($tally);
      $this->io()->title(sprintf('%s: %d unique resources read, %d probed, importable on %d hosts', $source, count($resources), count($urls), count($hosts)));
      $this->io()->table(['Verdict', 'Resources'], array_map(NULL, array_keys($tally), array_values($tally)));
    }
  }

  /**
   * The catalogue's items, keyed by resource URL.
   */
  private function fetch(string $source, int $pages): array {
    $resources = [];
    for ($page = 0; $page < $pages; $page++) {
      $response = $this->httpClient->request('GET', self::SOURCES[$source], [
        'query' => ['limit' => 100, 'offset' => $page * 100],
        'timeout' => 30,
      ]);
      $items = json_decode((string) $response->getBody(), TRUE)['items'] ?? [];
      foreach ($items as $item) {
        $url = $item['resource'] ?? $item['accepts'][0]['resource'] ?? NULL;
        if (is_string($url) && $url !== '') {
          $resources[$url] = $item;
        }
      }
      if (count($items) < 100) {
        break;
      }
    }
    return $resources;
  }

  /**
   * One resource's verdict: "importable" or the first reason it is not.
   */
  private function check(array $item, string $url, SellerProbe $probe, TokenBlacklist $blacklist): string {
    $method = strtoupper((string) ($item['extensions']['bazaar']['info']['input']['method']
      ?? $item['accepts'][0]['outputSchema']['input']['method'] ?? 'GET'));
    try {
      [, $accepts] = $probe->requirements($url, $method === 'POST' ? 'POST' : 'GET');
    }
    catch (\RuntimeException $e) {
      // Group by the message's start: "answered 404", "could not be reached"...
      return 'probe: ' . preg_replace('/^(The endpoint (answered \d+|could not be reached)|[^:.]+).*/s', '$1', $e->getMessage());
    }

    // Each live requirement must be one the catalogue announced: same
    // network, asset, payTo and amount. Otherwise the catalogue lies.
    $announced = [];
    foreach ($item['accepts'] ?? [] as $a) {
      $announced[] = $this->fingerprint($a);
    }
    $unchecked = FALSE;
    foreach ($accepts as $req) {
      if (!is_array($req) || ($req['scheme'] ?? '') !== 'exact' || empty($req['network']) || empty($req['payTo']) || empty($req['asset'])) {
        return 'accepts: malformed or not exact';
      }
      $amount = (string) ($req['amount'] ?? $req['maxAmountRequired'] ?? '');
      if (!ctype_digit($amount) || ltrim($amount, '0') === '') {
        return 'accepts: amount not a positive integer';
      }
      if (!in_array($this->fingerprint($req), $announced, TRUE)) {
        return 'accepts: differs from the catalogue (amount, payTo or asset)';
      }
      $chain = str_starts_with($req['network'], 'eip155:') ? (int) substr($req['network'], 7) : 0;
      if (!isset(self::USDC[$chain]) || strtolower((string) $req['asset']) !== self::USDC[$chain][0]) {
        $unchecked = TRUE;
        continue;
      }
      $check = new AddressCheck(AddressCheck::PAY_TO, $req['network'], (string) $req['payTo'], $req);
      try {
        $blacklist($check);
      }
      catch (\Throwable) {
        return 'blacklist: node did not answer (not imported)';
      }
      if ($check->reason() !== NULL) {
        return 'blacklist: ' . $check->reason();
      }
    }
    return $unchecked ? 'importable: payTo unchecked' : 'importable';
  }

  /**
   * What must match between the catalogue and the live 402.
   */
  private function fingerprint(array $a): string {
    return strtolower(implode('|', [
      $a['network'] ?? '',
      $a['asset'] ?? '',
      $a['payTo'] ?? '',
      $a['amount'] ?? $a['maxAmountRequired'] ?? '',
    ]));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\ombu\SellerProbe;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Drupal\ombu\ResourceVerifier;
use Quebracho\X402\Rpc;
use Quebracho\X402\Networks;
use Quebracho\X402\TokenBlacklist;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Surveys the x402 discovery catalogues (CDP Bazaar, PayAI): how many of
 * their resources an importer could take.
 *
 * Read-only: no entity is written. For a random sample of unique resources it
 * asks the endpoint for its 402 (SellerProbe), checks the live accepts against
 * the catalogue's, and asks whether payTo is frozen or on OFAC's list
 * (ResourceVerifier, the importers' own checks). Every failure is counted
 * with its reason; "unchecked" (no freeze list, node down) is never importable.
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
    $ethereum = new Rpc($this->httpClient, $factory, $factory, 'https://eth-mainnet.g.alchemy.com/v2/' . $key);
    $verifier = new ResourceVerifier(new SellerProbe($this->httpClient), $blacklist, $ethereum, $this->transfers($key), $tokens);
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
        $verdict = $this->check($resources[$url], $url, $verifier);
        $tally[$verdict] = ($tally[$verdict] ?? 0) + 1;
        if ($verdict === 'importable') {
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
  private function check(array $item, string $url, ResourceVerifier $verifier): string {
    $method = strtoupper((string) ($item['extensions']['bazaar']['info']['input']['method']
      ?? $item['accepts'][0]['outputSchema']['input']['method'] ?? 'GET'));
    $report = $verifier->verify($url, $method, $item['accepts'] ?? []);
    if ($report['verdict'] === ResourceVerifier::PASS) {
      return 'importable';
    }
    foreach ($report['checks'] as $name => [$result, $detail]) {
      if ($result === $report['verdict'] && !str_ends_with($name, '.transfers')) {
        // Group by check and the detail's start: "answered 404", "frozen"...
        $name = preg_replace('/^accepts\[\d+\]/', 'accepts', $name);
        return $report['verdict'] . ' ' . $name . ': ' . preg_replace('/^(The endpoint (answered \d+|could not be reached)|[^:.(]+).*/s', '$1', $detail);
      }
    }
    return $report['verdict'];
  }

  /**
   * USDC transfers payTo received, through Alchemy (capped at 100).
   */
  private function transfers(string $key): \Closure {
    return function (string $network, string $asset, string $payTo) use ($key): int {
      $chain = str_starts_with($network, 'eip155:') ? (int) substr($network, 7) : 0;
      if (!isset(self::USDC[$chain])) {
        throw new \RuntimeException("No index for $network.");
      }
      $response = $this->httpClient->request('POST', 'https://' . self::USDC[$chain][1] . ".g.alchemy.com/v2/$key", [
        'timeout' => 15,
        'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'alchemy_getAssetTransfers', 'params' => [[
          'toAddress' => $payTo, 'contractAddresses' => [$asset], 'category' => ['erc20'], 'maxCount' => '0x64', 'excludeZeroValue' => TRUE,
        ]]],
      ]);
      $transfers = json_decode((string) $response->getBody(), TRUE)['result']['transfers'] ?? NULL;
      return is_array($transfers) ? count($transfers) : throw new \RuntimeException('No answer.');
    };
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Drupal\ombu\ResourceVerifier;
use Drupal\ombu\ResourceVerifierFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Surveys the x402 discovery catalogues (CDP Bazaar, PayAI).
 *
 * Counts how many of their resources an importer could take.
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
    $verifier = ResourceVerifierFactory::create($this->httpClient);
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
    $report = $verifier->verify($url, $method, $item['accepts'] ?? [], ResourceVerifierFactory::networks());
    if ($report['verdict'] === ResourceVerifier::PASS) {
      // The importers' own rule: some USDC has come in to payTo.
      return ($report['activity'] ?? 0) >= 1 ? 'importable' : 'inactive: no incoming USDC';
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

}

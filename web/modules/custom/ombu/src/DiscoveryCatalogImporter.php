<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Imports an x402 discovery catalogue (CDP Bazaar, PayAI) as listings.
 *
 * Both answer `GET {api}?limit&offset` with `items[]` of `{resource, accepts,
 * description, ...}`. A catalogue says what a seller claims; a resource
 * becomes a listing only when Ombú has checked it itself (ResourceVerifier):
 * the live 402 answers with the same network, asset, payTo and amount the
 * catalogue lists, payTo is not frozen by the token nor on OFAC's list, and
 * payTo has received USDC on chain. Whatever cannot be asked is not imported
 * and, if it was imported before, not retired either: only a "no" retires (a
 * failed check, no activity on chain, or the catalogue no longer lists it).
 *
 * Verifying costs a request to the seller and to a node, so a run verifies at
 * most $budget resources that are new or changed; the rest wait for the next
 * run. A listing whose catalogue entry did not change is not asked again.
 *
 * Owns one source: it never touches the listings of another, and a listing
 * staff delete stays deleted (state `ombu.<source>_blocked`).
 */
final class DiscoveryCatalogImporter implements CatalogImporterInterface {

  /**
   * Bump to refresh every listing once, when the import learns a new field.
   */
  private const FORMAT = 1;

  /**
   * Builds the importer of one catalogue.
   *
   * @param string $source
   *   The value `bazaar` or `payai`: the listings' `field_source` and market.
   * @param string $api
   *   The catalogue's discovery URL.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Reads the catalogue.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads and saves the listings.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logs each run's counts.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Keeps two runs of one source apart.
   * @param \Drupal\ombu\ResourceVerifier $verifier
   *   The checks a resource passes before it is listed.
   * @param \Drupal\Core\State\StateInterface $state
   *   Holds the listings staff took down and the time of the last run.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The clock.
   * @param int $budget
   *   Resources to verify per run (0 = no limit).
   * @param int $minTransfers
   *   Incoming USDC transfers payTo must have received to be listed.
   */
  public function __construct(
    private readonly string $source,
    private readonly string $api,
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly LockBackendInterface $lock,
    private readonly ResourceVerifier $verifier,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly int $budget = 200,
    private readonly int $minTransfers = 1,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function source(): string {
    return $this->source;
  }

  /**
   * {@inheritdoc}
   *
   * The counts: created, updated, unchanged, exists (another source has it),
   * blocked, unsupported (no exact requirement in a USDC Ombú verifies),
   * rejected, inactive, unverified (a node or the seller did not answer),
   * deferred (over the budget), retired. `reasons` tallies the rejections.
   */
  public function import(int $max = 0): array {
    $lock = "ombu.{$this->source}_import";
    if (!$this->lock->acquire($lock, 3600)) {
      throw new \RuntimeException("A {$this->source} catalogue import is already running.");
    }
    try {
      return $this->doImport($max);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Reads the catalogue page by page and lists what passes the checks.
   *
   * @param int $max
   *   Stop after reading this many resources (0 = all).
   *
   * @return array
   *   The count of each outcome, and the tally of the reasons for rejections.
   */
  private function doImport(int $max): array {
    $counts = array_fill_keys([
      'created', 'updated', 'unchanged', 'exists', 'blocked', 'unsupported',
      'rejected', 'inactive', 'unverified', 'deferred', 'retired',
    ], 0);
    $reasons = [];
    $seen = [];
    $verified = 0;
    $read = 0;
    $offset = 0;
    $complete = FALSE;
    $done = [];
    do {
      $page = json_decode((string) $this->httpClient->request('GET', $this->api, [
        'query' => ['limit' => 100, 'offset' => $offset],
        'timeout' => 30,
      ])->getBody(), TRUE);
      // An empty or broken answer is not an empty catalogue: it would retire
      // every listing of the source.
      if (!is_array($page) || !is_array($page['items'] ?? NULL)) {
        throw new \RuntimeException("The {$this->source} catalogue did not answer with a list of items.");
      }
      $items = $page['items'];
      foreach ($items as $item) {
        $normal = self::normalize($item);
        if ($normal === NULL) {
          $counts['unsupported']++;
          continue;
        }
        // The same URL listed twice in one run is one resource.
        if (isset($done[$normal['key']])) {
          continue;
        }
        $done[$normal['key']] = TRUE;
        $read++;
        $outcome = $this->handle($normal, $verified, $reasons, $seen);
        $counts[$outcome]++;
        if ($max && $read >= $max) {
          break 2;
        }
      }
      $offset += 100;
      $complete = count($items) < 100 || $offset >= (int) ($page['pagination']['total'] ?? 0);
    } while (!$complete);

    // Only a complete run knows what disappeared.
    if ($complete && !$max) {
      // A catalogue that suddenly lists far less than last time is more likely
      // broken than emptied: keep what we have and say so.
      $lastRead = (int) $this->state->get("ombu.{$this->source}_read", 0);
      if ($read === 0 || ($lastRead && $read < $lastRead / 2)) {
        $counts['retire_skipped'] = "read $read, the run before read $lastRead";
      }
      else {
        $counts['retired'] = $this->retireMissing($seen);
        $this->state->set("ombu.{$this->source}_read", $read);
      }
      $this->state->set("ombu.{$this->source}_last", $this->time->getRequestTime());
    }
    arsort($reasons);
    $counts['reasons'] = $reasons;
    $this->loggerFactory->get('ombu')->info('@source catalogue import: @c', [
      '@source' => $this->source,
      '@c' => json_encode($counts),
    ]);
    return $counts;
  }

  /**
   * One resource: verify it if it is new or changed, then list it.
   *
   * @param array $r
   *   The resource, as normalize() returns it.
   * @param int $verified
   *   How many resources this run has verified so far (counted here).
   * @param array $reasons
   *   The tally of the reasons for rejections (counted here).
   * @param array $seen
   *   Keys of the resources whose listing stays (not retired by this run).
   *
   * @return string
   *   The outcome, a key of the counts.
   */
  private function handle(array $r, int &$verified, array &$reasons, array &$seen): string {
    if (isset($this->state->get(self::blockedKey($this->source), [])[$r['key']])) {
      return 'blocked';
    }
    $assets = ResourceVerifierFactory::assets();
    $pays = fn($a) => $a['scheme'] === 'exact'
      && in_array(strtolower($a['network'] . '|' . ($a['asset'] ?? '')), $assets, TRUE);
    if (!array_filter($r['accepts'], $pays)) {
      return 'unsupported';
    }
    $products = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_endpoint', $r['url'])
      ->condition('field_http_method', $r['method'])
      ->range(0, 1)->execute();
    $product = $ids ? $products->load(reset($ids)) : NULL;
    if ($product && $product->get('field_source')->value !== $this->source) {
      return 'exists';
    }
    // Whatever happens next, an existing listing stays unless a check says no.
    $keep = function () use ($product, $r, &$seen): void {
      if ($product) {
        $seen[$r['key']] = TRUE;
      }
    };
    $hash = hash('sha256', json_encode([self::FORMAT, $r['hash']]));
    if ($product && $product->get('field_source_hash')->value === $hash && !$product->get('field_retired')->value) {
      $keep();
      return 'unchanged';
    }
    if ($this->budget && $verified >= $this->budget) {
      $keep();
      return 'deferred';
    }
    $verified++;
    try {
      $report = $this->verifier->verify($r['url'], $r['method'], $r['accepts'], $assets);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ombu')->warning('Verifying @url: @m', ['@url' => $r['url'], '@m' => $e->getMessage()]);
      $keep();
      return 'unverified';
    }
    if ($report['verdict'] === ResourceVerifier::UNCHECKED) {
      $keep();
      return 'unverified';
    }
    if ($report['verdict'] === ResourceVerifier::FAIL) {
      $reasons[self::reason($report['checks'])] = ($reasons[self::reason($report['checks'])] ?? 0) + 1;
      return 'rejected';
    }
    if ((int) ($report['activity'] ?? 0) < $this->minTransfers) {
      $reasons['no incoming USDC'] = ($reasons['no incoming USDC'] ?? 0) + 1;
      return 'inactive';
    }
    $seen[$r['key']] = TRUE;
    return $this->save($product, $r, $report['accepts'], $hash);
  }

  /**
   * The first failed check, grouped: "accepts.freeze: address_blacklisted".
   */
  private static function reason(array $checks): string {
    foreach ($checks as $name => [$result, $detail]) {
      if ($result === ResourceVerifier::FAIL) {
        return preg_replace('/^accepts\[\d+\]/', 'accepts', $name) . ': ' . trim(preg_replace('/^(The endpoint (answered \d+|could not be reached)|[^:.(]+).*/s', '$1', (string) $detail));
      }
    }
    return 'failed';
  }

  /**
   * Creates or updates the listing of a verified resource.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface|null $product
   *   The listing of this resource, if there is one.
   * @param array $r
   *   The resource, as normalize() returns it.
   * @param array $accepts
   *   The live requirements that passed: what the listing is sold under.
   * @param string $hash
   *   Of the catalogue entry, to tell a change next time.
   *
   * @return string
   *   `created` or `updated`.
   */
  private function save(?ProductInterface $product, array $r, array $accepts, string $hash): string {
    // The cheapest way to pay. Every verified asset is USDC (6 decimals).
    usort($accepts, fn($a, $b) => (int) ($a['amount'] ?? $a['maxAmountRequired']) <=> (int) ($b['amount'] ?? $b['maxAmountRequired']));
    $best = $accepts[0];
    $price = new Price(Calculator::divide((string) ($best['amount'] ?? $best['maxAmountRequired']), '1000000', 6), 'USD');
    $values = [
      'title' => $r['title'],
      'body' => ['value' => $r['description'], 'format' => 'plain_text'],
      'field_endpoint' => $r['url'],
      'field_http_method' => $r['method'],
      'field_pay_to' => $best['payTo'],
      'field_network' => array_values(array_unique(array_column($accepts, 'network'))),
      'field_accepts' => json_encode($accepts, JSON_UNESCAPED_SLASHES),
      'field_input_schema' => $r['input'] ? json_encode($r['input'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_output_schema' => $r['output'] ? json_encode($r['output'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_provider' => mb_substr($r['provider'], 0, 255),
      'field_provider_term' => $this->provider($r['provider'], $r['website']),
      'field_rails' => ['vanilla'],
      'field_siwx' => FALSE,
      'field_retired' => FALSE,
      'field_source_hash' => $hash,
      'field_source' => $this->source,
      'field_markets' => [$this->source],
      'field_verified' => FALSE,
      'field_source_updated' => $r['updated'],
      'field_tags' => array_map(fn($t) => $this->term('listing_tag', $t), $r['tags']),
      'status' => TRUE,
    ];
    $outcome = $product ? 'updated' : 'created';
    if (!$product) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      $product = $this->entityTypeManager->getStorage('commerce_product')->create([
        'type' => 'listing',
        'stores' => [$store],
      ]);
    }
    foreach ($values as $field => $value) {
      $product->set($field, $value);
    }
    $variation = $product->get('variations')->entity ?? $this->entityTypeManager->getStorage('commerce_product_variation')->create(['type' => 'listing']);
    $variation->setSku($this->source . '-' . substr(hash('sha256', $r['key']), 0, 16));
    $variation->setPrice($price);
    $variation->save();
    if (!$product->hasVariation($variation)) {
      $product->addVariation($variation);
    }
    $product->save();
    return $outcome;
  }

  /**
   * A catalogue entry as one shape, or NULL when it cannot be a listing.
   *
   * Bazaar and PayAI differ in where they put the method and the schemas; the
   * `extensions.bazaar.info` both carry is the common place.
   *
   * @return array|null
   *   Keys: key, url, method, accepts, title, description, provider, website,
   *   tags, input, output, updated and hash.
   */
  public static function normalize(array $item): ?array {
    $url = $item['resource'] ?? NULL;
    if (!is_string($url) || !preg_match('#^https://[^/\s]+#i', $url)) {
      return NULL;
    }
    $accepts = array_values(array_filter($item['accepts'] ?? [], fn($a) => is_array($a)
      && isset($a['scheme'], $a['network'], $a['payTo'])
      && (isset($a['amount']) || isset($a['maxAmountRequired']))));
    if (!$accepts) {
      return NULL;
    }
    $info = $item['extensions']['bazaar']['info'] ?? [];
    $method = strtoupper((string) ($info['input']['method'] ?? $item['method'] ?? 'GET'));
    $host = (string) parse_url($url, PHP_URL_HOST);
    $description = trim((string) ($item['description'] ?? ''));
    $provider = trim((string) ($item['serviceName'] ?? '')) ?: $host;
    // Volatile fields (quality counters, the clock) do not make it "changed".
    $stable = $item;
    unset($stable['quality'], $stable['lastUpdated']);
    return [
      'key' => $method . ' ' . $url,
      'url' => $url,
      'method' => $method,
      'accepts' => $accepts,
      'title' => mb_substr($description !== '' ? $description : $provider . ' ' . (parse_url($url, PHP_URL_PATH) ?: ''), 0, 255),
      'description' => $description,
      'provider' => $provider,
      'website' => 'https://' . $host,
      'tags' => array_slice(array_values(array_filter(array_map(
        fn($t) => mb_substr(trim((string) $t), 0, 40),
        array_filter((array) ($item['tags'] ?? []), 'is_scalar'),
      ))), 0, 10),
      'input' => ($info['input'] ?? $item['inputSchema'] ?? NULL) ?: NULL,
      'output' => ($info['output'] ?? $item['outputSchema'] ?? NULL) ?: NULL,
      'updated' => (string) ($item['lastUpdated'] ?? ''),
      'hash' => json_encode($stable),
    ];
  }

  /**
   * Keeps a deleted listing of a catalogue from coming back with the import.
   */
  public static function block(ProductInterface $listing): void {
    $source = (string) $listing->get('field_source')->value;
    if ($listing->bundle() !== 'listing' || !in_array($source, ['bazaar', 'payai'], TRUE)) {
      return;
    }
    $state = \Drupal::state();
    $key = strtoupper((string) $listing->get('field_http_method')->value) . ' ' . $listing->get('field_endpoint')->value;
    $state->set(self::blockedKey($source), [$key => TRUE] + $state->get(self::blockedKey($source), []));
  }

  /**
   * The state key of the listings of a source that staff took down.
   */
  private static function blockedKey(string $source): string {
    return "ombu.{$source}_blocked";
  }

  /**
   * The provider's term, created from its name and site.
   */
  private function provider(string $name, string $website): ?int {
    if (!$tid = $this->term('listing_provider', $name)) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if (!$term->get('field_website')->value) {
      $term->set('field_website', $website)->save();
    }
    return $tid;
  }

  /**
   * Loads or creates a term by name.
   */
  private function term(string $vid, string $name): ?int {
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') {
      return NULL;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $found = $terms->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $found ? reset($found) : $terms->create(['vid' => $vid, 'name' => $name]);
    if ($term->isNew()) {
      $term->save();
    }
    return (int) $term->id();
  }

  /**
   * Retires this source's listings that the last full run did not keep.
   */
  private function retireMissing(array $seen): int {
    $products = $this->entityTypeManager->getStorage('commerce_product');
    $query = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_source', $this->source);
    $ids = $query->condition($query->orConditionGroup()
      ->notExists('field_retired')
      ->condition('field_retired', 0))
      ->execute();
    $n = 0;
    foreach ($products->loadMultiple($ids) as $product) {
      $key = strtoupper((string) $product->get('field_http_method')->value) . ' ' . $product->get('field_endpoint')->value;
      if (!isset($seen[$key])) {
        $product->set('field_retired', TRUE)->save();
        $n++;
      }
    }
    return $n;
  }

}

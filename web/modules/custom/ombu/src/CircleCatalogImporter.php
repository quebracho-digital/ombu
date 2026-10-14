<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use GuzzleHttp\ClientInterface;

/**
 * Imports the Circle Agent Marketplace catalogue as listing products.
 *
 * The Discovery API is public and read-only. Each resource becomes one
 * listing keyed by method + URL, under its provider (a term holding the
 * provider's site and OpenAPI). Each listing keeps the operation of its
 * provider's spec that documents it, so people and agents search the API
 * itself. A listing changes when what Circle says about it changes (a hash
 * of it, not only lastUpdated). Listings Circle no longer returns are
 * retired: still published and open to reviews, never for sale, never
 * deleted. A listing staff delete (an abuse report, a legal order) stays
 * deleted: its key is kept and the import skips it.
 */
final class CircleCatalogImporter {

  public const API = 'https://api.circle.com/v2/x402/discovery/resources';

  /**
   * State: method + URL of the Circle listings taken down, never imported again.
   */
  public const BLOCKED = 'ombu.circle_blocked';

  /**
   * Bump to refresh every listing once, when the import learns a new field.
   */
  private const FORMAT = 2;

  /**
   * Specs fetched in this run, by URL: one fetch per provider, not per item.
   *
   * @var array<string, array|null>
   */
  private array $specs = [];

  /**
   * Ids of our listings found on Circle in this run.
   */
  private array $onCircle = [];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly LockBackendInterface $lock,
    // Specs come through the guarded fetch (public addresses only, 5 MB at
    // most); without it listings are imported without their OpenAPI.
    private readonly ?PageFetcher $fetcher = NULL,
    // Recognizes our own listings when Circle lists them too.
    private readonly ?Sellers $sellers = NULL,
  ) {}

  /**
   * Imports every page; returns counts per outcome.
   *
   * @param int $max
   *   Stop after this many resources (0 = all). For trying it out.
   *
   * @throws \RuntimeException
   *   When another import (cron or drush) is already running: two at once
   *   both miss each other's new listings and create duplicates.
   */
  public function import(int $max = 0): array {
    if (!$this->lock->acquire('ombu.circle_import', 3600)) {
      throw new \RuntimeException('A Circle catalogue import is already running.');
    }
    try {
      return $this->doImport($max);
    }
    finally {
      $this->lock->release('ombu.circle_import');
    }
  }

  private function doImport(int $max): array {
    $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'ours' => 0, 'blocked' => 0, 'retired' => 0];
    $this->specs = [];
    $this->onCircle = [];
    $seen = [];
    $offset = 0;
    do {
      $page = json_decode((string) $this->httpClient->request('GET', self::API, [
        'query' => ['limit' => 100, 'offset' => $offset],
        'timeout' => 30,
      ])->getBody(), TRUE);
      foreach ($page['items'] ?? [] as $item) {
        $outcome = $this->upsert($item);
        $counts[$outcome]++;
        if (!in_array($outcome, ['skipped', 'ours', 'blocked'], TRUE)) {
          $seen[$this->key($item)] = TRUE;
        }
        if ($max && array_sum($counts) >= $max) {
          break 2;
        }
      }
      $offset += 100;
    } while ($offset < ($page['pagination']['total'] ?? 0));

    // Only a complete run knows what disappeared.
    if (!$max) {
      $counts['retired'] = $this->retireMissing($seen);
      $this->unmarkMissing();
      \Drupal::state()->set('ombu.circle_last', \Drupal::time()->getRequestTime());
    }
    $this->loggerFactory->get('ombu')->info('Circle catalogue import: @c', ['@c' => json_encode($counts)]);
    return $counts;
  }

  /**
   * Keeps a deleted Circle listing from coming back with the next import.
   */
  public static function block(ProductInterface $listing): void {
    if ($listing->bundle() !== 'listing' || $listing->get('field_source')->value !== 'circle') {
      return;
    }
    $state = \Drupal::state();
    $key = strtoupper((string) $listing->get('field_http_method')->value) . ' ' . $listing->get('field_endpoint')->value;
    $state->set(self::BLOCKED, [$key => TRUE] + $state->get(self::BLOCKED, []));
  }

  private function key(array $item): string {
    return strtoupper($item['metadata']['method'] ?? 'GET') . ' ' . $item['resource'];
  }

  /**
   * Creates or updates the listing for one resource.
   */
  private function upsert(array $item): string {
    $accepts = array_values(array_filter($item['accepts'] ?? [], fn($a) => ($a['scheme'] ?? '') === 'exact' && isset($a['amount'], $a['payTo'])));
    if (!$accepts || empty($item['resource'])) {
      return 'skipped';
    }
    $meta = $item['metadata'] ?? [];
    $provider = $meta['provider'] ?? [];
    $method = strtoupper($meta['method'] ?? 'GET');
    if (isset(\Drupal::state()->get(self::BLOCKED, [])[$this->key($item)])) {
      return 'blocked';
    }

    $products = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_endpoint', $item['resource'])
      ->condition('field_http_method', $method)
      ->range(0, 1)->execute();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $ids ? $products->load(reset($ids)) : NULL;

    // A listing of ours (or one added by hand) that Circle lists too: say
    // so on it, never overwrite it nor copy it.
    $ours = $product && $product->get('field_source')->value !== 'circle' ? $product : $this->ours($item['resource'], $accepts);
    if ($ours) {
      $this->onCircle[$ours->id()] = TRUE;
      $markets = array_column($ours->get('field_markets')->getValue(), 'value');
      if (!in_array('circle', $markets, TRUE)) {
        $ours->set('field_markets', [...$markets, 'circle'])->save();
      }
      return 'ours';
    }
    $updated = $item['lastUpdated'] ?? '';
    $openApiUrl = $provider['openApiUrl'] ?? NULL;
    $spec = $openApiUrl ? $this->spec($openApiUrl) : NULL;
    $operation = $spec ? OpenApi::operation($spec, $item['resource'], $method) : NULL;
    $hash = hash('sha256', json_encode([self::FORMAT, $item, $operation]));
    if ($product && $product->get('field_source_hash')->value === $hash && !$product->get('field_retired')->value) {
      return 'unchanged';
    }

    // Arc first (the hackathon chain), then the cheapest offer.
    usort($accepts, fn($a, $b) => [!str_starts_with($a['network'], 'eip155:5042'), (int) $a['amount']] <=> [!str_starts_with($b['network'], 'eip155:5042'), (int) $b['amount']]);
    $best = $accepts[0];
    // ponytail: assumes every asset is USDC (6 decimals), true for the whole
    // catalogue today; read the decimals per asset if non-USDC listings appear.
    $price = new Price(Calculator::divide((string) $best['amount'], '1000000', 6), 'USD');

    $title = trim($meta['description'] ?? '') ?: (($provider['name'] ?? 'Service') . ' ' . ($meta['path'] ?? ''));
    $values = [
      'title' => mb_substr($title, 0, 255),
      'body' => ['value' => $provider['description'] ?? '', 'format' => 'plain_text'],
      'field_endpoint' => $item['resource'],
      'field_http_method' => $method,
      'field_pay_to' => $best['payTo'],
      'field_network' => array_values(array_unique(array_column($accepts, 'network'))),
      'field_accepts' => json_encode($accepts, JSON_UNESCAPED_SLASHES),
      'field_input_schema' => isset($meta['input']) ? json_encode($meta['input'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_output_schema' => isset($meta['output']) ? json_encode($meta['output'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_provider' => mb_substr($provider['name'] ?? '', 0, 255),
      'field_provider_term' => $this->provider($provider),
      'field_openapi' => $operation ? json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : NULL,
      'field_rails' => array_keys(array_filter([
        'vanilla' => $meta['supportsVanillax402'] ?? TRUE,
        'gateway' => $meta['supportsCircleGateway'] ?? FALSE,
      ])),
      'field_siwx' => !empty($meta['siwx']),
      'field_retired' => FALSE,
      'field_source_hash' => $hash,
      'field_docs_url' => $provider['docsUrl'] ?? $provider['website'] ?? NULL,
      'field_source' => 'circle',
      'field_verified' => FALSE,
      'field_source_updated' => $updated,
      'field_category' => $this->term('listing_category', self::categoryLabel($provider['category'] ?? 'OTHER')),
      'field_tags' => array_map(fn($t) => $this->term('listing_tag', $t), array_slice($provider['tags'] ?? [], 0, 10)),
      'status' => TRUE,
    ];

    $outcome = $product ? 'updated' : 'created';
    if (!$product) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      $product = $products->create(['type' => 'listing', 'stores' => [$store]]);
    }
    foreach ($values as $field => $value) {
      $product->set($field, $value);
    }
    $variation = $product->getDefaultVariation() ?? $this->entityTypeManager->getStorage('commerce_product_variation')->create(['type' => 'listing']);
    $variation->setSku('circle-' . substr(hash('sha256', $this->key($item)), 0, 16));
    $variation->setPrice($price);
    $variation->save();
    if (!$product->hasVariation($variation)) {
      $product->addVariation($variation);
    }
    $product->save();
    return $outcome;
  }

  /**
   * The provider's term, created or updated from what Circle says of it.
   */
  private function provider(array $provider): ?int {
    if (!$tid = $this->term('listing_provider', $provider['name'] ?? '')) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $values = [
      'description' => $provider['description'] ?? '',
      'field_website' => $provider['website'] ?? NULL,
      'field_docs_url' => $provider['docsUrl'] ?? NULL,
      'field_openapi_url' => $provider['openApiUrl'] ?? NULL,
    ];
    $changed = FALSE;
    foreach ($values as $field => $value) {
      if ((string) $term->get($field)->value !== (string) $value) {
        $term->set($field, $value);
        $changed = TRUE;
      }
    }
    if ($changed) {
      $term->save();
    }
    return $tid;
  }

  /**
   * A provider's OpenAPI spec, fetched once per run; NULL if unusable.
   */
  private function spec(string $url): ?array {
    if (!array_key_exists($url, $this->specs)) {
      try {
        $this->specs[$url] = $this->fetcher ? OpenApi::parse($this->fetcher->fetch($url)) : NULL;
      }
      catch (\Exception $e) {
        // Too big (over the fetch's 5 MB), down, or not public: the listing
        // keeps Circle's own input and output schemas.
        $this->loggerFactory->get('ombu')->notice('OpenAPI @url: @m', ['@url' => $url, '@m' => $e->getMessage()]);
        $this->specs[$url] = NULL;
      }
    }
    return $this->specs[$url];
  }

  /**
   * A Circle category code as people read it: "WEB_SEARCH_RESEARCH" →
   * "Web search research". A name passes through unchanged.
   */
  public static function categoryLabel(string $code): string {
    return ucfirst(mb_strtolower(str_replace('_', ' ', trim($code))));
  }

  /**
   * A category name back to Circle's code, for its Discovery shape.
   */
  public static function categoryCode(string $label): string {
    return mb_strtoupper(str_replace(' ', '_', trim($label)));
  }

  /**
   * Loads or creates a term by name.
   */
  private function term(string $vid, string $name): ?int {
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') {
      return NULL;
    }
    static $cache = [];
    if (isset($cache[$vid][$name])) {
      return $cache[$vid][$name];
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $found = $terms->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $found ? reset($found) : $terms->create(['vid' => $vid, 'name' => $name]);
    if ($term->isNew()) {
      $term->save();
    }
    return $cache[$vid][$name] = (int) $term->id();
  }

  /**
   * Our listing that a Circle resource is, or NULL.
   *
   * Ours are sold at /x402/commerce_product/{id}/…; the id alone could be
   * another site's, so the listing must also pay one of the resource's
   * payTo addresses.
   */
  private function ours(string $resource, array $accepts): ?ProductInterface {
    if (!$this->sellers || !preg_match('#/x402/commerce_product/(\d+)(/|$)#', (string) parse_url($resource, PHP_URL_PATH), $m)) {
      return NULL;
    }
    $listing = $this->entityTypeManager->getStorage('commerce_product')->load($m[1]);
    if (!$listing instanceof ProductInterface || $listing->bundle() !== 'listing' || $listing->get('field_source')->value === 'circle') {
      return NULL;
    }
    $payTo = strtolower((string) $this->sellers->payTo($listing));
    return $payTo && in_array($payTo, array_map('strtolower', array_column($accepts, 'payTo')), TRUE) ? $listing : NULL;
  }

  /**
   * Unmarks our listings that the last full run no longer found on Circle.
   */
  private function unmarkMissing(): void {
    $products = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_markets', 'circle')
      ->execute();
    foreach ($products->loadMultiple(array_diff($ids, array_keys($this->onCircle))) as $product) {
      $markets = array_column($product->get('field_markets')->getValue(), 'value');
      $product->set('field_markets', array_values(array_diff($markets, ['circle'])))->save();
    }
  }

  /**
   * Retires Circle listings that the last full run did not return.
   */
  private function retireMissing(array $seen): int {
    $products = $this->entityTypeManager->getStorage('commerce_product');
    $query = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_source', 'circle');
    $ids = $query->condition($query->orConditionGroup()
      ->notExists('field_retired')
      ->condition('field_retired', FALSE))
      ->execute();
    $n = 0;
    foreach ($products->loadMultiple($ids) as $product) {
      $key = $product->get('field_http_method')->value . ' ' . $product->get('field_endpoint')->value;
      if (!isset($seen[$key])) {
        $product->set('field_retired', TRUE)->save();
        $n++;
      }
    }
    return $n;
  }

}

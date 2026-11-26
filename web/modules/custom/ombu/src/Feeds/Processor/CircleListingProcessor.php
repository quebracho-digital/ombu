<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Processor;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Item\ItemInterface;
use Drupal\ombu\Catalog\CatalogRun;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\ombu\OpenApi;

/**
 * Keeps the Circle Agent Marketplace catalogue as listings.
 *
 * Circle's Discovery API is public and read-only. Each resource becomes one
 * listing keyed by method + URL, under its provider (a term holding the
 * provider's site, docs and OpenAPI). Each listing keeps the operation of
 * its provider's spec that documents it, fetched once per provider per run
 * through the guarded fetch (public addresses only, 5 MB at most). A listing
 * changes when what Circle says about it changes (a hash of it, not only
 * lastUpdated). A listing of ours (or one added by hand) that Circle lists
 * too is marked with the `circle` market, never overwritten nor copied, and
 * unmarked when Circle no longer lists it. Providers without a logo get
 * their site's icon after a whole run.
 *
 * This is CircleCatalogImporter as a Feeds processor, with one deliberate
 * difference: the guards. The importer took a 200 without `items` for an
 * empty catalogue and retired every Circle listing; here an empty or broken
 * answer is an error (CatalogFetcher), and a catalogue that reads nothing or
 * less than half of the last whole run retires nothing and unmarks nothing
 * (ListingProcessorBase). The hash of an entry is the importer's, so a
 * listing it wrote is `unchanged` here.
 *
 * The form a third party lists a service with (SellForm) still writes
 * through CircleCatalogImporter::upsert(): it is not a catalogue run.
 *
 * @FeedsProcessor(
 *   id = "ombu_circle_listing",
 *   title = @Translation("Circle listing"),
 *   description = @Translation("Lists Circle's Agent Marketplace resources, with their provider and OpenAPI operation."),
 * )
 */
final class CircleListingProcessor extends ListingProcessorBase {

  /**
   * Bump to refresh every listing once, when the import learns a new field.
   *
   * The importer's, so both hash an entry the same way.
   */
  private const FORMAT = 3;

  /**
   * Specs fetched in this request, by URL: one fetch per provider.
   *
   * @var array<string, array|null>
   */
  protected array $specs = [];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['source' => 'circle'];
  }

  /**
   * {@inheritdoc}
   */
  protected function outcomes(): array {
    return ['created', 'updated', 'unchanged', 'skipped', 'ours', 'blocked', 'wallet_changed', 'retired'];
  }

  /**
   * {@inheritdoc}
   */
  protected function withReasons(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function begin(FeedInterface $feed, CatalogRun $run): void {
    parent::begin($feed, $run);
    $this->specs = [];
  }

  /**
   * {@inheritdoc}
   */
  protected function handle(FeedInterface $feed, ItemInterface $item, CatalogRun $run): array {
    $r = $item->toArray();
    $entry = $r['item'];
    $accepts = $r['accepts'];
    $exact = $r['exact'];
    $meta = $entry['metadata'] ?? [];
    $provider = $meta['provider'] ?? [];
    $method = $r['method'];
    if ($this->blocked($r['key'])) {
      return ['blocked', NULL];
    }
    $product = $this->listing($r['url'], $method);

    // A listing of ours (or one added by hand) that Circle lists too: say
    // so on it, never overwrite it nor copy it.
    $ours = $product && $product->get('field_source')->value !== $this->source() ? $product : $this->ours($r['url'], $accepts);
    if ($ours) {
      $run->found[(int) $ours->id()] = TRUE;
      $markets = array_column($ours->get('field_markets')->getValue(), 'value');
      if (!in_array($this->source(), $markets, TRUE)) {
        $ours->set('field_markets', [...$markets, $this->source()])->save();
      }
      return ['ours', NULL];
    }
    $keep = $product ? (int) $product->id() : NULL;
    $openApiUrl = $provider['openApiUrl'] ?? NULL;
    $operation = is_string($openApiUrl) && $openApiUrl !== '' ? $this->operation($openApiUrl, $entry, $r['url'], $method) : NULL;
    $hash = hash('sha256', json_encode([self::FORMAT, $entry, $operation]));
    if ($product && $product->get('field_source_hash')->value === $hash && !$product->get('field_retired')->value) {
      return ['unchanged', $keep];
    }

    // Arc first (the hackathon chain), then the cheapest offer.
    usort($exact, fn($a, $b) => [
      !str_starts_with($a['network'], 'eip155:5042'),
      (int) $a['amount'],
    ] <=> [!str_starts_with($b['network'], 'eip155:5042'), (int) $b['amount']]);
    $best = $exact[0];

    // A listing pays only the wallets it declared when it came in. Circle
    // answering with another one is refused and kept for staff, who may
    // accept it (drush ombu:wallet-changes circle --accept=ID): a changed
    // wallet is also what a tampered catalogue looks like. The listing is
    // not kept in the run, so the clean stage retires it: off sale (the
    // wallet it came in with may be abandoned, or someone else's) until
    // staff decide, with nothing overwritten.
    if ($product) {
      $declared = self::payees($product);
      $offered = array_values(array_unique(array_filter(array_column($accepts, 'payTo'))));
      if ($declared && array_diff($offered, $declared)) {
        $this->note('wallet_changed', (int) $product->id(), [
          'url' => $r['url'],
          'old' => $declared,
          'new' => $offered,
          'accepts' => $accepts,
          'time' => $this->time->getCurrentTime(),
        ]);
        return ['wallet_changed', NULL];
      }
      $this->note('wallet_changed', (int) $product->id(), NULL);
    }
    // Assumes every asset is USDC (6 decimals), as the importer does: true
    // for the whole catalogue today.
    $price = new Price(Calculator::divide((string) $best['amount'], '1000000', 6), 'USD');

    $title = trim((string) ($meta['description'] ?? '')) ?: (($provider['name'] ?? 'Service') . ' ' . ($meta['path'] ?? ''));
    $values = [
      'title' => mb_substr($title, 0, 255),
      'body' => ['value' => $provider['description'] ?? '', 'format' => 'plain_text'],
      'field_endpoint' => $r['url'],
      'field_http_method' => $method,
      'field_pay_to' => $best['payTo'],
      'field_network' => array_values(array_unique(array_column($accepts, 'network'))),
      'field_accepts' => json_encode($accepts, JSON_UNESCAPED_SLASHES),
      'field_input_schema' => isset($meta['input']) ? json_encode($meta['input'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_output_schema' => isset($meta['output']) ? json_encode($meta['output'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_provider' => mb_substr((string) ($provider['name'] ?? ''), 0, 255),
      'field_provider_term' => $this->provider($provider),
      'field_openapi' => $this->openapiJson($operation),
      'field_rails' => array_keys(array_filter([
        'vanilla' => $meta['supportsVanillax402'] ?? TRUE,
        'gateway' => $meta['supportsCircleGateway'] ?? FALSE,
      ])),
      'field_siwx' => !empty($meta['siwx']),
      'field_retired' => FALSE,
      'field_source_hash' => $hash,
      'field_docs_url' => $provider['docsUrl'] ?? $provider['website'] ?? NULL,
      'field_source' => $this->source(),
      'field_verified' => FALSE,
      'field_source_updated' => $entry['lastUpdated'] ?? '',
      'field_category' => $this->term('listing_category', CircleCatalogImporter::categoryLabel((string) ($provider['category'] ?? 'OTHER'))),
      'field_tags' => array_map(fn($t) => $this->term('listing_tag', (string) $t), array_slice(is_array($provider['tags'] ?? NULL) ? $provider['tags'] : [], 0, 10)),
      'status' => TRUE,
    ];
    $sku = $this->source() . '-' . substr(hash('sha256', $r['key']), 0, 16);
    return [$this->write($feed, $item, $product, $values, $sku, $price), $keep];
  }

  /**
   * Our listing that a Circle resource is, or NULL.
   *
   * Ours are sold at /x402/commerce_product/{id}/…; the id alone could be
   * another site's, so the listing must also pay one of the resource's
   * payTo addresses.
   */
  private function ours(string $resource, array $accepts): ?ProductInterface {
    if (!$this->container->has('ombu.sellers') || !preg_match('#/x402/commerce_product/(\d+)(/|$)#', (string) parse_url($resource, PHP_URL_PATH), $m)) {
      return NULL;
    }
    $listing = $this->products()->load($m[1]);
    if (!$listing instanceof ProductInterface || $listing->bundle() !== 'listing' || $listing->get('field_source')->value === $this->source()) {
      return NULL;
    }
    $payTo = strtolower((string) $this->container->get('ombu.sellers')->payTo($listing));
    return $payTo && in_array($payTo, array_map('strtolower', array_column($accepts, 'payTo')), TRUE) ? $listing : NULL;
  }

  /**
   * The provider's term, created or completed from what Circle says of it.
   *
   * The vocabulary is shared with Bazaar and PayAI and a term is found by
   * name alone, so an entry may name another seller's provider. Nothing a
   * term already holds is overwritten (a first writer wins) and a term that
   * already points to another site is not touched nor linked: otherwise any
   * entry could rewrite a provider's description, docs and OpenAPI URL, and
   * its listings would show under that provider.
   */
  private function provider(array $provider): ?int {
    if (!$tid = $this->term('listing_provider', (string) ($provider['name'] ?? ''))) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $website = (string) ($provider['website'] ?? '');
    if (!preg_match('#^https?://#i', $website) && preg_match('#^https?://#i', (string) ($provider['openApiUrl'] ?? ''))) {
      $website = (string) $provider['openApiUrl'];
    }
    $own = strtolower((string) parse_url($website, PHP_URL_HOST));
    $held = strtolower((string) parse_url((string) $term->get('field_website')->value, PHP_URL_HOST));
    if ($held !== '' && $own !== '' && $held !== $own) {
      $this->loggerFactory->get('ombu')->warning('Provider @name is another site\'s (@held, not @own): the entry keeps no provider.', [
        '@name' => (string) ($provider['name'] ?? ''),
        '@held' => $held,
        '@own' => $own,
      ]);
      return NULL;
    }
    $values = [
      'description' => $provider['description'] ?? '',
      'field_website' => $website !== '' ? $website : NULL,
      'field_docs_url' => $provider['docsUrl'] ?? NULL,
      'field_openapi_url' => $provider['openApiUrl'] ?? NULL,
    ];
    $changed = FALSE;
    foreach ($values as $field => $value) {
      // Only fills what is empty: an existing value (from any source) stands.
      if ((string) $value !== '' && (string) $term->get($field)->value === '') {
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
   * The operation as JSON, or NULL when it grew past 256 KB.
   */
  private function openapiJson(?array $operation): ?string {
    if (!$operation) {
      return NULL;
    }
    $json = json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > 262144) {
      $this->loggerFactory->get('ombu')->warning('OpenAPI operation skipped: over 256 KB after inlining.');
      return NULL;
    }
    return $json;
  }

  /**
   * The operation of a provider's spec, cached by the entry's hash.
   *
   * An unchanged entry hashes to the same key, so the spec is not downloaded
   * again: a hostile catalogue cannot make every run fetch its spec. The
   * cache is keyed by the entry, so a changed entry asks for the spec again.
   */
  private function operation(string $openApiUrl, array $entry, string $url, string $method): ?array {
    $cache = $this->container->get('cache.default');
    $key = 'ombu:openapi:' . hash('sha256', json_encode([
      $openApiUrl,
      hash('sha256', json_encode([self::FORMAT, $entry])),
      $url,
      $method,
    ]));
    $cached = $cache->get($key);
    if ($cached !== FALSE) {
      return is_array($cached->data) ? $cached->data : NULL;
    }
    $spec = $this->spec($openApiUrl);
    $operation = $spec ? OpenApi::operation($spec, $url, $method) : NULL;
    $cache->set($key, $operation, $operation === NULL ? 3600 : CacheBackendInterface::CACHE_PERMANENT);
    return $operation;
  }

  /**
   * A provider's OpenAPI spec, fetched once per request; NULL if unusable.
   *
   * Through the guarded fetch only (public addresses, 5 MB): without it the
   * listing keeps Circle's own input and output schemas.
   */
  private function spec(string $url): ?array {
    if (!array_key_exists($url, $this->specs)) {
      try {
        $fetcher = $this->container->has(PageFetcher::class) ? $this->container->get(PageFetcher::class) : NULL;
        $this->specs[$url] = $fetcher ? OpenApi::parse($fetcher->fetch($url)) : NULL;
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('ombu')->notice('OpenAPI @url: @m', ['@url' => $url, '@m' => $e->getMessage()]);
        $this->specs[$url] = NULL;
      }
    }
    return $this->specs[$url];
  }

  /**
   * {@inheritdoc}
   *
   * Our listings Circle no longer lists lose the market (only when the
   * guards let the run retire), and providers without a logo get one.
   */
  protected function afterWholeRun(FeedInterface $feed, CatalogRun $run): void {
    if ($run->retireAllowed) {
      $this->unmarkMissing($run->found);
    }
    $run->count('logos', $this->logos());
  }

  /**
   * Unmarks our listings that the last whole run no longer found on Circle.
   */
  private function unmarkMissing(array $found): void {
    $ids = $this->products()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_markets', $this->source())
      ->execute();
    foreach ($this->products()->loadMultiple(array_diff($ids, array_keys($found))) as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }
      $markets = array_column($product->get('field_markets')->getValue(), 'value');
      $product->set('field_markets', array_values(array_diff($markets, [$this->source()])))->save();
    }
  }

  /**
   * Gives each provider without a logo its site's icon; returns how many.
   *
   * Circle's catalogue has no logos: its marketplace shows the site's icon
   * from Google's favicon service, and so do we, saved once, not hotlinked.
   * Only a PNG under 1 MB is kept. Google answers 404 for a site without one.
   */
  private function logos(): int {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    if (!$terms->create(['vid' => 'listing_provider'])->hasField('field_logo')) {
      return 0;
    }
    $ids = $terms->getQuery()->accessCheck(FALSE)
      ->condition('vid', 'listing_provider')
      ->exists('field_website')
      ->notExists('field_logo')
      ->execute();
    $dir = 'public://providers';
    $this->container->get('file_system')->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $http = $this->container->get('http_client');
    $n = 0;
    foreach ($terms->loadMultiple($ids) as $term) {
      $host = parse_url((string) $term->get('field_website')->value, PHP_URL_HOST);
      // A host name only: it becomes a file name.
      if (!is_string($host) || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
        continue;
      }
      try {
        $response = $http->request('GET', 'https://www.google.com/s2/favicons', [
          'query' => ['domain' => $host, 'sz' => 64],
          'timeout' => 10,
        ]);
      }
      catch (\Exception) {
        continue;
      }
      $png = (string) $response->getBody();
      if (!str_starts_with($response->getHeaderLine('Content-Type'), 'image/png') || strlen($png) > 1048576) {
        continue;
      }
      $file = $this->container->get('file.repository')->writeData($png, "$dir/$host.png", FileExists::Replace);
      $term->set('field_logo', ['target_id' => $file->id(), 'alt' => $term->label()])->save();
      $n++;
    }
    return $n;
  }

}

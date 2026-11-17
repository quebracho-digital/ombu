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
 * The import can be narrowed (the "granularity" of what comes in): $onlyTags
 * keeps only resources carrying one of the tags named, and the coherence
 * filters ($priceMatch, $maxAgeDays) demand that payTo's incoming transfers
 * look like real sales of this listing: at least one of exactly the price it
 * had on that day, and no older than a window. A resource that passes the
 * checks but fails the coherence is 'inactive' with its reason, never
 * listed.
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
   * How long a check stands, in seconds: a week.
   *
   * A catalogue can say the same for months while the seller's own answer
   * changes (another wallet, another price): past this age a listing is
   * asked again though its entry did not change, within the run's budget.
   */
  public const MAX_AGE = 604800;

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
   * @param string[] $onlyTags
   *   When not empty, only resources carrying at least one of these tags are
   *   asked (the rest are 'skipped'); the granularity of what comes in.
   * @param bool $priceMatch
   *   With the detail from the transfer index: payTo must have received at
   *   least one transfer of exactly the price the listing had on that day
   *   (an `exact` payment moves the price, no more, no less). A wallet paid
   *   1,000 USDC for something else is not evidence a $0.005 listing sells.
   * @param int $maxAgeDays
   *   With the detail: the newest incoming transfer (with $priceMatch, the
   *   newest of the listing's price) no older than this many days (0 = any
   *   age). A sale of this listing happened recently.
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
    private readonly array $onlyTags = [],
    private readonly bool $priceMatch = FALSE,
    private readonly int $maxAgeDays = 0,
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
      'rejected', 'inactive', 'unverified', 'deferred', 'skipped', 'retired',
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
        if ($this->onlyTags && !array_intersect($this->onlyTags, $normal['tags'])) {
          // The granularity of what comes in: not this run's business. A
          // listing it already has stays (not retired), like anything the
          // verifier was not asked about.
          $products = $this->entityTypeManager->getStorage('commerce_product');
          $ids = $products->getQuery()->accessCheck(FALSE)
            ->condition('type', 'listing')
            ->condition('field_endpoint', $normal['url'])
            ->condition('field_http_method', $normal['method'])
            ->condition('field_source', $this->source)
            ->range(0, 1)->execute();
          if ($ids) {
            $seen[$normal['key']] = TRUE;
          }
          $counts['skipped']++;
          continue;
        }
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
   * The wallets a listing declared when it came in: the only ones it may pay.
   *
   * A catalogue entry that names another one (the seller moved, or someone
   * sits in the middle) is refused and the listing retired, until staff
   * accept the new wallet (acceptWallet()).
   *
   * @return string[]
   *   Its payTo and the payTo of each requirement kept with it.
   */
  private static function payees(ProductInterface $product): array {
    $payees = [(string) $product->get('field_pay_to')->value];
    foreach (json_decode((string) $product->get('field_accepts')->value, TRUE) ?: [] as $accept) {
      $payees[] = (string) ($accept['payTo'] ?? '');
    }
    return array_values(array_unique(array_filter($payees)));
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
    if ($product && $product->get('field_source_hash')->value === $hash && !$product->get('field_retired')->value && !$this->stale($product)) {
      $keep();
      return 'unchanged';
    }
    if ($this->budget && $verified >= $this->budget) {
      $keep();
      return 'deferred';
    }
    $verified++;
    try {
      $report = $this->verifier->verify($r['url'], $r['method'], $r['accepts'], $assets, $product ? self::payees($product) : []);
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
    if ($product) {
      $this->note('checked', (int) $product->id(), $this->time->getCurrentTime());
    }
    if ($report['verdict'] === ResourceVerifier::FAIL) {
      // Refused for its wallet alone: kept for staff, who may accept it
      // (drush ombu:wallet-changes).
      $moved = array_filter($report['checks'], fn($c, $at) => str_ends_with((string) $at, '.payee') && $c[0] === ResourceVerifier::FAIL, ARRAY_FILTER_USE_BOTH);
      if ($product && $moved) {
        $this->note('wallet_changed', (int) $product->id(), [
          'url' => $r['url'],
          'old' => self::payees($product),
          'new' => array_values(array_unique(array_filter(array_column(array_filter($report['accepts'], 'is_array'), 'payTo')))),
          'time' => $this->time->getCurrentTime(),
        ]);
      }
      $reasons[self::reason($report['checks'])] = ($reasons[self::reason($report['checks'])] ?? 0) + 1;
      return 'rejected';
    }
    if ((int) ($report['activity'] ?? 0) < $this->minTransfers) {
      $reasons['no incoming USDC'] = ($reasons['no incoming USDC'] ?? 0) + 1;
      return 'inactive';
    }
    if (!$this->coherent($report, $product, $reasons)) {
      return 'inactive';
    }
    $seen[$r['key']] = TRUE;
    return $this->save($product, $r, $report['accepts'], $hash);
  }

  /**
   * Do payTo's incoming transfers look like real sales of this listing?
   *
   * With the detail the transfer index answered (per checked requirement,
   * each transfer's amount and day): with $priceMatch, at least one
   * transfer of exactly the price the listing had on its day (priceAt());
   * with $maxAgeDays, the newest of those (or of all, without $priceMatch)
   * inside the window. A check the index could not answer is not a failure
   * here (the count already proved activity), it is just not filtered.
   *
   * @param array $report
   *   The verifier's report (its `transfers` detail, keyed as `accepts`).
   * @param \Drupal\commerce_product\Entity\ProductInterface|null $product
   *   The listing, when it exists: its price history dates the transfers.
   * @param array $reasons
   *   The tally of the reasons (counted here).
   *
   * @return bool
   *   Whether every checked requirement with detail passes the filters.
   */
  private function coherent(array $report, ?ProductInterface $product, array &$reasons): bool {
    if (!$this->priceMatch && !$this->maxAgeDays) {
      return TRUE;
    }
    $transfers = $report['transfers'] ?? NULL;
    if (!$transfers) {
      return TRUE;
    }
    foreach ($report['accepts'] as $i => $req) {
      $detail = $transfers["accepts[$i]"] ?? NULL;
      if (!$detail) {
        continue;
      }
      $evidence = $detail['transfers'] ?? [];
      if ($this->priceMatch) {
        $evidence = array_filter($evidence, fn(array $t) => $t['amount'] !== NULL
          && in_array($t['amount'], $this->priceAt($product, $req, $t['time']), TRUE));
        if (!$evidence) {
          $reasons['no incoming transfer of the listing\'s price'] = ($reasons['no incoming transfer of the listing\'s price'] ?? 0) + 1;
          return FALSE;
        }
      }
      if ($this->maxAgeDays) {
        $times = array_filter(array_column($evidence, 'time'), 'is_int');
        $newest = $times ? max($times) : NULL;
        if ($newest !== NULL && $newest < $this->time->getRequestTime() - $this->maxAgeDays * 86400) {
          $reasons['no incoming transfer in the window'] = ($reasons['no incoming transfer in the window'] ?? 0) + 1;
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * The prices a requirement's payTo took at a time, in atomic units.
   *
   * A new listing has no history: its announced price. An existing one is
   * dated by its history (field_past_accepts, as ReviewVerifier::at() reads
   * it): a time before a change takes what was offered until that change;
   * after the last change, what the listing offers now, plus the price the
   * catalogue announces in this run (the seller may have changed it before
   * we noticed).
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface|null $product
   *   The listing, or NULL if it is new.
   * @param array $req
   *   The checked requirement (network, asset, payTo, amount).
   * @param int|null $time
   *   When the transfer happened (NULL: unknown, taken as now).
   *
   * @return int[]
   *   The prices of that payTo, on that network and asset, at that time.
   */
  private function priceAt(?ProductInterface $product, array $req, ?int $time): array {
    $network = (string) ($req['network'] ?? '');
    $evm = str_starts_with($network, 'eip155:');
    $key = fn(array $o) => $network === (string) ($o['network'] ?? '')
      && ($evm ? strtolower((string) ($o['asset'] ?? '')) === strtolower((string) ($req['asset'] ?? '')) : ($o['asset'] ?? NULL) === ($req['asset'] ?? NULL))
      && ($evm ? strtolower((string) ($o['payTo'] ?? '')) === strtolower((string) ($req['payTo'] ?? '')) : ($o['payTo'] ?? NULL) === ($req['payTo'] ?? NULL));
    $amounts = fn(array $offers) => array_values(array_unique(array_map(
      fn(array $o) => (int) ($o['amount'] ?? $o['maxAmountRequired'] ?? -1),
      array_filter($offers, $key),
    )));
    $announced = $amounts([$req]);
    if (!$product) {
      return $announced;
    }
    if ($time !== NULL) {
      $past = array_filter(ReviewVerifier::past($product), fn(array $o) => (int) ($o['until'] ?? 0) >= $time);
      if ($past) {
        $first = min(array_map(fn(array $o) => (int) $o['until'], $past));
        return $amounts(array_filter($past, fn(array $o) => (int) $o['until'] === $first));
      }
    }
    $now = json_decode((string) $product->get('field_accepts')->value, TRUE) ?: [];
    return array_values(array_unique([...$amounts(array_filter($now, 'is_array')), ...$announced]));
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
    $this->note('checked', (int) $product->id(), $this->time->getCurrentTime());
    $this->note('wallet_changed', (int) $product->id(), NULL);
    return $outcome;
  }

  /**
   * Whether a listing's last check is older than MAX_AGE (or was never noted).
   */
  private function stale(ProductInterface $product): bool {
    $checked = $this->state->get("ombu.catalog.checked.$this->source", [])[(int) $product->id()] ?? 0;
    return $this->time->getCurrentTime() - (int) $checked > self::MAX_AGE;
  }

  /**
   * Notes something about a listing in the state, by its id; NULL forgets it.
   *
   * The lists are `checked` (when it was last asked) and `wallet_changed`
   * (refused because it pays a wallet it never declared).
   */
  private function note(string $list, int $id, mixed $value): void {
    $key = "ombu.catalog.$list.$this->source";
    $all = $this->state->get($key, []);
    if ($value === NULL) {
      if (!isset($all[$id])) {
        return;
      }
      unset($all[$id]);
    }
    else {
      $all[$id] = $value;
    }
    $this->state->set($key, $all);
  }

  /**
   * The listings refused because their seller now names another wallet.
   *
   * @return array<int, array{url: string, old: string[], new: string[], time: int}>
   *   By listing id.
   */
  public function walletChanges(): array {
    return $this->state->get("ombu.catalog.wallet_changed.$this->source", []);
  }

  /**
   * Accepts the wallet a listing's seller answers with now, in place of the
   * one it declared. For staff, after looking: a changed wallet is also what
   * an attack in the middle looks like.
   *
   * The listing is the same one (its catalogue, URL and method name it, never
   * its wallet): its id, SKU and reviews stay. Every other check runs again
   * (freeze list, sanctions, activity of the new wallet); only a pass
   * replaces the wallet. It stays retired until the next run of the import
   * compares it with the catalogue.
   *
   * @return array
   *   The verifier's report; its verdict says whether the wallet was taken.
   */
  public function acceptWallet(ProductInterface $product): array {
    if ($product->get('field_source')->value !== $this->source) {
      throw new \InvalidArgumentException("Listing {$product->id()} is not from $this->source.");
    }
    $report = $this->verifier->verify((string) $product->get('field_endpoint')->value, (string) $product->get('field_http_method')->value, [], ResourceVerifierFactory::assets(), []);
    $accepts = array_values(array_filter($report['accepts'] ?? [], 'is_array'));
    if ($report['verdict'] !== ResourceVerifier::PASS || !$accepts) {
      return $report;
    }
    if ((int) ($report['activity'] ?? 0) < $this->minTransfers) {
      return ['verdict' => ResourceVerifier::FAIL, 'checks' => $report['checks'] + ['activity' => [ResourceVerifier::FAIL, 'no incoming USDC']]] + $report;
    }
    usort($accepts, fn($a, $b) => (int) ($a['amount'] ?? $a['maxAmountRequired']) <=> (int) ($b['amount'] ?? $b['maxAmountRequired']));
    $product->set('field_pay_to', $accepts[0]['payTo']);
    $product->set('field_accepts', json_encode($accepts, JSON_UNESCAPED_SLASHES));
    // No hash: the next run asks it again, against the wallet just taken.
    $product->set('field_source_hash', NULL);
    $product->save();
    $this->note('wallet_changed', (int) $product->id(), NULL);
    $this->loggerFactory->get('ombu')->notice('Listing @id (@url): new wallet @wallet accepted by staff.', [
      '@id' => $product->id(),
      '@url' => $product->get('field_endpoint')->value,
      '@wallet' => $accepts[0]['payTo'],
    ]);
    return $report;
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

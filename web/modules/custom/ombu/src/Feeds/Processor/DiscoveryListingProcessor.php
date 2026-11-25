<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Processor;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Item\ItemInterface;
use Drupal\ombu\Catalog\CatalogRun;
use Drupal\ombu\ResourceVerifier;
use Drupal\ombu\ResourceVerifierFactory;
use Drupal\ombu\ReviewVerifier;

/**
 * Lists what Ombú verified itself from a Bazaar or PayAI catalogue.
 *
 * A catalogue says what a seller claims; a resource becomes a listing only
 * when Ombú has checked it (ResourceVerifier): the live 402 answers with the
 * same network, asset, payTo and amount the catalogue lists, payTo is not
 * frozen by the token nor on OFAC's list, and payTo has received USDC on
 * chain. Whatever cannot be asked is not imported and, if it was imported
 * before, not retired either: only a "no" retires (a failed check, no
 * activity on chain, or the catalogue no longer lists it).
 *
 * Verifying costs a request to the seller and to a node, so a run verifies
 * at most `budget` resources that are new or changed; the rest wait for the
 * next run (`deferred`). A listing whose catalogue entry did not change is
 * not asked again until its check is older than MAX_AGE.
 *
 * The import can be narrowed: `only_tags` keeps only resources carrying one
 * of the tags named (the rest are `skipped`, an existing listing of them
 * stays), and the coherence filters (`price_match`, `max_age_days`) demand
 * that payTo's incoming transfers look like real sales of this listing.
 *
 * A listing pays only the wallets it declared when it came in: an entry
 * that names another one is refused, the listing retired and noted for
 * staff (`ombu.catalog.wallet_changed.{source}`), who may accept the new
 * wallet (acceptWallet()).
 *
 * This is DiscoveryCatalogImporter's handle() and what it calls, as a Feeds
 * processor; the run (pages, budget, retirement) is the base class's and
 * Feeds'. The hash of an entry is the importer's, so a listing it wrote is
 * `unchanged` here.
 *
 * @FeedsProcessor(
 *   id = "ombu_discovery_listing",
 *   title = @Translation("Verified x402 listing"),
 *   description = @Translation("Lists the Bazaar or PayAI resources Ombú verified itself."),
 * )
 */
final class DiscoveryListingProcessor extends ListingProcessorBase {

  /**
   * Bump to refresh every listing once, when the import learns a new field.
   *
   * The importer's, so both hash an entry the same way.
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
   * The verifier of this request; built when a run starts.
   */
  protected ?ResourceVerifier $verifier = NULL;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'source' => '',
      'budget' => 200,
      'min_transfers' => 1,
      'only_tags' => [],
      'price_match' => FALSE,
      'max_age_days' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function outcomes(): array {
    return [
      'created', 'updated', 'unchanged', 'exists', 'blocked', 'unsupported',
      'rejected', 'inactive', 'unverified', 'deferred', 'skipped', 'retired',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The verifier is built now, so a missing node key stops the run before
   * the first page is read, as the importer's command did.
   */
  public function begin(FeedInterface $feed, CatalogRun $run): void {
    parent::begin($feed, $run);
    try {
      $this->verifier = $this->container->get('ombu.resource_verifier');
    }
    catch (\Throwable $e) {
      $run->fail($e->getMessage());
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
  }

  /**
   * The verifier; in a later request of a batch, built again.
   */
  private function verifier(): ResourceVerifier {
    return $this->verifier ??= $this->container->get('ombu.resource_verifier');
  }

  /**
   * {@inheritdoc}
   */
  protected function handle(FeedInterface $feed, ItemInterface $item, CatalogRun $run): array {
    $r = $item->toArray();
    $onlyTags = array_values(array_filter((array) $this->option($run, 'only_tags'), 'is_string'));
    if ($onlyTags && !array_intersect($onlyTags, (array) $r['tags'])) {
      // The granularity of what comes in: not this run's business. A listing
      // it already has stays (not retired), like anything the verifier was
      // not asked about.
      $mine = $this->listing($r['url'], $r['method'], $this->source());
      return ['skipped', $mine ? (int) $mine->id() : NULL];
    }
    if ($this->blocked($r['key'])) {
      return ['blocked', NULL];
    }
    $assets = ResourceVerifierFactory::assets();
    $pays = fn($a) => $a['scheme'] === 'exact'
      && in_array(strtolower($a['network'] . '|' . ($a['asset'] ?? '')), $assets, TRUE);
    if (!array_filter($r['accepts'], $pays)) {
      return ['unsupported', NULL];
    }
    $product = $this->listing($r['url'], $r['method']);
    if ($product && $product->get('field_source')->value !== $this->source()) {
      return ['exists', NULL];
    }
    // Whatever happens next, an existing listing stays unless a check says no.
    $keep = $product ? (int) $product->id() : NULL;
    $hash = hash('sha256', json_encode([self::FORMAT, $r['hash']]));
    if ($product && $product->get('field_source_hash')->value === $hash && !$product->get('field_retired')->value && !$this->stale($product)) {
      return ['unchanged', $keep];
    }
    $budget = (int) $this->option($run, 'budget');
    if ($budget && $run->verified >= $budget) {
      return ['deferred', $keep];
    }
    $run->verified++;
    try {
      $report = $this->verifier()->verify($r['url'], $r['method'], $r['accepts'], $assets, $product ? self::payees($product) : []);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ombu')->warning('Verifying @url: @m', ['@url' => $r['url'], '@m' => $e->getMessage()]);
      return ['unverified', $keep];
    }
    if ($report['verdict'] === ResourceVerifier::UNCHECKED) {
      return ['unverified', $keep];
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
      $run->reason(self::reason($report['checks']));
      return ['rejected', NULL];
    }
    if ((int) ($report['activity'] ?? 0) < (int) $this->option($run, 'min_transfers')) {
      $run->reason('no incoming USDC');
      return ['inactive', NULL];
    }
    if (!$this->coherent($report, $product, $run)) {
      return ['inactive', NULL];
    }
    $saved = $this->save($feed, $item, $product, $r, $report['accepts'], $hash);
    return [$saved, $product ? (int) $product->id() : NULL];
  }

  /**
   * Do payTo's incoming transfers look like real sales of this listing?
   *
   * With the detail the transfer index answered (per checked requirement,
   * each transfer's amount and day): with `price_match`, at least one
   * transfer of exactly the price the listing had on its day (priceAt());
   * with `max_age_days`, the newest of those (or of all, without
   * `price_match`) inside the window. A check the index could not answer is
   * not a failure here (the count already proved activity).
   */
  private function coherent(array $report, ?ProductInterface $product, CatalogRun $run): bool {
    $priceMatch = (bool) $this->option($run, 'price_match');
    $maxAgeDays = (int) $this->option($run, 'max_age_days');
    if (!$priceMatch && !$maxAgeDays) {
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
      if ($priceMatch) {
        $evidence = array_filter($evidence, fn(array $t) => $t['amount'] !== NULL
          && in_array($t['amount'], $this->priceAt($product, $req, $t['time']), TRUE));
        if (!$evidence) {
          $run->reason('no incoming transfer of the listing\'s price');
          return FALSE;
        }
      }
      if ($maxAgeDays) {
        $times = array_filter(array_column($evidence, 'time'), 'is_int');
        $newest = $times ? max($times) : NULL;
        if ($newest !== NULL && $newest < $this->time->getRequestTime() - $maxAgeDays * 86400) {
          $run->reason('no incoming transfer in the window');
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
   * dated by its history (field_past_accepts, as ReviewVerifier::past() reads
   * it): a time before a change takes what was offered until that change;
   * after the last change, what the listing offers now, plus the price the
   * catalogue announces in this run.
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
   * @return string
   *   `created` or `updated`.
   */
  private function save(FeedInterface $feed, ItemInterface $item, ?ProductInterface $product, array $r, array $accepts, string $hash): string {
    // The cheapest way to pay. Every verified asset is USDC (6 decimals).
    usort($accepts, fn($a, $b) => (int) ($a['amount'] ?? $a['maxAmountRequired']) <=> (int) ($b['amount'] ?? $b['maxAmountRequired']));
    $best = $accepts[0];
    $price = new Price(Calculator::divide((string) ($best['amount'] ?? $best['maxAmountRequired']), '1000000', 6), 'USD');
    $source = $this->source();
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
      'field_source' => $source,
      'field_markets' => [$source],
      'field_verified' => FALSE,
      'field_source_updated' => $r['updated'],
      'field_tags' => array_map(fn($t) => $this->term('listing_tag', $t), $r['tags']),
      'status' => TRUE,
    ];
    return $this->write($feed, $item, $product, $values, $source . '-' . substr(hash('sha256', $r['key']), 0, 16), $price);
  }

  /**
   * {@inheritdoc}
   *
   * The importer took the first variation, whatever its status.
   */
  protected function variationOf(ProductInterface $product): ?ProductVariationInterface {
    $variation = $product->get('variations')->entity;
    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function written(ProductInterface $product): void {
    $this->note('checked', (int) $product->id(), $this->time->getCurrentTime());
    $this->note('wallet_changed', (int) $product->id(), NULL);
  }

  /**
   * Whether a listing's last check is older than MAX_AGE (or was never noted).
   */
  private function stale(ProductInterface $product): bool {
    $checked = $this->drupalState->get("ombu.catalog.checked.{$this->source()}", [])[(int) $product->id()] ?? 0;
    return $this->time->getCurrentTime() - (int) $checked > self::MAX_AGE;
  }

  /**
   * The listings refused because their seller now names another wallet.
   *
   * @return array<int, array{url: string, old: string[], new: string[], time: int}>
   *   By listing id.
   */
  public function walletChanges(): array {
    return $this->drupalState->get("ombu.catalog.wallet_changed.{$this->source()}", []);
  }

  /**
   * Accepts the wallet a listing's seller answers with now.
   *
   * For staff, after looking: a changed wallet is also what an attack in the
   * middle looks like. The listing is the same one (its catalogue, URL and
   * method name it, never its wallet): its id, SKU and reviews stay. Every
   * other check runs again (freeze list, sanctions, activity of the new
   * wallet); only a pass replaces the wallet. It stays retired until the
   * next run compares it with the catalogue.
   *
   * @return array
   *   The verifier's report; its verdict says whether the wallet was taken.
   */
  public function acceptWallet(ProductInterface $product): array {
    if ($product->get('field_source')->value !== $this->source()) {
      throw new \InvalidArgumentException("Listing {$product->id()} is not from {$this->source()}.");
    }
    $report = $this->verifier()->verify((string) $product->get('field_endpoint')->value, (string) $product->get('field_http_method')->value, [], ResourceVerifierFactory::assets(), []);
    $accepts = array_values(array_filter($report['accepts'] ?? [], 'is_array'));
    if ($report['verdict'] !== ResourceVerifier::PASS || !$accepts) {
      return $report;
    }
    if ((int) ($report['activity'] ?? 0) < (int) $this->configuration['min_transfers']) {
      $checks = $report['checks'] + ['activity' => [ResourceVerifier::FAIL, 'no incoming USDC']];
      return ['verdict' => ResourceVerifier::FAIL, 'checks' => $checks] + $report;
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
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['budget'] = [
      '#type' => 'number',
      '#title' => $this->t('Resources to verify per run'),
      '#description' => $this->t('Each costs a request to the seller and to a node. 0: no limit.'),
      '#min' => 0,
      '#default_value' => $this->configuration['budget'],
    ];
    $form['min_transfers'] = [
      '#type' => 'number',
      '#title' => $this->t('Incoming USDC transfers payTo must have received'),
      '#min' => 0,
      '#default_value' => $this->configuration['min_transfers'],
    ];
    $form['only_tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Only resources with one of these tags'),
      '#description' => $this->t('Comma-separated. Empty: every resource.'),
      '#default_value' => implode(', ', $this->configuration['only_tags']),
    ];
    $form['price_match'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("payTo must have received a transfer of exactly the listing's price on that day"),
      '#default_value' => $this->configuration['price_match'],
    ];
    $form['max_age_days'] = [
      '#type' => 'number',
      '#title' => $this->t('The newest incoming transfer no older than this many days'),
      '#description' => $this->t('0: any age.'),
      '#min' => 0,
      '#default_value' => $this->configuration['max_age_days'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration([
      'source' => (string) $form_state->getValue('source'),
      'budget' => (int) $form_state->getValue('budget'),
      'min_transfers' => (int) $form_state->getValue('min_transfers'),
      'only_tags' => array_values(array_filter(array_map('trim', explode(',', (string) $form_state->getValue('only_tags'))))),
      'price_match' => (bool) $form_state->getValue('price_match'),
      'max_age_days' => (int) $form_state->getValue('max_age_days'),
    ]);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Processor;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\StoreStorageInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\State\StateInterface as DrupalState;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Item\ItemInterface;
use Drupal\feeds\Feeds\Processor\ProcessorBase;
use Drupal\feeds\Feeds\State\CleanStateInterface;
use Drupal\feeds\Plugin\Type\CleanableInterface;
use Drupal\feeds\StateInterface;
use Drupal\feeds\StateType;
use Drupal\ombu\Catalog\CatalogFeeds;
use Drupal\ombu\Catalog\CatalogRun;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Writes one catalogue's resources as listings, and retires what it drops.
 *
 * Not Feeds' entity processor: a listing is not mapped field by field from
 * the catalogue. What a seller claims is checked first (Discovery) or
 * compared with what Ombú already holds (Circle), and a listing is written
 * from that, with its price on a Commerce variation. So the processor holds
 * the rules, and Feeds holds the run: the paging, the batch or the cron
 * queue, the lock, the clean stage that retires, the events.
 *
 * What every catalogue shares lives here:
 * - One source per processor (`source`): it creates, updates and retires
 *   only the listings whose `field_source` is its own.
 * - The clean list is every listing of the source not retired yet; a
 *   listing the run keeps is taken off it, and the clean stage retires the
 *   rest: still published and open to reviews, never for sale, never
 *   deleted.
 * - Only a whole run retires (CatalogRun::whole()), and the guards: a
 *   catalogue that reads nothing, or less than half of what the last whole
 *   run read, retires nothing and says so (`retire_skipped`). The time of
 *   the last whole run is `ombu.{source}_last`, what it read
 *   `ombu.{source}_read`.
 * - A listing staff delete stays deleted (`ombu.{source}_blocked`, written
 *   by ListingHooks on delete).
 * - Each listing saved goes through Feeds' entity events (prevalidate,
 *   presave, postsave) with the catalogue item, so other code can add to it.
 * - The counts of the run are the importers' (`created`, `updated`, ...),
 *   logged, kept as the source's last report, and given to Feeds' own
 *   messages.
 */
abstract class ListingProcessorBase extends ProcessorBase implements CleanableInterface, ContainerFactoryPluginInterface, PluginFormInterface {

  /**
   * Terms found or created in this request, by vocabulary and name.
   *
   * @var array<string, array<string, int>>
   */
  protected array $terms = [];

  /**
   * Builds the processor.
   *
   * The services are not readonly: Feeds serializes its plugins between the
   * steps of a batch, and Drupal's serialization sets them back.
   */
  final public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DrupalState $drupalState,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected LockBackendInterface $lock,
    protected KeyValueFactoryInterface $keyValue,
    protected ContainerInterface $container,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('datetime.time'),
      $container->get('logger.factory'),
      $container->get('lock'),
      $container->get('keyvalue'),
      $container,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['source' => ''];
  }

  /**
   * The value of `field_source` (and the market) of the listings it writes.
   */
  public function source(): string {
    return (string) $this->configuration['source'];
  }

  /**
   * The outcomes this catalogue counts, in the order they are reported.
   *
   * @return string[]
   *   Keys of the report, each at least 0.
   */
  abstract protected function outcomes(): array;

  /**
   * One resource: what happened to it, and the listing it keeps.
   *
   * @return array{0: string, 1: int|null}
   *   The outcome (a key of the counts) and, when an existing listing of
   *   this source stays (not retired by this run), its id.
   */
  abstract protected function handle(FeedInterface $feed, ItemInterface $item, CatalogRun $run): array;

  /**
   * Whether the report tallies the reasons for rejections.
   */
  protected function withReasons(): bool {
    return TRUE;
  }

  /**
   * After a whole run, before its time is recorded (Circle: markets, logos).
   */
  protected function afterWholeRun(FeedInterface $feed, CatalogRun $run): void {}

  /**
   * Starts a run: its options, one run per catalogue, the keys read.
   *
   * Called by CatalogFeedSubscriber when Feeds starts fetching the first
   * page, whoever started the import (drush, the feed's page, cron).
   *
   * @throws \RuntimeException
   *   When another import of the same source is running: two at once both
   *   miss each other's new listings and create duplicates.
   */
  public function begin(FeedInterface $feed, CatalogRun $run): void {
    $run->started = TRUE;
    $store = $this->keyValue->get(CatalogRun::STORE);
    $run->options = (array) $store->get('options.' . $feed->id(), []);
    $store->delete('options.' . $feed->id());
    $store->delete('done.' . $feed->id());
    // Only a complete one knows what disappeared: a run with max never
    // retires, even when the catalogue is shorter than max.
    $run->partial = (int) ($run->options['max'] ?? 0) > 0;
    $source = $this->source();
    // The importer of this source (CatalogImporterInterface) holds this lock
    // while it runs; Feeds locks each feed, and a source could have two.
    $busy = !$this->lock->lockMayBeAvailable("ombu.{$source}_import");
    foreach (CatalogFeeds::feedsOf($source) as $other) {
      $busy = $busy || ($other->id() !== $feed->id() && $other->isLocked());
    }
    if ($busy) {
      $message = "A $source catalogue import is already running.";
      $run->fail($message);
      throw new \RuntimeException($message);
    }
  }

  /**
   * The value of an option of this run, or of the processor's configuration.
   */
  protected function option(CatalogRun $run, string $name): mixed {
    return $run->options[$name] ?? $this->configuration[$name] ?? NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Called after each page is parsed. The first time it fills the clean list
   * with this source's listings that are not retired. After the last page it
   * asks the guards, and a run they stop gets an empty clean list: the clean
   * stage has nothing to retire.
   */
  public function initialize(FeedInterface $feed) {
    $clean = $feed->getState(StateInterface::CLEAN);
    if (!$clean instanceof CleanStateInterface) {
      return;
    }
    if (!$clean->initiated()) {
      $clean->setEntityTypeId('commerce_product');
      $clean->setList($this->activeIds());
      $clean->total = $clean->count();
      $clean->progress($clean->total, 0);
    }
    $run = CatalogRun::of($feed);
    if ($run->retireAllowed !== NULL || $feed->progressFetching() !== StateInterface::BATCH_COMPLETE) {
      return;
    }
    $run->retireAllowed = $this->guardsAgree($run);
    if (!$run->retireAllowed) {
      $clean->setList([]);
      $clean->total = 0;
      $clean->progress(0, 0);
    }
  }

  /**
   * Whether a run that read to its end may retire what it did not keep.
   *
   * A catalogue that suddenly lists nothing, or far less than last time, is
   * more likely broken than emptied: keep what we have and say so.
   */
  private function guardsAgree(CatalogRun $run): bool {
    if ($run->partial || $run->error !== NULL) {
      return FALSE;
    }
    $lastRead = (int) $this->drupalState->get("ombu.{$this->source()}_read", 0);
    if ($run->read === 0 || ($lastRead && $run->read < $lastRead / 2)) {
      $run->retireSkipped = "read {$run->read}, the run before read $lastRead";
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Ids of this source's listings that are not retired.
   *
   * @return int[]
   *   Listing ids.
   */
  protected function activeIds(): array {
    $query = $this->products()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_source', $this->source());
    return array_map('intval', array_values($query->condition($query->orConditionGroup()
      ->notExists('field_retired')
      ->condition('field_retired', 0))
      ->execute()));
  }

  /**
   * {@inheritdoc}
   */
  public function process(FeedInterface $feed, ItemInterface $item, StateInterface $state) {
    $run = CatalogRun::of($feed);
    try {
      [$outcome, $keep] = $this->handle($feed, $item, $run);
    }
    catch (\Throwable $e) {
      // Not this resource's fault alone, maybe: the run goes on, but a run
      // with an error retires nothing.
      $run->fail($e->getMessage());
      $run->count('failed');
      $this->loggerFactory->get('ombu')->error('@source catalogue feed, @key: @m', [
        '@source' => $this->source(),
        '@key' => (string) $item->get('key'),
        '@m' => $e->getMessage(),
      ]);
      $state->report(StateType::FAIL, $e->getMessage(), ['feed' => $feed, 'item' => $item]);
      return;
    }
    finally {
      $run->handled++;
    }
    $run->count($outcome);
    if ($keep) {
      $clean = $feed->getState(StateInterface::CLEAN);
      if ($clean instanceof CleanStateInterface) {
        $clean->removeItem($keep);
      }
    }
    $code = match ($outcome) {
      'created' => StateType::CREATE,
      'updated' => StateType::UPDATE,
      default => StateType::SKIP,
    };
    $state->report($code, $outcome, ['feed' => $feed, 'item' => $item]);
  }

  /**
   * {@inheritdoc}
   *
   * Retires a listing the run did not keep: still published and open to
   * reviews, never for sale, never deleted. Asks the run first, so nothing
   * is retired by a run that is not whole or that the guards stopped.
   */
  public function clean(FeedInterface $feed, EntityInterface $entity, CleanStateInterface $state) {
    $run = CatalogRun::of($feed);
    if ($run->mayRetire()
      && $entity instanceof ProductInterface
      && $entity->bundle() === 'listing'
      && $entity->get('field_source')->value === $this->source()
      && !$entity->get('field_retired')->value) {
      $entity->set('field_retired', TRUE)->save();
      $run->count('retired');
      $state->report(StateType::CLEAN, 'Retired: the catalogue no longer lists it.', [
        'feed' => $feed,
        'entity' => $entity,
      ]);
    }
    $state->progress($state->total, $state->cleaned);
  }

  /**
   * {@inheritdoc}
   */
  public function postProcess(FeedInterface $feed, StateInterface $state) {
    $run = CatalogRun::of($feed);
    $source = $this->source();
    if ($run->whole()) {
      if ($run->retireAllowed) {
        $this->drupalState->set("ombu.{$source}_read", $run->read);
      }
      $this->afterWholeRun($feed, $run);
      $this->drupalState->set("ombu.{$source}_last", $this->time->getRequestTime());
    }
    $report = $run->summary($this->outcomes(), $this->withReasons());
    $store = $this->keyValue->get(CatalogRun::STORE);
    $store->set("report.$source", [
      'time' => $this->time->getCurrentTime(),
      'whole' => $run->whole(),
      'counts' => $report,
    ]);
    $store->delete('done.' . $feed->id());
    $this->loggerFactory->get('ombu')->info('@source catalogue feed: @c', [
      '@source' => $source,
      '@c' => json_encode($report),
    ]);
    parent::postProcess($feed, $state);
  }

  /**
   * The listing of a resource, from any source, by its URL and method.
   */
  protected function listing(string $url, string $method, ?string $source = NULL): ?ProductInterface {
    $query = $this->products()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_endpoint', $url)
      ->condition('field_http_method', $method);
    if ($source !== NULL) {
      $query->condition('field_source', $source);
    }
    $ids = $query->range(0, 1)->execute();
    $product = $ids ? $this->products()->load(reset($ids)) : NULL;
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * Whether staff took this resource down (its listing was deleted).
   */
  protected function blocked(string $key): bool {
    return isset($this->drupalState->get("ombu.{$this->source()}_blocked", [])[$key]);
  }

  /**
   * Writes the values on a new or existing listing and its variation.
   *
   * Feeds' entity events run on it with the item, as they do for any
   * entity Feeds saves: prevalidate and presave before, postsave after.
   *
   * @return string
   *   `created` or `updated`.
   */
  protected function write(FeedInterface $feed, ItemInterface $item, ?ProductInterface $product, array $values, string $sku, mixed $price): string {
    $outcome = $product ? 'updated' : 'created';
    if (!$product) {
      $stores = $this->entityTypeManager->getStorage('commerce_store');
      $product = $this->products()->create([
        'type' => 'listing',
        'stores' => $stores instanceof StoreStorageInterface ? [$stores->loadDefault()] : [],
      ]);
    }
    if (!$product instanceof ProductInterface) {
      throw new \LogicException('A listing is not a Commerce product.');
    }
    foreach ($values as $field => $value) {
      $product->set($field, $value);
    }
    $variation = $this->variationOf($product) ?? $this->entityTypeManager->getStorage('commerce_product_variation')->create(['type' => 'listing']);
    if (!$variation instanceof ProductVariationInterface) {
      throw new \LogicException('A listing variation is not a Commerce variation.');
    }
    $variation->setSku($sku);
    $variation->setPrice($price);
    $variation->save();
    if (!$product->hasVariation($variation)) {
      $product->addVariation($variation);
    }
    $feed->dispatchEntityEvent('feeds.process_entity_prevalidate', $product, $item);
    $feed->dispatchEntityEvent('feeds.process_entity_presave', $product, $item);
    $product->save();
    $feed->dispatchEntityEvent('feeds.process_entity_postsave', $product, $item);
    $this->written($product);
    return $outcome;
  }

  /**
   * The variation that carries a listing's price, if it has one.
   */
  protected function variationOf(ProductInterface $product): ?ProductVariationInterface {
    return $product->getDefaultVariation();
  }

  /**
   * After a listing is saved (Discovery notes when it was checked).
   */
  protected function written(ProductInterface $product): void {}

  /**
   * Loads or creates a term by name.
   */
  protected function term(string $vid, string $name): ?int {
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') {
      return NULL;
    }
    if (isset($this->terms[$vid][$name])) {
      return $this->terms[$vid][$name];
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $found = $terms->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $found ? reset($found) : $terms->create(['vid' => $vid, 'name' => $name]);
    if ($term->isNew()) {
      $term->save();
    }
    return $this->terms[$vid][$name] = (int) $term->id();
  }

  /**
   * The listings' storage.
   */
  protected function products(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('commerce_product');
  }

  /**
   * {@inheritdoc}
   */
  public function getItemCount(FeedInterface $feed) {
    return (int) $this->products()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_source', $this->source())
      ->count()->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getImportedItemIds(FeedInterface $feed) {
    return $this->products()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_source', $this->source())
      ->execute();
  }

  /**
   * {@inheritdoc}
   *
   * Listings never expire: a catalogue retires them by no longer listing them.
   */
  public function getExpiredIds(FeedInterface $feed, $time = NULL) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function expireItem(FeedInterface $feed, $item_id, StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function expiryTime() {
    return self::EXPIRE_NEVER;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemLabel() {
    return (string) $this->t('listing');
  }

  /**
   * {@inheritdoc}
   */
  public function getItemLabelPlural() {
    return (string) $this->t('listings');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['source'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Source'),
      '#description' => $this->t("The listings' source and market: they are this processor's alone."),
      '#default_value' => $this->configuration['source'],
      '#machine_name' => ['exists' => fn() => FALSE],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration(['source' => (string) $form_state->getValue('source')] + $this->configuration);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Catalog;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\FeedTypeInterface;
use Drupal\ombu\Feeds\Processor\ListingProcessorBase;

/**
 * The catalogue feeds: one Feeds feed type and one feed per catalogue.
 *
 * The feed types ship as configuration (config/optional), the feeds are
 * content and are created here the first time a catalogue is asked for.
 * A catalogue is named by its source (`bazaar`, `payai`, `circle`), the
 * `source` of its feed type's processor; a new catalogue is a new feed type
 * with an Ombú listing processor and a source of its own.
 *
 * import() is what drush runs: one whole import in this request, with the
 * run's options, and the run's counts back. Feeds' own ways of running a
 * feed (its page, its cron, `drush feeds:import`) run the same plugins and
 * leave the same report.
 */
final class CatalogFeeds {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessengerInterface $messenger,
    private readonly KeyValueFactoryInterface $keyValue,
  ) {}

  /**
   * The source a feed type writes, or its id if it is not a catalogue.
   */
  public static function sourceOf(FeedTypeInterface $type): string {
    $processor = $type->getProcessor();
    return $processor instanceof ListingProcessorBase ? $processor->source() : (string) $type->id();
  }

  /**
   * The feed types of every catalogue, by source.
   *
   * @return array<string, \Drupal\feeds\FeedTypeInterface>
   *   The first feed type found for each source.
   */
  public static function types(): array {
    $types = [];
    foreach (\Drupal::entityTypeManager()->getStorage('feeds_feed_type')->loadMultiple() as $type) {
      if ($type instanceof FeedTypeInterface && $type->getProcessor() instanceof ListingProcessorBase) {
        $types[self::sourceOf($type)] ??= $type;
      }
    }
    return $types;
  }

  /**
   * Every feed that writes a source's listings.
   *
   * @return \Drupal\feeds\FeedInterface[]
   *   Feeds of any feed type whose processor has this source.
   */
  public static function feedsOf(string $source): array {
    $storage = \Drupal::entityTypeManager()->getStorage('feeds_feed_type');
    $ids = [];
    foreach ($storage->loadMultiple() as $type) {
      if ($type instanceof FeedTypeInterface && $type->getProcessor() instanceof ListingProcessorBase && self::sourceOf($type) === $source) {
        $ids[] = $type->id();
      }
    }
    if (!$ids) {
      return [];
    }
    $feeds = \Drupal::entityTypeManager()->getStorage('feeds_feed');
    $loaded = $feeds->loadMultiple($feeds->getQuery()->accessCheck(FALSE)->condition('type', $ids, 'IN')->execute());
    return array_filter($loaded, fn($feed) => $feed instanceof FeedInterface);
  }

  /**
   * The feed of a catalogue, created if there is none yet.
   *
   * @throws \InvalidArgumentException
   *   When no feed type writes that source.
   */
  public function feed(string $source): FeedInterface {
    $type = self::types()[$source] ?? NULL;
    if (!$type) {
      throw new \InvalidArgumentException("No catalogue feed for $source (" . implode(', ', array_keys(self::types())) . ').');
    }
    $storage = $this->entityTypeManager->getStorage('feeds_feed');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $type->id())->sort('fid')->range(0, 1)->execute();
    $feed = $ids ? $storage->load(reset($ids)) : $storage->create([
      'type' => $type->id(),
      'title' => $type->label(),
      // What the fetcher reads is the feed type's address; the feed shows it.
      'source' => (string) ($type->getFetcher()->getConfiguration()['url'] ?? ''),
      // Imports run as nobody in particular, as cron does.
      'uid' => 0,
    ]);
    if (!$feed instanceof FeedInterface) {
      throw new \LogicException("The $source feed is not a feed.");
    }
    if ($feed->isNew()) {
      $feed->save();
    }
    return $feed;
  }

  /**
   * Imports a catalogue now, in this request, and returns the run's counts.
   *
   * @param string $source
   *   The catalogue: bazaar, payai, circle, or another feed type's source.
   * @param array $options
   *   For this run only: max (stop after this many, 0 = all; a partial run
   *   retires nothing), only_tags, price_match, max_age_days.
   *
   * @return array
   *   The counts of each outcome, as the importers returned them.
   *
   * @throws \RuntimeException
   *   When an import of the source is running, or the run failed (an empty
   *   or broken catalogue, a node without a key): the message says which.
   */
  public function import(string $source, array $options = []): array {
    // Fresh plugins: a feed type keeps its plugins, and a long process (or
    // a test) may have changed the services they were built with.
    $this->entityTypeManager->getStorage('feeds_feed_type')->resetCache();
    $this->entityTypeManager->getStorage('feeds_feed')->resetCache();
    $feed = $this->feed($source);
    if ($feed->isLocked()) {
      throw new \RuntimeException("A $source catalogue import is already running.");
    }
    $store = $this->keyValue->get(CatalogRun::STORE);
    $store->set('options.' . $feed->id(), array_filter($options, fn($v) => $v !== NULL && $v !== '' && $v !== []));
    $store->delete("report.$source");
    // Feeds turns a run's RuntimeException into an error message and ends
    // the run: those messages are this run's errors too.
    $errors = count($this->messenger->messagesByType(MessengerInterface::TYPE_ERROR));
    try {
      $feed->import();
    }
    finally {
      $store->delete('options.' . $feed->id());
    }
    $report = $store->get("report.$source");
    $error = $report['counts']['error'] ?? array_slice($this->messenger->messagesByType(MessengerInterface::TYPE_ERROR), $errors)[0] ?? NULL;
    if ($error !== NULL) {
      throw new \RuntimeException((string) $error);
    }
    if (!is_array($report)) {
      throw new \RuntimeException("The $source catalogue feed did not run.");
    }
    return $report['counts'];
  }

  /**
   * The last run of a catalogue: when, whether it was whole, its counts.
   */
  public function lastReport(string $source): ?array {
    $report = $this->keyValue->get(CatalogRun::STORE)->get("report.$source");
    return is_array($report) ? $report : NULL;
  }

}

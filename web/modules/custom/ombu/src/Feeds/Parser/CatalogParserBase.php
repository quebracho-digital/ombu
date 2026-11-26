<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Parser;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Item\DynamicItem;
use Drupal\feeds\Feeds\Parser\ParserBase;
use Drupal\feeds\Result\FetcherResultInterface;
use Drupal\feeds\Result\ParserResult;
use Drupal\feeds\StateInterface;
use Drupal\ombu\Catalog\CatalogRun;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns one page of a catalogue into items of one shape.
 *
 * What every catalogue shares: an entry that cannot be a listing is counted
 * and dropped here (it never reaches the processor), the same resource
 * listed twice in one run is read once (by its key, method and URL, across
 * pages), and a run with `max` stops after that many and tells the fetcher
 * not to ask for another page. What a catalogue's entry looks like is its
 * subclass's normalize().
 *
 * A resource listed twice with different wallets is a conflict, not a
 * duplicate: neither entry is a listing, and one marker item lets the
 * processor take down the listing the first of them may have written. A
 * catalogue that repeats an entry (a re-listing by an aggregator) says the
 * same wallets and is a duplicate as before.
 *
 * The keys already read are kept in the key-value store, not in the run's
 * state, and written once per page: Feeds saves the states after every item.
 */
abstract class CatalogParserBase extends ParserBase implements ContainerFactoryPluginInterface {

  /**
   * Builds the parser.
   *
   * Not readonly: Feeds serializes its plugins between the steps of a batch.
   */
  final public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected KeyValueFactoryInterface $keyValue,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('keyvalue'));
  }

  /**
   * A catalogue entry as one shape, or NULL when it cannot be a listing.
   *
   * @return array|null
   *   At least `key` (method and URL), `url` and `method`.
   */
  abstract public static function normalize(array $item): ?array;

  /**
   * The outcome an entry that cannot be a listing is counted as.
   */
  abstract protected function unusable(): string;

  /**
   * How many entries count towards `max` so far.
   */
  protected function counted(CatalogRun $run): int {
    return $run->read;
  }

  /**
   * The value an entry's key is deduplicated by: the wallets it pays.
   *
   * Two entries for one resource (same method and URL) with the same wallets
   * are one entry, however often a catalogue repeats them. Different wallets
   * are two claims on one resource: a conflict.
   *
   * @return string
   *   A short hash of the sorted, lowercased payTo addresses.
   */
  protected function identity(array $normal): string {
    $payTo = [];
    foreach ((array) ($normal['accepts'] ?? []) as $accept) {
      if (is_array($accept) && isset($accept['payTo']) && is_scalar($accept['payTo']) && (string) $accept['payTo'] !== '') {
        $payTo[] = strtolower((string) $accept['payTo']);
      }
    }
    $payTo = array_values(array_unique($payTo));
    sort($payTo);
    return substr(hash('sha256', implode('|', $payTo)), 0, 8);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(FeedInterface $feed, FetcherResultInterface $fetcher_result, StateInterface $state) {
    $run = CatalogRun::of($feed);
    $page = json_decode($fetcher_result->getRaw(), TRUE);
    $raw = is_array($page) ? ($page['items'] ?? []) : [];
    $items = is_array($raw) ? $raw : [];
    $store = $this->keyValue->get(CatalogRun::STORE);
    $doneKey = 'done.' . $feed->id();
    $done = $store->get($doneKey, []);
    $max = (int) ($run->options['max'] ?? 0);
    $result = new ParserResult();
    // The items of this page, added once it is read: a conflict found further
    // down the page still drops the entry before the processor sees it.
    $out = [];
    $at = [];
    foreach ($items as $entry) {
      $normal = NULL;
      if (is_array($entry)) {
        try {
          $normal = static::normalize($entry);
        }
        catch (\Throwable $e) {
          // A poisoned entry must not lock the feed: Feeds catches only
          // \Exception, so a TypeError would escape and leave the feed
          // locked. Count it as unusable and go on.
          \Drupal::logger('ombu')->warning('Catalogue entry skipped: @message', ['@message' => $e->getMessage()]);
        }
      }
      if ($normal === NULL) {
        $run->count($this->unusable());
      }
      else {
        // The same resource listed twice in one run is one resource, unless
        // the two entries name different wallets: then neither is listed.
        $id = substr(hash('sha256', $normal['key']), 0, 24);
        $identity = $this->identity($normal);
        $seen = $done[$id] ?? NULL;
        if (is_string($seen) && $seen !== $identity) {
          if (!empty($done["conflict.$id"])) {
            continue;
          }
          // One marker for the pair: the processor counts it, and takes down
          // the listing the first of them wrote in this run (on another
          // page); the first of this page is dropped before it is added.
          $done["conflict.$id"] = TRUE;
          // The entry never reaches the processor: it is not queued.
          unset($out[$at[$id] ?? -1], $at[$id]);
          $run->queued = max(0, $run->queued - 1);
          $out[] = [
            'key' => $normal['key'],
            'url' => $normal['url'],
            'method' => $normal['method'],
            'conflict' => TRUE,
          ];
        }
        elseif ($seen !== NULL) {
          continue;
        }
        else {
          $done[$id] = $identity;
          $run->read++;
          $run->queued++;
          $out[] = $normal;
          $at[$id] = array_key_last($out);
        }
      }
      if ($max && $this->counted($run) >= $max) {
        // Enough for this run: no more pages.
        $feed->getState(StateInterface::FETCH)->setCompleted();
        break;
      }
    }
    foreach ($out as $item) {
      $result->addItem((new DynamicItem())->fromArray($item));
    }
    $store->set($doneKey, $done);
    $state->setCompleted();
    return $result;
  }

}

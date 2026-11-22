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
   * {@inheritdoc}
   */
  public function parse(FeedInterface $feed, FetcherResultInterface $fetcher_result, StateInterface $state) {
    $run = CatalogRun::of($feed);
    $page = json_decode($fetcher_result->getRaw(), TRUE);
    $store = $this->keyValue->get(CatalogRun::STORE);
    $doneKey = 'done.' . $feed->id();
    $done = $store->get($doneKey, []);
    $max = (int) ($run->options['max'] ?? 0);
    $result = new ParserResult();
    foreach ($page['items'] ?? [] as $entry) {
      $normal = is_array($entry) ? static::normalize($entry) : NULL;
      if ($normal === NULL) {
        $run->count($this->unusable());
      }
      else {
        // The same resource listed twice in one run is one resource.
        $id = substr(hash('sha256', $normal['key']), 0, 24);
        if (isset($done[$id])) {
          continue;
        }
        $done[$id] = TRUE;
        $run->read++;
        $run->queued++;
        $result->addItem((new DynamicItem())->fromArray($normal));
      }
      if ($max && $this->counted($run) >= $max) {
        // Enough for this run: no more pages.
        $feed->getState(StateInterface::FETCH)->setCompleted();
        break;
      }
    }
    $store->set($doneKey, $done);
    $state->setCompleted();
    return $result;
  }

}

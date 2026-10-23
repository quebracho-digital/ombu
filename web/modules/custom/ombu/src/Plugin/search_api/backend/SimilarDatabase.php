<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\search_api\backend;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\ombu\Similar;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api_db\Plugin\search_api\backend\Database;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search API's database backend, plus "More like this" by Similar.
 *
 * Not a plugin of its own: SimilarBackendSubscriber puts this class behind
 * the "search_api_db" plugin, so servers, indexes and their data stay as
 * they are. A "More like this" query (Search API's contextual filter of
 * that name) is narrowed to Similar's ids and answered in Similar's order.
 * Another backend with its own "More like this" (Solr, Meilisearch) needs
 * none of this: a view built on the filter keeps working when the server
 * changes.
 */
class SimilarDatabase extends Database {

  /**
   * Scores the listings like the one asked about.
   */
  protected Similar $similar;

  /**
   * Lists the site's languages, for a query that names none.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $backend = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $backend->similar = $container->get('ombu.similar');
    $backend->languageManager = $container->get('language_manager');
    return $backend;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [...parent::getSupportedFeatures(), 'search_api_mlt'];
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $mlt = $query->getOption('search_api_mlt');
    if (!$mlt) {
      parent::search($query);
      return;
    }
    // Views passes the argument as given: a product id, or an item id.
    $id = (string) $mlt['id'];
    if (!ctype_digit($id)) {
      [, $raw] = Utility::splitCombinedId($id);
      $id = strtok((string) $raw, ':');
    }
    $scores = $this->similar->scores((int) $id);
    $rank = array_flip(array_keys($scores));
    $item_ids = [];
    foreach (array_keys($scores) as $other) {
      foreach ($query->getLanguages() ?? array_keys($this->languageManager->getLanguages()) as $langcode) {
        $item_ids[] = "entity:commerce_product/$other:$langcode";
      }
    }

    // Every candidate the other conditions let through, then Similar's
    // order and the page asked for.
    $offset = (int) $query->getOption('offset');
    $limit = $query->getOption('limit');
    $query->addCondition('search_api_id', $item_ids ?: ['none'], 'IN');
    $query->range(0, max(1, count($item_ids)));
    parent::search($query);
    $query->range($offset, $limit);

    $results = $query->getResults();
    $items = [];
    foreach ($results->getResultItems() as $item_id => $item) {
      [, $raw] = Utility::splitCombinedId($item_id);
      $other = (int) strtok((string) $raw, ':');
      $item->setScore($scores[$other] ?? 0);
      $items[$item_id] = [$rank[$other] ?? PHP_INT_MAX, $item];
    }
    uasort($items, fn ($a, $b) => $a[0] <=> $b[0]);
    $results->setResultItems(array_slice(array_map(fn ($pair) => $pair[1], $items), $offset, $limit, TRUE));
    $results->setResultCount(count($items));
  }

}

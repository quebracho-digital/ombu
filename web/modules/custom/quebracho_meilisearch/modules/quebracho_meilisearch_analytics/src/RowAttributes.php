<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch_analytics;

use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\quebracho_meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;

/**
 * Adds the click-tracking attributes to rows of Meilisearch search views.
 *
 * Supports the "Unformatted list" and "HTML list" styles.
 */
class RowAttributes {

  /**
   * Constructs a RowAttributes service.
   */
  public function __construct(protected KillSwitch $pageCacheKillSwitch) {}

  /**
   * Applies the attributes to the preprocess variables of a views style.
   */
  public function apply(array &$variables): void {
    $view = $variables['view'] ?? NULL;
    $query = $view?->query;
    if (!$query instanceof SearchApiQuery || empty($variables['rows'])) {
      return;
    }
    $index = $query->getIndex();
    if (!$index->hasValidServer() || $index->getServerInstance()->getBackendId() !== 'meilisearch') {
      return;
    }
    $query_uid = $query->getSearchApiResults()?->getExtraData('quebracho_meilisearch_query_uid');
    if (!is_string($query_uid)) {
      return;
    }
    $offset = (int) $query->getSearchApiQuery()->getOption('offset', 0);

    foreach (array_keys($variables['rows']) as $delta) {
      $item = $view->result[$delta]->_item ?? NULL;
      if (!$item || !isset($variables['rows'][$delta]['attributes'])) {
        continue;
      }
      $variables['rows'][$delta]['attributes']
        ->setAttribute('data-meilisearch-index', $index->id())
        ->setAttribute('data-meilisearch-query-uid', $query_uid)
        ->setAttribute('data-meilisearch-object-id', MeilisearchUtils::encodeDocumentId($item->getId()))
        ->setAttribute('data-meilisearch-position', (string) ($offset + $delta));
    }

    $variables['#attached']['library'][] = 'quebracho_meilisearch_analytics/click_tracking';
    // The query UID identifies this very search: a cached page would share
    // it between visitors.
    $variables['#cache']['max-age'] = 0;
    $this->pageCacheKillSwitch->trigger();
  }

}

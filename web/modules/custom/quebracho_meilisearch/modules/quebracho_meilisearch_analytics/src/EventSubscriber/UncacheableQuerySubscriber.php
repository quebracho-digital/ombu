<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch_analytics\EventSubscriber;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps Meilisearch searches out of the Search API results cache.
 *
 * Each search gets its own query UID for click attribution; a cached result
 * set would replay one UID for every visitor.
 */
class UncacheableQuerySubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute'];
  }

  /**
   * Marks queries on Meilisearch servers as uncacheable.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    $index = $query->getIndex();
    if ($query instanceof RefinableCacheableDependencyInterface
      && $index->hasValidServer()
      && $index->getServerInstance()->getBackendId() === 'meilisearch') {
      $query->mergeCacheMaxAge(0);
    }
  }

}

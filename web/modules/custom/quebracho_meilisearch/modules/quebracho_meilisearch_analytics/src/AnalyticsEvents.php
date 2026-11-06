<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch_analytics;

use Drupal\quebracho_meilisearch\Api\MeilisearchApiException;
use Drupal\quebracho_meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends click and conversion events to Meilisearch Cloud.
 *
 * Self-hosted Meilisearch has no events route: nothing is sent there.
 *
 * @see https://www.meilisearch.com/docs/capabilities/analytics/advanced/events_endpoint
 */
class AnalyticsEvents {

  /**
   * Constructs an AnalyticsEvents service.
   */
  public function __construct(
    protected UserIdResolver $userIds,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a click on a search result.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The searched index.
   * @param string $queryUid
   *   The UID of the search, from the "quebracho_meilisearch_query_uid" result data.
   * @param string $objectId
   *   The Meilisearch document ID of the clicked result.
   * @param int $position
   *   The 0-based position of the result in the whole result list.
   *
   * @return bool
   *   TRUE if the event was sent.
   */
  public function click(IndexInterface $index, string $queryUid, string $objectId, int $position): bool {
    return $this->send($index, [
      'eventType' => 'click',
      'eventName' => 'Search Result Clicked',
      'queryUid' => $queryUid,
      'objectId' => $objectId,
      'position' => $position,
    ]);
  }

  /**
   * Records a conversion, such as a purchase, following a search.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index holding the converted item.
   * @param string $objectId
   *   The Meilisearch document ID of the item
   *   (\Drupal\quebracho_meilisearch\Utility\MeilisearchUtils::encodeDocumentId()).
   * @param string|null $queryUid
   *   The UID of the search that led to the conversion, if known.
   * @param string $eventName
   *   A label for the conversion.
   *
   * @return bool
   *   TRUE if the event was sent.
   */
  public function conversion(IndexInterface $index, string $objectId, ?string $queryUid = NULL, string $eventName = 'Conversion'): bool {
    return $this->send($index, array_filter([
      'eventType' => 'conversion',
      'eventName' => $eventName,
      'queryUid' => $queryUid,
      'objectId' => $objectId,
    ], fn($value) => $value !== NULL));
  }

  /**
   * Sends an event through the index's server.
   */
  protected function send(IndexInterface $index, array $event): bool {
    $backend = $index->hasValidServer() ? $index->getServerInstance()->getBackend() : NULL;
    if (!$backend instanceof MeilisearchBackend || !$backend->getApi()->isCloud()) {
      return FALSE;
    }
    $event += [
      'indexUid' => $backend->getIndexUid($index),
      'userId' => $this->userIds->getUserId() ?? 'anonymous',
    ];
    try {
      $backend->getApi()->sendEvent($event);
      return TRUE;
    }
    catch (MeilisearchApiException $e) {
      $this->logger->warning('Meilisearch analytics event failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

}

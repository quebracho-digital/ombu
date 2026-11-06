<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Api;

/**
 * Talks to one Meilisearch instance.
 *
 * Every method throws \Drupal\quebracho_meilisearch\Api\MeilisearchApiException on any
 * failure, including network errors and timeouts.
 */
interface MeilisearchApiServiceInterface {

  /**
   * How long to wait for a task by default, in milliseconds.
   */
  public const TASK_TIMEOUT = 60000;

  /**
   * Returns the URL of the instance.
   */
  public function getUrl(): string;

  /**
   * Returns TRUE if the instance is hosted on Meilisearch Cloud.
   */
  public function isCloud(): bool;

  /**
   * Returns TRUE if the instance answers its health check.
   *
   * Never throws.
   */
  public function ping(): bool;

  /**
   * Returns the version information of the instance.
   */
  public function version(): array;

  /**
   * Returns TRUE if the index exists.
   */
  public function indexExists(string $uid): bool;

  /**
   * Returns the statistics of an index, including its number of documents.
   */
  public function indexStats(string $uid): array;

  /**
   * Creates an index. Returns the enqueued task.
   */
  public function createIndex(string $uid, string $primaryKey): array;

  /**
   * Deletes an index. Returns the enqueued task.
   */
  public function deleteIndex(string $uid): array;

  /**
   * Updates the settings of an index. Returns the enqueued task.
   */
  public function updateSettings(string $uid, array $settings): array;

  /**
   * Adds or replaces documents. Returns the enqueued task.
   */
  public function addDocuments(string $uid, array $documents, string $primaryKey): array;

  /**
   * Deletes documents by primary key. Returns the enqueued task.
   *
   * @param string $uid
   *   The index UID.
   * @param string[] $ids
   *   The primary key values.
   */
  public function deleteDocuments(string $uid, array $ids): array;

  /**
   * Deletes the documents matching a filter. Returns the enqueued task.
   */
  public function deleteDocumentsByFilter(string $uid, string $filter): array;

  /**
   * Deletes all documents of an index. Returns the enqueued task.
   */
  public function deleteAllDocuments(string $uid): array;

  /**
   * Searches an index and returns the raw Meilisearch response.
   */
  public function search(string $uid, string $query, array $params): array;

  /**
   * Runs several searches in one request.
   *
   * @param array[] $queries
   *   Search parameter arrays, each including "indexUid".
   *
   * @return array[]
   *   The raw responses, in the order of the queries.
   */
  public function multiSearch(array $queries): array;

  /**
   * Returns the documents most similar to one, by an embedder's vectors.
   *
   * @param string $uid
   *   The index UID.
   * @param array $params
   *   The parameters, including "id" and "embedder", as documented at
   *   https://www.meilisearch.com/docs/reference/api/similar.
   *
   * @return array
   *   The raw Meilisearch response.
   */
  public function similar(string $uid, array $params): array;

  /**
   * Sends an analytics event (Meilisearch Cloud only).
   *
   * @see https://www.meilisearch.com/docs/capabilities/analytics/advanced/events_endpoint
   */
  public function sendEvent(array $event): void;

  /**
   * Waits for a task to finish.
   *
   * @param int $taskUid
   *   The task UID.
   * @param int $timeoutMs
   *   How long to wait, in milliseconds.
   *
   * @return array
   *   The finished task.
   *
   * @throws \Drupal\quebracho_meilisearch\Api\MeilisearchApiException
   *   When the task failed or did not finish in time.
   */
  public function waitForTask(int $taskUid, int $timeoutMs = self::TASK_TIMEOUT): array;

}

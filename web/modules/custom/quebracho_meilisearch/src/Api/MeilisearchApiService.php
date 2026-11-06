<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\ExceptionInterface;
use Meilisearch\Exceptions\TimeOutException;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Wraps the Meilisearch PHP client for one instance.
 *
 * Create instances with \Drupal\quebracho_meilisearch\Api\MeilisearchApiFactory.
 */
class MeilisearchApiService implements MeilisearchApiServiceInterface {

  /**
   * Constructs a MeilisearchApiService.
   *
   * @param \Meilisearch\Client $client
   *   The Meilisearch client.
   * @param \GuzzleHttp\ClientInterface $http
   *   The HTTP client the Meilisearch client uses, for routes it lacks.
   * @param string $url
   *   The instance URL, without trailing slash.
   * @param string $apiKey
   *   The API key.
   */
  public function __construct(
    protected Client $client,
    protected ClientInterface $http,
    protected string $url,
    protected string $apiKey,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getUrl(): string {
    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function isCloud(): bool {
    return (bool) preg_match('/(?:^|\.)meilisearch\.io$/i', (string) parse_url($this->url, PHP_URL_HOST));
  }

  /**
   * {@inheritdoc}
   */
  public function ping(): bool {
    try {
      $this->client->health();
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function version(): array {
    return $this->call(fn() => $this->client->version());
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists(string $uid): bool {
    try {
      $this->call(fn() => $this->client->getRawIndex($uid));
      return TRUE;
    }
    catch (MeilisearchApiException $e) {
      if ($e->getErrorCode() === 'index_not_found') {
        return FALSE;
      }
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexStats(string $uid): array {
    return $this->call(fn() => $this->client->index($uid)->stats());
  }

  /**
   * {@inheritdoc}
   */
  public function createIndex(string $uid, string $primaryKey): array {
    return $this->call(fn() => $this->client->createIndex($uid, ['primaryKey' => $primaryKey]));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteIndex(string $uid): array {
    return $this->call(fn() => $this->client->deleteIndex($uid));
  }

  /**
   * {@inheritdoc}
   */
  public function updateSettings(string $uid, array $settings): array {
    return $this->call(fn() => $this->client->index($uid)->updateSettings($settings));
  }

  /**
   * {@inheritdoc}
   */
  public function addDocuments(string $uid, array $documents, string $primaryKey): array {
    return $this->call(fn() => $this->client->index($uid)->addDocuments($documents, $primaryKey));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDocuments(string $uid, array $ids): array {
    return $this->call(fn() => $this->client->index($uid)->deleteDocuments(array_values($ids)));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDocumentsByFilter(string $uid, string $filter): array {
    return $this->call(fn() => $this->client->index($uid)->deleteDocuments(['filter' => $filter]));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllDocuments(string $uid): array {
    return $this->call(fn() => $this->client->index($uid)->deleteAllDocuments());
  }

  /**
   * {@inheritdoc}
   */
  public function search(string $uid, string $query, array $params): array {
    return $this->call(fn() => $this->client->index($uid)->search($query, $params, ['raw' => TRUE]));
  }

  /**
   * {@inheritdoc}
   */
  public function multiSearch(array $queries): array {
    $response = $this->post('multi-search', ['queries' => array_values($queries)]);
    return $response['results'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function similar(string $uid, array $params): array {
    return $this->post('indexes/' . rawurlencode($uid) . '/similar', $params);
  }

  /**
   * {@inheritdoc}
   */
  public function sendEvent(array $event): void {
    $this->post('events', $event);
  }

  /**
   * {@inheritdoc}
   */
  public function waitForTask(int $taskUid, int $timeoutMs = self::TASK_TIMEOUT): array {
    try {
      $task = $this->client->waitForTask($taskUid, $timeoutMs, min(100, $timeoutMs));
    }
    catch (TimeOutException $e) {
      throw new MeilisearchApiException(sprintf('Meilisearch task %d did not finish within %d ms.', $taskUid, $timeoutMs), 'task_timeout', $e);
    }
    // The SDK only declares TimeOutException, but polling the task can fail
    // with API, network and transfer errors too.
    // @phpstan-ignore catch.neverThrown
    catch (ExceptionInterface $e) {
      throw $this->convert($e);
    }
    // @phpstan-ignore catch.neverThrown
    catch (ClientExceptionInterface $e) {
      throw $this->convert($e);
    }
    if (($task['status'] ?? NULL) === 'failed') {
      throw new MeilisearchApiException(
        sprintf('Meilisearch task %d failed: %s', $taskUid, $task['error']['message'] ?? 'unknown error'),
        $task['error']['code'] ?? NULL,
      );
    }
    return $task;
  }

  /**
   * Runs an SDK call, converting its exceptions.
   *
   * @param callable $callback
   *   The SDK call.
   *
   * @return mixed
   *   The result of the call.
   */
  protected function call(callable $callback): mixed {
    try {
      return $callback();
    }
    // The SDK only wraps connection errors; TLS and transfer errors (Guzzle
    // RequestException) come through as PSR-18 client exceptions.
    catch (ExceptionInterface | ClientExceptionInterface $e) {
      throw $this->convert($e);
    }
  }

  /**
   * Converts an SDK exception.
   */
  protected function convert(ExceptionInterface|ClientExceptionInterface $e): MeilisearchApiException {
    if ($e instanceof ApiException) {
      return new MeilisearchApiException($e->getMessage(), $e->errorCode, $e);
    }
    return new MeilisearchApiException($e->getMessage(), NULL, $e);
  }

  /**
   * Sends a JSON POST request for routes the SDK does not cover.
   */
  protected function post(string $path, array $body): array {
    $options = ['json' => $body, 'headers' => []];
    if ($this->apiKey !== '') {
      $options['headers']['Authorization'] = 'Bearer ' . $this->apiKey;
    }
    try {
      $response = $this->http->request('POST', $path, $options);
    }
    catch (RequestException $e) {
      $error = $e->hasResponse() ? json_decode((string) $e->getResponse()->getBody(), TRUE) : NULL;
      throw new MeilisearchApiException($error['message'] ?? $e->getMessage(), $error['code'] ?? NULL, $e);
    }
    catch (GuzzleException $e) {
      throw new MeilisearchApiException($e->getMessage(), NULL, $e);
    }
    $decoded = json_decode((string) $response->getBody(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}

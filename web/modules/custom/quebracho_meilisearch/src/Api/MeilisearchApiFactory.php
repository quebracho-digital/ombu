<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Api;

use Drupal\Core\Http\ClientFactory;
use Meilisearch\Client;

/**
 * Creates one Meilisearch API service per connection.
 *
 * Each Search API server owns its own instance, so servers pointing at
 * different Meilisearch projects never share credentials.
 */
class MeilisearchApiFactory {

  /**
   * The client agent reported to Meilisearch.
   */
  public const CLIENT_AGENT = 'Meilisearch Drupal (v1.0)';

  /**
   * Constructs a MeilisearchApiFactory.
   *
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   Drupal's HTTP client factory, so site proxy settings apply.
   */
  public function __construct(protected ClientFactory $httpClientFactory) {}

  /**
   * Creates an API service for one Meilisearch instance.
   *
   * @param string $url
   *   The Meilisearch URL, including scheme and port.
   * @param string $apiKey
   *   The API key, or an empty string when the instance has none.
   * @param array<string, string> $headers
   *   Extra HTTP headers sent with every request.
   */
  public function create(string $url, string $apiKey, array $headers = []): MeilisearchApiServiceInterface {
    $url = rtrim($url, '/');
    $http = $this->httpClientFactory->fromOptions([
      'base_uri' => $url . '/',
      'connect_timeout' => 5,
      'timeout' => 30,
      'headers' => $headers,
    ]);
    $client = new Client($url, $apiKey !== '' ? $apiKey : NULL, $http, NULL, [self::CLIENT_AGENT]);
    return new MeilisearchApiService($client, $http, $url, $apiKey);
  }

}

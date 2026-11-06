<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Api;

use Drupal\Core\Http\ClientFactory;
use Drupal\quebracho_meilisearch\Api\MeilisearchApiException;
use Drupal\quebracho_meilisearch\Api\MeilisearchApiFactory;
use Drupal\quebracho_meilisearch\Api\MeilisearchApiServiceInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\quebracho_meilisearch\Api\MeilisearchApiService
 */
class MeilisearchApiServiceTest extends TestCase {

  /**
   * Requests sent through the mocked HTTP client.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  protected array $history = [];

  /**
   * Builds an API service whose HTTP layer answers with the given responses.
   *
   * @param array $responses
   *   Responses or exceptions, in order.
   * @param array $headers
   *   Extra headers for every request.
   * @param string $url
   *   The Meilisearch URL.
   */
  protected function api(array $responses, array $headers = [], string $url = 'http://meili.test:7700'): MeilisearchApiServiceInterface {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return (new MeilisearchApiFactory(new ClientFactory($stack)))->create($url, 'secret', $headers);
  }

  /**
   * Returns a JSON response.
   */
  protected function json(array $body, int $status = 200): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * @covers ::waitForTask
   */
  public function testFailedTaskThrowsWithMeilisearchError(): void {
    $api = $this->api([
      $this->json([
        'uid' => 7,
        'status' => 'failed',
        'error' => ['message' => 'Document has invalid _geo', 'code' => 'invalid_document_geo_field'],
      ]),
    ]);
    try {
      $api->waitForTask(7);
      $this->fail('A failed task must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertStringContainsString('Document has invalid _geo', $e->getMessage());
      $this->assertSame('invalid_document_geo_field', $e->getErrorCode());
    }
  }

  /**
   * @covers ::waitForTask
   */
  public function testSucceededTaskIsReturned(): void {
    $api = $this->api([$this->json(['uid' => 7, 'status' => 'succeeded'])]);
    $this->assertSame('succeeded', $api->waitForTask(7)['status']);
  }

  /**
   * @covers ::waitForTask
   */
  public function testTaskTimeoutThrows(): void {
    $api = $this->api(array_fill(0, 20, $this->json(['uid' => 7, 'status' => 'processing'])));
    $this->expectException(MeilisearchApiException::class);
    $this->expectExceptionMessageMatches('/task 7/i');
    $api->waitForTask(7, 1);
  }

  /**
   * @covers ::search
   */
  public function testNetworkErrorThrows(): void {
    $api = $this->api([new ConnectException('Connection refused', new Request('POST', '/'))]);
    $this->expectException(MeilisearchApiException::class);
    $api->search('idx', 'foo', []);
  }

  /**
   * TLS and transfer errors are not "network" errors for the SDK.
   *
   * @covers ::search
   * @covers ::waitForTask
   */
  public function testTransferErrorsThrow(): void {
    $error = new RequestException('cURL error 60: SSL certificate problem', new Request('POST', '/'));
    try {
      $this->api([$error])->search('idx', 'foo', []);
      $this->fail('A transfer error during search must throw MeilisearchApiException.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertStringContainsString('cURL error 60', $e->getMessage());
    }
    $this->expectException(MeilisearchApiException::class);
    $this->api([$error])->waitForTask(7);
  }

  /**
   * @covers ::search
   */
  public function testApiErrorKeepsErrorCode(): void {
    $api = $this->api([
      $this->json([
        'message' => 'Attribute `x` is not filterable.',
        'code' => 'invalid_search_filter',
        'type' => 'invalid_request',
        'link' => '',
      ], 400),
    ]);
    try {
      $api->search('idx', 'foo', ['filter' => 'x = 1']);
      $this->fail('An API error must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('invalid_search_filter', $e->getErrorCode());
    }
  }

  /**
   * @covers ::search
   */
  public function testSearchReturnsRawResponse(): void {
    $api = $this->api([$this->json(['hits' => [], 'metadata' => ['queryUid' => 'abc']])]);
    $this->assertSame('abc', $api->search('idx', '', [])['metadata']['queryUid']);
  }

  /**
   * @covers \Drupal\quebracho_meilisearch\Api\MeilisearchApiFactory::create
   */
  public function testExtraHeadersAndUserAgentAreSent(): void {
    $api = $this->api([$this->json(['hits' => []])], ['Meili-Include-Metadata' => 'true']);
    $api->search('idx', '', []);
    $request = $this->history[0]['request'];
    $this->assertSame('true', $request->getHeaderLine('Meili-Include-Metadata'));
    $this->assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
    $this->assertStringContainsString('Meilisearch Drupal', $request->getHeaderLine('User-Agent'));
  }

  /**
   * @covers ::multiSearch
   */
  public function testMultiSearchPostsQueriesAndReturnsResults(): void {
    $api = $this->api([$this->json(['results' => [['hits' => [], 'indexUid' => 'idx']]])]);
    $results = $api->multiSearch([['indexUid' => 'idx', 'q' => 'foo', 'limit' => 0]]);
    $this->assertSame('idx', $results[0]['indexUid']);
    $request = $this->history[0]['request'];
    $this->assertSame('http://meili.test:7700/multi-search', (string) $request->getUri());
    $this->assertSame(['queries' => [['indexUid' => 'idx', 'q' => 'foo', 'limit' => 0]]], json_decode((string) $request->getBody(), TRUE));
  }

  /**
   * @covers ::similar
   */
  public function testSimilarPostsToTheIndexSimilarRoute(): void {
    $api = $this->api([$this->json(['hits' => [['search_api_id' => 'entity:node/2:en']], 'id' => 'a'])]);
    $response = $api->similar('idx', ['id' => 'a', 'embedder' => 'default']);
    $this->assertSame('entity:node/2:en', $response['hits'][0]['search_api_id']);
    $request = $this->history[0]['request'];
    $this->assertSame('http://meili.test:7700/indexes/idx/similar', (string) $request->getUri());
    $this->assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
    $this->assertSame(['id' => 'a', 'embedder' => 'default'], json_decode((string) $request->getBody(), TRUE));
  }

  /**
   * @covers ::similar
   */
  public function testSimilarKeepsErrorCode(): void {
    $api = $this->api([$this->json(['message' => 'Document `a` not found.', 'code' => 'not_found_similar_id'], 400)]);
    try {
      $api->similar('idx', ['id' => 'a', 'embedder' => 'default']);
      $this->fail('A missing document must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('not_found_similar_id', $e->getErrorCode());
    }
  }

  /**
   * @covers ::sendEvent
   */
  public function testSendEventPostsToEventsRoute(): void {
    $api = $this->api([new Response(201)]);
    $api->sendEvent(['eventType' => 'click']);
    $this->assertSame('http://meili.test:7700/events', (string) $this->history[0]['request']->getUri());
  }

  /**
   * @covers ::isCloud
   */
  public function testIsCloudDetectsMeilisearchDomain(): void {
    $this->assertTrue($this->api([], [], 'https://ms-abc123.fra.meilisearch.io')->isCloud());
    $this->assertTrue($this->api([], [], 'https://meilisearch.io')->isCloud());
    $this->assertFalse($this->api([], [], 'http://127.0.0.1:7700')->isCloud());
    $this->assertFalse($this->api([], [], 'https://evilmeilisearch.io')->isCloud(), 'Confusable hostname must not be treated as cloud.');
  }

}

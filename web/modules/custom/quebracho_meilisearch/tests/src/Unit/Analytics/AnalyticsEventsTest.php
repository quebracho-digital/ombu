<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Unit\Analytics;

use Drupal\quebracho_meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\quebracho_meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\quebracho_meilisearch_analytics\AnalyticsEvents;
use Drupal\quebracho_meilisearch_analytics\UserIdResolver;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\quebracho_meilisearch_analytics\AnalyticsEvents
 */
class AnalyticsEventsTest extends TestCase {

  /**
   * @covers ::click
   */
  public function testClickIsSentToCloudWithIndexUidAndUser(): void {
    $api = $this->createMock(MeilisearchApiServiceInterface::class);
    $api->method('isCloud')->willReturn(TRUE);
    $api->expects($this->once())->method('sendEvent')->with([
      'eventType' => 'click',
      'eventName' => 'Search Result Clicked',
      'queryUid' => 'q-uid',
      'objectId' => 'doc',
      'position' => 3,
      'indexUid' => 'prod_content',
      'userId' => 'u123',
    ]);
    $this->assertTrue($this->events($api)->click($this->index($api), 'q-uid', 'doc', 3));
  }

  /**
   * @covers ::click
   */
  public function testNothingIsSentToSelfHostedInstances(): void {
    $api = $this->createMock(MeilisearchApiServiceInterface::class);
    $api->method('isCloud')->willReturn(FALSE);
    $api->expects($this->never())->method('sendEvent');
    $this->assertFalse($this->events($api)->click($this->index($api), 'q-uid', 'doc', 3));
  }

  /**
   * Builds the service with a fixed visitor ID.
   */
  private function events(MeilisearchApiServiceInterface $api): AnalyticsEvents {
    $users = $this->createMock(UserIdResolver::class);
    $users->method('getUserId')->willReturn('u123');
    return new AnalyticsEvents($users, new NullLogger());
  }

  /**
   * Returns an index on a Meilisearch server using the given API.
   */
  private function index(MeilisearchApiServiceInterface $api): IndexInterface {
    $backend = $this->createMock(MeilisearchBackend::class);
    $backend->method('getApi')->willReturn($api);
    $backend->method('getIndexUid')->willReturn('prod_content');
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackend')->willReturn($backend);
    $index = $this->createMock(IndexInterface::class);
    $index->method('hasValidServer')->willReturn(TRUE);
    $index->method('getServerInstance')->willReturn($server);
    return $index;
  }

}

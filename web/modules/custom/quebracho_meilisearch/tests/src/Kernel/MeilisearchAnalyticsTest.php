<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Meilisearch analytics submodule.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchAnalyticsTest extends KernelTestBase {

  use ExampleContentTrait;
  use MeilisearchTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'search_api',
    'user',
    'system',
    'entity_test',
    'filter',
    'text',
    'views',
    'search_api_test_example_content',
    'quebracho_meilisearch',
    'quebracho_meilisearch_test',
    'quebracho_meilisearch_analytics',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpMeilisearchConnection(['quebracho_meilisearch_test_server']);
    parent::setUp();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('user');
    $this->installConfig(['search_api', 'search_api_test_example_content', 'quebracho_meilisearch_test']);
    $this->setUpExampleStructure();
    $this->insertExampleContent();
    $this->indexItems('quebracho_meilisearch_test_index');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * Skips the test when the instance does not return search metadata.
   *
   * Older Meilisearch versions ignore the Meili-Include-Metadata header.
   */
  protected function requireSearchMetadata(): void {
    $results = Index::load('quebracho_meilisearch_test_index')->query()->keys('foo')->execute();
    if ($results->getExtraData('quebracho_meilisearch_query_uid') === NULL) {
      $this->markTestSkipped('This Meilisearch version does not return search metadata.');
    }
  }

  /**
   * Searches ask Meilisearch for their query UID.
   */
  public function testSearchesReturnTheQueryUid(): void {
    $this->requireSearchMetadata();
    $results = Index::load('quebracho_meilisearch_test_index')->query()->keys('foo')->execute();
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $results->getExtraData('quebracho_meilisearch_query_uid'));
  }

  /**
   * Result rows carry what the click tracker needs, and are not cached.
   */
  public function testViewRowsCarryAnalyticsAttributes(): void {
    $this->requireSearchMetadata();
    $view = Views::getView('quebracho_meilisearch_test_view');
    $build = $view->preview();
    $html = (string) \Drupal::service('renderer')->renderRoot($build);

    $this->assertSame(5, substr_count($html, 'data-meilisearch-object-id="'));
    $this->assertStringContainsString('data-meilisearch-object-id="entity_3Aentity__test__mulrev__changed_2F1_3Aen"', $html);
    $this->assertStringContainsString('data-meilisearch-position="0"', $html);
    $this->assertStringContainsString('data-meilisearch-position="4"', $html);
    $this->assertStringContainsString('data-meilisearch-index="quebracho_meilisearch_test_index"', $html);
    $this->assertMatchesRegularExpression('/data-meilisearch-query-uid="[0-9a-f-]{36}"/', $html);
    $this->assertContains('quebracho_meilisearch_analytics/click_tracking', $build['#attached']['library']);
    $this->assertSame(0, $build['#cache']['max-age']);
  }

  /**
   * Cached view results must not replay an old query UID.
   */
  public function testCachedViewsGetFreshQueryUids(): void {
    $this->requireSearchMetadata();
    $view_config = \Drupal::configFactory()->getEditable('views.view.quebracho_meilisearch_test_view');
    $view_config->set('display.default.display_options.cache', ['type' => 'search_api_tag', 'options' => []])->save();

    $uids = [];
    for ($run = 0; $run < 2; $run++) {
      $view = Views::getView('quebracho_meilisearch_test_view');
      $build = $view->preview();
      $html = (string) \Drupal::service('renderer')->renderRoot($build);
      preg_match('/data-meilisearch-query-uid="([0-9a-f-]{36})"/', $html, $match);
      $uids[] = $match[1] ?? NULL;
    }
    $this->assertNotNull($uids[0]);
    $this->assertNotSame($uids[0], $uids[1]);
  }

  /**
   * The click endpoint validates its input.
   */
  public function testClickEndpointValidatesInput(): void {
    $this->assertSame(400, $this->postClick('not json')->getStatusCode());
    $this->assertSame(400, $this->postClick(['index' => 'quebracho_meilisearch_test_index'])->getStatusCode());
    $this->assertSame(404, $this->postClick($this->validClick(['index' => 'nope']))->getStatusCode());
    $this->assertSame(400, $this->postClick($this->validClick(['objectId' => 'a b']))->getStatusCode());
  }

  /**
   * Self-hosted Meilisearch has no /events route: nothing is sent.
   */
  public function testClickOnSelfHostedIsAccepted(): void {
    $this->assertSame(204, $this->postClick($this->validClick())->getStatusCode());
  }

  /**
   * Cross-site requests are refused.
   */
  public function testCrossSiteClicksAreRefused(): void {
    $this->assertSame(403, $this->postClick($this->validClick(), ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
  }

  /**
   * Clicks are rate limited per client.
   */
  public function testClicksAreRateLimited(): void {
    for ($i = 0; $i < 60; $i++) {
      $this->assertSame(204, $this->postClick($this->validClick())->getStatusCode());
    }
    $this->assertSame(429, $this->postClick($this->validClick())->getStatusCode());
  }

  /**
   * Returns a valid click payload, with overrides.
   */
  protected function validClick(array $overrides = []): array {
    return $overrides + [
      'index' => 'quebracho_meilisearch_test_index',
      'queryUid' => '01a0f434-50d7-7083-a0c7-33f3febe3cd7',
      'objectId' => 'entity_3Aentity__test__mulrev__changed_2F1_3Aen',
      'position' => 0,
    ];
  }

  /**
   * Sends a click through the HTTP kernel.
   */
  protected function postClick(array|string $payload, array $server = []) {
    $body = is_string($payload) ? $payload : json_encode($payload);
    $request = Request::create('/meilisearch/analytics/click', 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    return $this->container->get('http_kernel')->handle($request);
  }

}

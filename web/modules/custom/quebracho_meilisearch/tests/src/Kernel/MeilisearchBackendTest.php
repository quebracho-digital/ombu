<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Kernel;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\SearchApiException;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Meilisearch-specific backend behavior against a real instance.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchBackendTest extends KernelTestBase {

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
    'search_api_test_example_content',
    'meilisearch',
    'quebracho_meilisearch_test',
  ];

  /**
   * The ID of the test index.
   */
  protected string $indexId = 'quebracho_meilisearch_test_index';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpMeilisearchConnection(['quebracho_meilisearch_test_server']);
    parent::setUp();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_test_example_content', 'quebracho_meilisearch_test']);
    $this->setUpExampleStructure();
    $this->insertExampleContent();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * A second, broken server must not take over the first one's connection.
   */
  public function testServersAreIsolated(): void {
    $this->indexItems($this->indexId);

    $broken = Server::create([
      'id' => 'broken',
      'name' => 'Broken',
      'backend' => 'meilisearch',
      'backend_config' => ['url' => 'http://127.0.0.1:1', 'api_key' => 'nope'],
    ]);
    $this->assertFalse($broken->getBackend()->isAvailable());

    $results = Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertGreaterThan(0, $results->getResultCount());
  }

  /**
   * An unreachable Meilisearch must surface as a SearchApiException.
   */
  public function testUnreachableServerThrowsSearchApiException(): void {
    $server = Server::load('quebracho_meilisearch_test_server');
    $server->getBackend()->setConfiguration(['url' => 'http://127.0.0.1:1'] + $server->getBackendConfig());
    $index = Index::load($this->indexId);
    $query = $index->query()->keys('foo');

    try {
      $server->getBackend()->search($query);
      $this->fail('Searching an unreachable server must throw.');
    }
    catch (SearchApiException) {
      // A failed search must not be cached as "no results".
      $this->assertInstanceOf(RefinableCacheableDependencyInterface::class, $query);
      $this->assertSame(0, $query->getCacheMaxAge());
    }
  }

  /**
   * A task that fails in Meilisearch must fail indexing, not mark items done.
   */
  public function testFailedIndexingTaskKeepsItemsPending(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    // Recreate the Meilisearch index with a different primary key, so adding
    // documents with ours fails asynchronously.
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'other'])['taskUid']);

    // Search API catches the exception and logs it; nothing is marked done.
    $this->assertSame(0, $index->indexItems());
    $this->assertSame(0, $index->getTrackerInstance()->getIndexedItemsCount());
  }

  /**
   * Clearing one datasource must keep the documents of the others.
   */
  public function testDeleteAllItemsOfOneDatasource(): void {
    $this->indexItems($this->indexId);
    $index = Index::load($this->indexId);
    $backend = $index->getServerInstance()->getBackend();

    $backend->deleteAllIndexItems($index, 'entity:user');
    $this->assertSame(5, $index->query()->execute()->getResultCount());

    $backend->deleteAllIndexItems($index, 'entity:entity_test_mulrev_changed');
    $this->assertSame(0, $index->query()->execute()->getResultCount());
  }

  /**
   * Search results carry the Meilisearch ranking score and an exact count.
   */
  public function testResultsHaveScoresAndExactCounts(): void {
    $this->indexItems($this->indexId);
    $index = Index::load($this->indexId);
    $all = $index->query()->keys('foo')->range(0, 100)->execute();
    $results = $index->query()->keys('foo')->range(0, 1)->execute();

    $this->assertCount(1, $results->getResultItems());
    $this->assertGreaterThan(1, $results->getResultCount());
    $this->assertSame(count($all->getResultItems()), $results->getResultCount(), 'The count covers all pages.');
    $item = current($results->getResultItems());
    $this->assertGreaterThan(0, $item->getScore());
    $this->assertLessThanOrEqual(1, $item->getScore());
  }

  /**
   * Submitting the server form with an empty key keeps the stored key.
   */
  public function testEmptyApiKeyKeepsStoredKey(): void {
    // Let the form manage the key: drop its settings.php override.
    unset($GLOBALS['config']['search_api.server.quebracho_meilisearch_test_server']['backend_config']['api_key']);
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $backend = $this->meilisearchBackend();
    $backend->setConfiguration(['api_key' => 'stored-secret'] + $backend->getConfiguration());

    $form_state = new FormState();
    $form = $backend->buildConfigurationForm([], $form_state);
    $this->assertSame('password', $form['api_key']['#type']);
    $this->assertArrayNotHasKey('#default_value', $form['api_key'], 'The key is never sent to the browser.');

    $form_state->setValues(['api_key' => ''] + $backend->getConfiguration());
    $backend->submitConfigurationForm($form, $form_state);
    $this->assertSame('stored-secret', $backend->getConfiguration()['api_key']);
  }

  /**
   * Settings from settings.php are read-only in the form and never saved.
   */
  public function testOverriddenSettingsAreReadOnly(): void {
    $backend = $this->meilisearchBackend();
    $stored = \Drupal::configFactory()->getEditable('search_api.server.quebracho_meilisearch_test_server')->get('backend_config');

    $form_state = new FormState();
    $form = $backend->buildConfigurationForm([], $form_state);
    foreach (['url', 'api_key', 'index_prefix'] as $key) {
      $this->assertTrue($form[$key]['#disabled'], "$key is read-only.");
      $this->assertStringContainsString('settings.php', (string) $form[$key]['#description']);
    }
    $this->assertSame(getenv('MEILISEARCH_TEST_URL'), $form['url']['#default_value'], 'The effective URL is shown.');
    $this->assertArrayNotHasKey('#default_value', $form['api_key'], 'The key is never sent to the browser.');
    $this->assertArrayNotHasKey('#disabled', $form['search_mode']);

    $form_state->setValues([
      'url' => 'http://ignored.example:7700',
      'api_key' => 'ignored-key',
      'index_prefix' => 'ignored_',
    ] + $backend->getConfiguration());
    $backend->submitConfigurationForm($form, $form_state);
    $configuration = $backend->getConfiguration();
    foreach (['url', 'api_key', 'index_prefix'] as $key) {
      $this->assertSame($stored[$key], $configuration[$key], "$key keeps its stored value.");
    }
  }

  /**
   * The server page shows the connection status and document counts.
   */
  public function testViewSettingsShowsStatusAndDocumentCounts(): void {
    $info = $this->viewSettingsByLabel();
    $this->assertSame('Connected', (string) $info['Connection']['info']);
    $this->assertStringContainsString('0 documents', (string) $info['Index <em class="placeholder">Test index</em>']['info']);

    $count = $this->indexItems($this->indexId);
    $info = $this->viewSettingsByLabel();
    $this->assertStringContainsString("$count documents", (string) $info['Index <em class="placeholder">Test index</em>']['info']);
    $this->assertSame('url, api_key, index_prefix', $info['Set in settings.php']['info']);
  }

  /**
   * Returns the server page rows keyed by label.
   *
   * @return array<string, array<string, mixed>>
   *   The rows.
   */
  protected function viewSettingsByLabel(): array {
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $rows = [];
    foreach ($this->meilisearchBackend()->viewSettings() as $row) {
      $rows[(string) $row['label']] = $row;
    }
    return $rows;
  }

  /**
   * Results expose how long Meilisearch took.
   */
  public function testResultsExposeProcessingTime(): void {
    $this->indexItems($this->indexId);
    $results = Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertIsInt($results->getExtraData('quebracho_meilisearch_processing_time_ms'));
  }

  /**
   * Changing the index prefix sets up the new indexes and re-indexes.
   */
  public function testChangingThePrefixSetsUpNewIndexes(): void {
    $this->indexItems($this->indexId);
    // Let the form change the prefix: drop its override and cached copies.
    unset($GLOBALS['config']['search_api.server.quebracho_meilisearch_test_server']['backend_config']['index_prefix']);
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $server = Server::load('quebracho_meilisearch_test_server');
    $server->setBackendConfig(['index_prefix' => $this->meilisearchPrefix . 'moved_'] + $server->getBackendConfig());
    $server->save();

    $uid = $this->meilisearchPrefix . 'moved_' . $this->indexId;
    $settings = $this->meilisearchClient()->index($uid)->getSettings();
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame(0, Index::load($this->indexId)->getTrackerInstance()->getIndexedItemsCount(), 'Items are queued for re-indexing.');
  }

  /**
   * Indexing recreates a Meilisearch index deleted behind Drupal's back.
   *
   * This happens when an environment's URL is overridden in settings.php and
   * points at a fresh instance.
   */
  public function testIndexingSetsUpMissingIndexes(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);

    $this->indexItems($this->indexId);
    $settings = $client->index($uid)->getSettings();
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame(2, Index::load($this->indexId)->query()->addCondition('type', 'article')->execute()->getResultCount(), 'Filters work on the recreated index.');
  }

  /**
   * Saving a read-only index leaves the Meilisearch settings alone.
   */
  public function testReadOnlyIndexesKeepTheirSettings(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->index($uid)->updateFilterableAttributes(['owned_elsewhere'])['taskUid']);

    $index->set('read_only', TRUE);
    $index->save();
    $this->assertSame(['owned_elsewhere'], $client->index($uid)->getFilterableAttributes());
  }

  /**
   * Meilisearch must rank facet values by count before truncating them.
   */
  public function testFacetValuesAreRankedByCount(): void {
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    $faceting = $this->meilisearchClient()->index($uid)->getSettings()['faceting'];
    $this->assertSame(['*' => 'count'], $faceting['sortFacetValuesBy']);
  }

  /**
   * The search parameters hook also applies to facet count queries.
   */
  public function testSearchParamsHookAppliesToFacetQueries(): void {
    $this->indexItems($this->indexId);
    \Drupal::state()->set('quebracho_meilisearch_test.extra_filter', 'type = "item"');

    $query = Index::load($this->indexId)->query();
    $query->createAndAddConditionGroup('OR', ['facet:category'])->addCondition('category', 'item_category');
    $query->setOption('search_api_facets', [
      'category' => ['field' => 'category', 'limit' => 0, 'min_count' => 1, 'missing' => FALSE, 'operator' => 'or'],
    ]);
    $results = $query->execute();

    $this->assertSame(2, $results->getResultCount());
    $this->assertSame([['count' => 2, 'filter' => '"item_category"']], $results->getExtraData('search_api_facets')['category']);
  }

}

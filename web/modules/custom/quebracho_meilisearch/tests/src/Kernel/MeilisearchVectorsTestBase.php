<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Query\QueryInterface;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;

/**
 * Base for tests on a server whose vectors Drupal provides.
 *
 * The test server declares the "drupal" embedder with 3 dimensions, and
 * the test module adds the vectors in the "quebracho_meilisearch_test.vectors" state.
 */
abstract class MeilisearchVectorsTestBase extends KernelTestBase {

  use ExampleContentTrait;
  use MeilisearchTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'dblog',
    'field',
    'language',
    'search_api',
    'user',
    'system',
    'entity_test',
    'filter',
    'text',
    'views',
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
    $this->installSchema('dblog', ['watchdog']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_test_example_content', 'quebracho_meilisearch_test']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->setUpExampleStructure();
    $this->insertExampleContent();

    // Entity 5 has no vector.
    \Drupal::state()->set('quebracho_meilisearch_test.vectors', [
      1 => [1.0, 0.0, 0.0],
      2 => [0.9, 0.1, 0.0],
      // Leaning towards 1, so 3 and 4 never tie against it.
      3 => [0.1, 1.0, 0.0],
      4 => [0.0, 0.0, 1.0],
    ]);
    $this->setServerConfig(['embedder' => 'drupal', 'embedder_dimensions' => 3]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * Saves the test server with these backend settings.
   */
  protected function setServerConfig(array $settings): void {
    $server = Server::load('quebracho_meilisearch_test_server');
    $server->setBackendConfig($settings + $server->getBackendConfig());
    $server->save();
  }

  /**
   * Returns the entity IDs of a query's results, in order.
   *
   * @return int[]
   *   The entity IDs.
   */
  protected function entityIds(QueryInterface $query): array {
    $ids = [];
    foreach (array_keys($query->execute()->getResultItems()) as $item_id) {
      $ids[] = (int) explode(':', explode('/', $item_id)[1])[0];
    }
    return $ids;
  }

  /**
   * Returns the parameters sent to Meilisearch since recording started.
   *
   * @return array<string, array[]>
   *   Parameter arrays, keyed by hook_meilisearch_search_params_alter()
   *   context ("main", "facet_values"…).
   */
  protected function recordedParams(): array {
    return \Drupal::state()->get('quebracho_meilisearch_test.params', []);
  }

  /**
   * Starts recording the parameters sent to Meilisearch.
   */
  protected function recordParams(): void {
    \Drupal::state()->set('quebracho_meilisearch_test.record_params', TRUE);
    \Drupal::state()->delete('quebracho_meilisearch_test.params');
  }

}

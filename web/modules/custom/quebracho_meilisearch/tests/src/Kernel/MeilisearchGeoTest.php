<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Field;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests location search through the "search_api_location" query option.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchGeoTest extends KernelTestBase {

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

    FieldStorageConfig::create([
      'field_name' => 'geo',
      'entity_type' => 'entity_test_mulrev_changed',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'geo',
      'entity_type' => 'entity_test_mulrev_changed',
      'bundle' => 'item',
    ])->save();

    $index = Index::load('quebracho_meilisearch_test_index');
    $index->addField((new Field($index, 'geo'))
      ->setType('location')
      ->setDatasourceId('entity:entity_test_mulrev_changed')
      ->setPropertyPath('geo')
      ->setLabel('Geo'));
    $index->save();

    $this->addTestEntity(1, ['name' => 'Paris', 'type' => 'item', 'geo' => '48.8566,2.3522']);
    $this->addTestEntity(2, ['name' => 'Lyon', 'type' => 'item', 'geo' => '45.7640,4.8357']);
    $this->addTestEntity(3, ['name' => 'New York', 'type' => 'item', 'geo' => '40.7128,-74.0060']);
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
   * A radius keeps nearby items, and sorting on the field sorts by distance.
   */
  public function testRadiusAndDistanceSort(): void {
    $query = Index::load('quebracho_meilisearch_test_index')->query();
    // From Dijon: Lyon is ~175 km away, Paris ~260 km, New York far away.
    $query->setOption('search_api_location', [
      ['field' => 'geo', 'lat' => 47.322, 'lon' => 5.041, 'radius' => 500],
    ]);
    $query->sort('geo');
    $results = $query->execute();

    $this->assertSame($this->getItemIds([2, 1]), array_keys($results->getResultItems()));
  }

}

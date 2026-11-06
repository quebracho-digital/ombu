<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Kernel;

use Drupal\search_api\Query\QueryInterface;
use Drupal\Tests\search_api\Kernel\BackendTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs Search API's backend conformance suite against a real Meilisearch.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchBackendConformanceTest extends BackendTestBase {

  use MeilisearchTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'meilisearch',
    'quebracho_meilisearch_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $serverId = 'quebracho_meilisearch_test_server';

  /**
   * {@inheritdoc}
   */
  protected $indexId = 'quebracho_meilisearch_test_index';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    $this->setUpMeilisearchConnection([$this->serverId]);
    parent::setUp();
    $this->installConfig(['quebracho_meilisearch_test']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkServerBackend() {
    $uid = $this->meilisearchBackend($this->serverId)->getIndexUid($this->getIndex());
    $this->assertSame([$uid], $this->testIndexUids(), 'Adding the index created it in Meilisearch.');
    $settings = $this->meilisearchClient()->index($uid)->getSettings();
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertContains('search_api_datasource', $settings['filterableAttributes']);
    $this->assertSame(['name', 'body'], $settings['searchableAttributes'], 'Boosted fields are searched first.');
    $this->assertSame('search_api_document_id', $this->meilisearchClient()->index($uid)->fetchPrimaryKey());
  }

  /**
   * {@inheritdoc}
   *
   * Same as the parent, with the expectations Meilisearch semantics change.
   */
  protected function searchSuccess() {
    // Copied from Search API, keeping its style.
    // phpcs:disable Squiz.Arrays.ArrayDeclaration.NoKeySpecified,DrupalPractice.General.LanguageNone.Und
    $results = $this->buildSearch('test')->range(1, 2)->execute();
    $this->assertEquals(4, $results->getResultCount(), 'Search for »test« returned correct number of results.');
    $this->assertEquals($this->getItemIds([2, 3]), array_keys($results->getResultItems()), 'Search for »test« returned correct result.');
    $this->assertEmpty($results->getIgnoredSearchKeys());
    $this->assertEmpty($results->getWarnings());

    $id = $this->getItemIds([2])[0];
    $this->assertEquals($id, key($results->getResultItems()));
    $this->assertEquals($id, $results->getResultItems()[$id]->getId());
    $this->assertEquals('entity:entity_test_mulrev_changed', $results->getResultItems()[$id]->getDatasourceId());

    // Meilisearch matches the last word as a prefix: "foo" finds "foobar".
    $results = $this->buildSearch('test foo')->execute();
    $this->assertResults([1, 2, 3, 4], $results, 'Search for »test foo«');

    $results = $this->buildSearch('foo', ['type,item'])->execute();
    $this->assertResults([1, 2, 3], $results, 'Search for »foo«');

    $keys = [
      '#conjunction' => 'AND',
      'test',
      [
        '#conjunction' => 'OR',
        'baz',
        'foobar',
      ],
      [
        '#conjunction' => 'OR',
        '#negation' => TRUE,
        'bar',
        // cspell:disable-next-line
        'fooblob',
      ],
    ];
    $results = $this->buildSearch($keys)->execute();
    $this->assertResults([4], $results, 'Complex search 1');

    $query = $this->buildSearch();
    $conditions = $query->createAndAddConditionGroup('OR');
    $conditions->addCondition('name', 'bar');
    $conditions->addCondition('body', 'bar');
    $results = $query->execute();
    // Conditions on fulltext fields match the whole field value, not words.
    $this->assertResults([3], $results, 'Search with multi-field fulltext filter');

    $results = $this->buildSearch()
      ->addCondition('keywords', ['grape', 'apple'], 'IN')
      ->execute();
    $this->assertResults([2, 4, 5], $results, 'Query with IN filter');

    $results = $this->buildSearch()->addCondition('keywords', ['grape', 'apple'], 'NOT IN')->execute();
    $this->assertResults([1, 3], $results, 'Query with NOT IN filter');

    $results = $this->buildSearch()->addCondition('width', ['0.9', '1.5'], 'BETWEEN')->execute();
    $this->assertResults([4], $results, 'Query with BETWEEN filter');

    $results = $this->buildSearch()
      ->addCondition('width', ['0.9', '1.5'], 'NOT BETWEEN')
      ->execute();
    $this->assertResults([1, 2, 3, 5], $results, 'Query with NOT BETWEEN filter');

    $results = $this->buildSearch()
      ->setLanguages(['und', 'en'])
      ->addCondition('keywords', ['grape', 'apple'], 'IN')
      ->execute();
    $this->assertResults([2, 4, 5], $results, 'Query with IN filter');

    $results = $this->buildSearch()
      ->setLanguages(['und'])
      ->execute();
    $this->assertResults([], $results, 'Query with languages');

    $query = $this->buildSearch();
    $query->createAndAddConditionGroup('OR')
      ->addCondition('search_api_language', 'und')
      ->addCondition('width', ['0.9', '1.5'], 'BETWEEN');
    $results = $query->execute();
    $this->assertResults([4], $results, 'Query with search_api_language filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_language', 'und')
      ->addCondition('width', ['0.9', '1.5'], 'BETWEEN')
      ->execute();
    $this->assertResults([], $results, 'Query with search_api_language filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_language', ['und', 'en'], 'IN')
      ->addCondition('width', ['0.9', '1.5'], 'BETWEEN')
      ->execute();
    $this->assertResults([4], $results, 'Query with search_api_language filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_language', ['und', 'de'], 'NOT IN')
      ->addCondition('width', ['0.9', '1.5'], 'BETWEEN')
      ->execute();
    $this->assertResults([4], $results, 'Query with search_api_language "NOT IN" filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_id', $this->getItemIds([1])[0])
      ->execute();
    $this->assertResults([1], $results, 'Query with search_api_id filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_id', $this->getItemIds([2, 4]), 'NOT IN')
      ->execute();
    $this->assertResults([1, 3, 5], $results, 'Query with search_api_id "NOT IN" filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_id', $this->getItemIds([3])[0], '>')
      ->execute();
    $this->assertResults([4, 5], $results, 'Query with search_api_id "greater than" filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_datasource', 'foobar')
      ->execute();
    $this->assertResults([], $results, 'Query for a non-existing datasource');

    $results = $this->buildSearch()
      ->addCondition('search_api_datasource', ['foobar', 'entity:entity_test_mulrev_changed'], 'IN')
      ->execute();
    $this->assertResults([1, 2, 3, 4, 5], $results, 'Query with search_api_id "IN" filter');

    $results = $this->buildSearch()
      ->addCondition('search_api_datasource', ['foobar', 'entity:entity_test_mulrev_changed'], 'NOT IN')
      ->execute();
    $this->assertResults([], $results, 'Query with search_api_id "NOT IN" filter');

    // For a query without keys, all of these except for the last one should
    // have no effect. Therefore, we expect results with IDs in descending
    // order.
    $results = $this->buildSearch(NULL, [], [], FALSE)
      ->sort('search_api_relevance')
      ->sort('search_api_datasource', QueryInterface::SORT_DESC)
      ->sort('search_api_language')
      ->sort('search_api_id', QueryInterface::SORT_DESC)
      ->execute();
    $this->assertResults([5, 4, 3, 2, 1], $results, 'Query with magic sorts');
    // phpcs:enable
  }

  /**
   * {@inheritdoc}
   *
   * Meilisearch has no OR between keywords: parsed keys are flattened into
   * words and the server's matching strategy decides how many must match.
   */
  protected function regressionTest2111753() {
    $keys = ['#conjunction' => 'OR', 'foo', 'test'];
    $results = $this->buildSearch($keys, [], ['name'])->execute();
    $this->assertResults([1, 2, 4], $results, 'Flattened OR keywords on one field');
  }

  /**
   * {@inheritdoc}
   *
   * Negated keys become Meilisearch negative keywords ("-word"), each word
   * negated on its own.
   */
  protected function regressionTest2127001() {
    $keys = ['#conjunction' => 'OR', '#negation' => TRUE, 'foo', 'baz'];
    $results = $this->buildSearch($keys)->execute();
    $this->assertResults([3], $results, 'Negated OR fulltext search');

    $keys = ['#conjunction' => 'AND', 'test', ['#conjunction' => 'AND', '#negation' => TRUE, 'baz']];
    $results = $this->buildSearch($keys)->execute();
    $this->assertResults([2, 3], $results, 'Nested negated fulltext search');
  }

  /**
   * {@inheritdoc}
   *
   * Facets on fulltext fields count whole field values, not words.
   */
  protected function regressionTest2469547() {
    $query = $this->buildSearch();
    $query->setOption('search_api_facets', [
      'type' => ['field' => 'type', 'limit' => 0, 'min_count' => 1, 'missing' => FALSE],
    ]);
    $query->addCondition('id', 5, '<>');
    $query->range(0, 0);
    $facets = $query->execute()->getExtraData('search_api_facets', [])['type'];
    usort($facets, [$this, 'facetCompare']);
    $this->assertEquals([
      ['count' => 3, 'filter' => '"item"'],
      ['count' => 1, 'filter' => '"article"'],
    ], $facets);
  }

  /**
   * {@inheritdoc}
   *
   * With prefix matching, "test foo" also matches item 3 ("foobar").
   */
  protected function regressionTest1403916() {
    $query = $this->buildSearch('test foo');
    $query->setOption('search_api_facets', [
      'type' => ['field' => 'type', 'limit' => 0, 'min_count' => 1, 'missing' => TRUE],
    ]);
    $query->range(0, 0);
    $facets = $query->execute()->getExtraData('search_api_facets', [])['type'];
    usort($facets, [$this, 'facetCompare']);
    $this->assertEquals([
      ['count' => 3, 'filter' => '"item"'],
      ['count' => 1, 'filter' => '"article"'],
    ], $facets);
  }

  /**
   * {@inheritdoc}
   *
   * With prefix matching, "test foo" matches three items.
   */
  protected function regressionTest2783987() {
    $query = $this->buildSearch('test foo');
    $query->setOption('search_api_facets', [
      'type' => ['field' => 'type', 'limit' => 0, 'min_count' => 2, 'missing' => TRUE],
    ]);
    $query->range(0, 0);
    $facets = $query->execute()->getExtraData('search_api_facets', [])['type'];
    $this->assertEquals([['count' => 3, 'filter' => '"item"']], $facets);
  }

  /**
   * {@inheritdoc}
   *
   * Meilisearch tolerates typos, so a word with one more character still
   * matches, and it truncates long facet values, so exact filters on string
   * values over about 250 bytes cannot match.
   */
  protected function regressionTest2616804() {
    // cspell:disable-next-line
    $mb_word = 'äöüßáŧæøðđŋħĸµäöüßáŧæøðđŋħĸµ';
    $this->addTestEntity(9, [
      'name' => 'Test item 9',
      'type' => 'item',
      'body' => implode(' ', array_fill(0, 8, $mb_word)),
    ]);
    $this->assertEquals(1, $this->indexItems($this->indexId));
    $this->assertResults([9], $this->buildSearch($mb_word)->execute(), 'Search for word with 28 multi-byte characters');
    $this->assertResults([9], $this->buildSearch($mb_word . 'ä')->execute(), 'Typo-tolerant search for 29 characters');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkModuleUninstall() {
    $uid = $this->meilisearchBackend($this->serverId)->getIndexUid($this->getIndex());
    $this->getIndex()->delete();
    $this->assertNotContains($uid, $this->testIndexUids(), 'Deleting the index removed it from Meilisearch.');
  }

}

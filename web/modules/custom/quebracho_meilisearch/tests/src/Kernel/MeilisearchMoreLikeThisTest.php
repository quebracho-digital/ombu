<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Kernel;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\entity_test\Entity\EntityTestMulRevChanged;
use Drupal\quebracho_meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\quebracho_meilisearch\Search\MoreLikeThisInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests "More like this" through Meilisearch's similar documents.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchMoreLikeThisTest extends MeilisearchVectorsTestBase {

  /**
   * The nearest items, by entity ID as Views passes it; not the item itself.
   */
  public function testSimilarToAnEntity(): void {
    $this->indexItems($this->indexId);
    $this->assertTrue(Server::load('quebracho_meilisearch_test_server')->supportsFeature('search_api_mlt'));

    $results = $this->similarTo('1', ['fields' => ['name']])->execute();
    $ids = $this->entityIds($this->similarTo('1'));
    $this->assertSame([2, 3, 4], $ids);
    $this->assertNotContains(5, $ids, 'An item without a vector is not similar to any.');
    $this->assertSame(3, $results->getResultCount(), 'The count leaves out items without a vector.');
    $item = current($results->getResultItems());
    $this->assertGreaterThan(0, $item->getScore());
    $this->assertLessThanOrEqual(1, $item->getScore());
  }

  /**
   * By item ID, with conditions; offset and limit page through the results.
   */
  public function testSimilarToAnItemWithConditionsAndPaging(): void {
    $this->indexItems($this->indexId);
    $query = $this->similarTo('entity:entity_test_mulrev_changed/1:en');
    $query->addCondition('type', 'article');
    $this->assertSame([4], $this->entityIds($query));

    $this->assertSame([3], $this->entityIds($this->similarTo('1')->range(1, 1)));
    $this->assertSame(3, $this->similarTo('1')->range(1, 5)->execute()->getResultCount(), 'A page short of the limit ends the count.');
    $this->assertGreaterThan(2, $this->similarTo('1')->range(0, 2)->execute()->getResultCount(), 'A full page has more behind it.');
    $past_the_end = $this->similarTo('1')->range(10, 5)->execute()->getResultCount();
    $this->assertLessThan(10, $past_the_end, 'No pages past the end.');
    $this->assertGreaterThanOrEqual(3, $past_the_end);

    // No limit: up to the server's maximum. A limit of 0: no results.
    $this->setServerConfig(['max_total_hits' => 2]);
    $this->recordParams();
    $this->assertSame([2, 3], $this->entityIds($this->similarTo('1')));
    $this->assertSame([], $this->entityIds($this->similarTo('1')->range(0, 0)));
    $sent = $this->recordedParams()['similar'];
    $this->assertSame(2, $sent[0]['limit']);
    $this->assertSame(0, $sent[1]['limit']);
    $this->assertSame(['search_api_id'], $sent[0]['attributesToRetrieve']);
    $this->assertTrue($sent[0]['showRankingScore']);
  }

  /**
   * An entity's other translations are not similar to it.
   */
  public function testTranslationsAreLeftOut(): void {
    EntityTestMulRevChanged::load(1)->addTranslation('es', ['name' => 'uno'])->save();
    $this->indexItems($this->indexId);

    $item_ids = array_keys($this->similarTo('1')->execute()->getResultItems());
    $this->assertNotContains('entity:entity_test_mulrev_changed/1:es', $item_ids);
    $this->assertSame('entity:entity_test_mulrev_changed/2:en', $item_ids[0]);
  }

  /**
   * The query's language picks which translation is the reference.
   */
  public function testTheQueryLanguagePicksTheReference(): void {
    foreach ([1, 2, 4] as $id) {
      EntityTestMulRevChanged::load($id)->addTranslation('es', ['name' => "es $id"])->save();
    }
    // The Spanish translation of 1 points elsewhere than the English one.
    $vectors = \Drupal::state()->get('quebracho_meilisearch_test.vectors');
    $vectors['1:es'] = [0.0, 0.0, 1.0];
    \Drupal::state()->set('quebracho_meilisearch_test.vectors', $vectors);
    $this->indexItems($this->indexId);

    $this->assertSame([4, 2], $this->entityIds($this->similarTo('1')->setLanguages(['es'])));
    $this->assertSame(2, $this->entityIds($this->similarTo('1')->setLanguages(['en']))[0]);
  }

  /**
   * Without languages, the default translation is the reference.
   */
  public function testTheDefaultTranslationIsTheReference(): void {
    EntityTestMulRevChanged::load(1)->addTranslation('es', ['name' => 'uno'])->save();
    $vectors = \Drupal::state()->get('quebracho_meilisearch_test.vectors');
    $vectors['1:es'] = [0.0, 0.0, 1.0];
    \Drupal::state()->set('quebracho_meilisearch_test.vectors', $vectors);
    $this->indexItems($this->indexId);

    $this->assertSame(2, $this->entityIds($this->similarTo('1'))[0]);
    // The query's languages in their order: Spanish first.
    $this->assertSame(4, $this->entityIds($this->similarTo('1')->setLanguages(['es', 'en']))[0]);
  }

  /**
   * An entity ID is looked up in every entity datasource, or the one given.
   */
  public function testEntityIdsAcrossDatasources(): void {
    $this->installEntitySchema('user');
    $index = Index::load($this->indexId);
    $index->addDatasource(\Drupal::getContainer()->get('search_api.plugin_helper')->createDatasourcePlugin($index, 'entity:user'));
    $index->save();
    User::create(['uid' => 1, 'name' => 'one'])->save();
    User::create(['uid' => 7, 'name' => 'seven'])->save();
    $more_like_this = \Drupal::service('meilisearch.more_like_this');
    $query = $index->query();

    $this->assertSame(['entity:entity_test_mulrev_changed/1:en', 'entity:user/1:en'], $more_like_this->referenceItemIds($query, ['id' => '1']));
    $this->assertSame(['entity:user/7:en'], $more_like_this->referenceItemIds($query, ['id' => '7']), 'Found in the second datasource only.');
    $only_users = ['id' => '1', 'datasource' => 'entity:user'];
    $this->assertSame(['entity:user/1:en'], $more_like_this->referenceItemIds($query, $only_users));
  }

  /**
   * Another translation is the reference when the first is not indexed.
   */
  public function testFirstIndexedTranslationIsTheReference(): void {
    EntityTestMulRevChanged::load(1)->addTranslation('es', ['name' => 'uno'])->save();
    $this->indexItems($this->indexId);
    $index = Index::load($this->indexId);
    $index->getServerInstance()->getBackend()->deleteItems($index, ['entity:entity_test_mulrev_changed/1:en']);

    $this->assertSame(2, $this->entityIds($this->similarTo('1'))[0]);
  }

  /**
   * A reference without a vector, or not indexed, has no similar items.
   */
  public function testReferencesWithoutResults(): void {
    $this->indexItems($this->indexId);
    $this->assertSame(0, $this->similarTo('999')->execute()->getResultCount());
    $this->assertSame([], $this->entityIds($this->similarTo('5')), 'No vector.');
    $this->assertSame([], $this->entityIds($this->similarTo('999')), 'No such entity.');
    $this->assertSame([], $this->entityIds($this->similarTo('entity:entity_test_mulrev_changed/999:en')), 'No such item.');
  }

  /**
   * The "datasource" key restricts an entity ID to one datasource.
   */
  public function testDatasourceOption(): void {
    $this->indexItems($this->indexId);
    $this->assertSame(2, $this->entityIds($this->similarTo('1', ['datasource' => 'entity:entity_test_mulrev_changed']))[0]);
    $this->assertSame([], $this->entityIds($this->similarTo('1', ['datasource' => 'entity:user'])));
    $this->assertSame([], \Drupal::service('meilisearch.more_like_this')->referenceItemIds(Index::load($this->indexId)->query(), ['id' => 'nowhere/5']), 'An unknown datasource is no item ID.');
  }

  /**
   * The query's location applies to similar documents.
   */
  public function testLocationApplies(): void {
    $this->indexItems($this->indexId);
    $this->recordParams();
    $query = $this->similarTo('1');
    $query->setOption('search_api_location', [['field' => 'geo', 'lat' => 1, 'lon' => 2, 'radius' => 5]]);
    try {
      $query->execute();
    }
    catch (SearchApiException) {
      // The test index has no location field.
    }
    $this->assertStringContainsString('_geoRadius(', $this->recordedParams()['similar'][0]['filter']);
  }

  /**
   * The search parameters hook runs with the "similar" context.
   */
  public function testSearchParamsHookApplies(): void {
    $this->indexItems($this->indexId);
    $this->recordParams();
    \Drupal::state()->set('quebracho_meilisearch_test.extra_filter', 'type = "article"');
    $this->assertSame([4], $this->entityIds($this->similarTo('1')));

    $params = $this->recordedParams();
    $this->assertSame(['similar'], array_keys($params));
    $this->assertSame('drupal', $params['similar'][0]['embedder']);
    $this->assertArrayNotHasKey('q', $params['similar'][0]);
  }

  /**
   * A failed search throws and is not cached.
   *
   * Only a missing reference means "no results".
   */
  public function testFailuresThrow(): void {
    $this->indexItems($this->indexId);
    \Drupal::state()->set('quebracho_meilisearch_test.extra_filter', 'bogus =');
    try {
      $this->similarTo('1')->execute();
      $this->fail('An invalid filter must throw.');
    }
    catch (SearchApiException) {
      $this->addToAssertionCount(1);
    }

    \Drupal::state()->delete('quebracho_meilisearch_test.extra_filter');
    $server = Server::load('quebracho_meilisearch_test_server');
    $server->getBackend()->setConfiguration(['url' => 'http://127.0.0.1:1'] + $server->getBackendConfig());
    $query = $this->similarTo('1');
    try {
      $server->getBackend()->search($query);
      $this->fail('An unreachable server must throw.');
    }
    catch (SearchApiException) {
      $this->assertInstanceOf(RefinableCacheableDependencyInterface::class, $query);
      $this->assertSame(0, $query->getCacheMaxAge());
    }
  }

  /**
   * The Views contextual filter, end to end.
   */
  public function testViewsContextualFilter(): void {
    $this->indexItems($this->indexId);
    $view = Views::getView('quebracho_meilisearch_test_view');
    $view->setDisplay('default');
    $view->setHandler('default', 'argument', 'search_api_more_like_this', [
      'id' => 'search_api_more_like_this',
      'table' => 'search_api_index_quebracho_meilisearch_test_index',
      'field' => 'search_api_more_like_this',
      'plugin_id' => 'search_api_more_like_this',
      'default_action' => 'empty',
    ]);
    $view->setArguments(['1']);
    $view->execute();
    $this->assertInstanceOf(SearchApiQuery::class, $view->query);
    $item_ids = array_keys($view->query->getSearchApiResults()->getResultItems());
    $this->assertSame('entity:entity_test_mulrev_changed/2:en', $item_ids[0] ?? NULL);
    $this->assertCount(count($item_ids), $view->result);
  }

  /**
   * Without an embedder, the server offers no "More like this".
   */
  public function testNoEmbedder(): void {
    $this->setServerConfig(['embedder' => '', 'embedder_dimensions' => 0]);
    $this->assertFalse(Server::load('quebracho_meilisearch_test_server')->supportsFeature('search_api_mlt'));
    $this->recordParams();
    try {
      $this->similarTo('1')->execute();
      $this->fail('"More like this" without an embedder must throw.');
    }
    catch (SearchApiException) {
      $this->assertSame([], $this->recordedParams(), 'Refused before any search.');
    }
  }

  /**
   * Facets do not apply: facet blocks get an empty list, not an error.
   */
  public function testFacetsAreEmpty(): void {
    $this->indexItems($this->indexId);
    $query = $this->similarTo('1');
    $query->setOption('search_api_facets', [
      'category' => ['field' => 'category', 'limit' => 0, 'min_count' => 1, 'missing' => FALSE, 'operator' => 'and'],
    ]);
    $this->assertSame([], $query->execute()->getExtraData('search_api_facets'));
    $this->assertNull($this->similarTo('1')->execute()->getExtraData('search_api_facets'), 'None asked, none set.');
  }

  /**
   * A serialized backend leaves the service out and gets it back.
   */
  public function testSerializedBackendKeepsMoreLikeThis(): void {
    $serialized = serialize($this->meilisearchBackend());
    $this->assertStringNotContainsString('MoreLikeThis', $serialized);
    $property = new \ReflectionProperty(MeilisearchBackend::class, 'moreLikeThis');
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize -- the test's own string.
    $this->assertInstanceOf(MoreLikeThisInterface::class, $property->getValue(unserialize($serialized)));
  }

  /**
   * Returns a "More like this" query on the test index.
   */
  protected function similarTo(string $id, array $mlt = []): QueryInterface {
    $query = Index::load($this->indexId)->query();
    $query->setOption('search_api_mlt', ['id' => $id] + $mlt);
    return $query;
  }

}

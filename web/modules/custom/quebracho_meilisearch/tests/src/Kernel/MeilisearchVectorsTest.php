<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\quebracho_meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\SearchApiException;
use Meilisearch\Contracts\DocumentsQuery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests vectors provided by Drupal and queries by vector.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchVectorsTest extends MeilisearchVectorsTestBase {

  /**
   * The server declares its userProvided embedder on the index.
   */
  public function testUserProvidedEmbedderIsDeclared(): void {
    $embedders = $this->embedders();
    $this->assertSame('userProvided', $embedders['drupal']['source']);
    $this->assertSame(3, $embedders['drupal']['dimensions']);
  }

  /**
   * Documents get the hook's vectors; one without a vector is still indexed.
   */
  public function testDocumentsCarryTheHooksVectors(): void {
    $this->assertSame($this->totalItems(), $this->indexItems($this->indexId));
    $context = \Drupal::state()->get('quebracho_meilisearch_test.documents_context');
    $this->assertSame('drupal', $context['embedder']);
    $this->assertSame(3, $context['dimensions']);
    $this->assertNotEmpty($context['items']);
    $vectors = $this->storedVectors([4, 5]);
    $this->assertEquals([[0.0, 0.0, 1.0]], $vectors[4]);
    $this->assertSame([], $vectors[5]);
  }

  /**
   * A vector Meilisearch would refuse is left out; the batch is indexed.
   */
  public function testInvalidVectorsAreLeftOut(): void {
    $vectors = \Drupal::state()->get('quebracho_meilisearch_test.vectors');
    $vectors[2] = ['a', 'b', 'c'];
    $vectors[3] = [1.0, 0.0];
    \Drupal::state()->set('quebracho_meilisearch_test.vectors', $vectors);

    $this->assertSame($this->totalItems(), $this->indexItems($this->indexId));
    $stored = $this->storedVectors([1, 2, 3]);
    $this->assertEquals([[1.0, 0.0, 0.0]], $stored[1]);
    $this->assertSame([], $stored[2]);
    $this->assertSame([], $stored[3]);

    $logged = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['variables'])
      ->condition('type', 'meilisearch')
      ->condition('severity', RfcLogLevel::WARNING)
      ->execute()->fetchCol();
    $this->assertCount(1, $logged, 'One entry for the batch.');
    $variables = unserialize($logged[0], ['allowed_classes' => FALSE]);
    $this->assertSame(2, $variables['@count']);
    $this->assertStringContainsString('entity:entity_test_mulrev_changed/2:en', $variables['%items']);
    $this->assertStringContainsString('entity:entity_test_mulrev_changed/3:en', $variables['%items']);
  }

  /**
   * A hook that throws fails the batch as Search API expects.
   */
  public function testThrowingHookFailsTheBatch(): void {
    \Drupal::state()->set('quebracho_meilisearch_test.documents_throw', TRUE);
    $this->assertSame(0, $this->indexItems($this->indexId));
    $this->assertSame(0, Index::load($this->indexId)->getTrackerInstance()->getIndexedItemsCount());
    $logged = \Drupal::database()->query("SELECT COUNT(*) FROM {watchdog} WHERE type = 'meilisearch' AND message LIKE '%documents_alter%'")->fetchField();
    $this->assertSame('1', (string) $logged);

    // Search API only catches its own exception, whatever the hook throws.
    $index = Index::load($this->indexId);
    $item = \Drupal::getContainer()->get('search_api.fields_helper')->createItem($index, 'entity:entity_test_mulrev_changed/1:en');
    foreach ([TRUE, 'error'] as $thrown) {
      \Drupal::state()->set('quebracho_meilisearch_test.documents_throw', $thrown);
      try {
        $this->meilisearchBackend()->indexItems($index, [$item->getId() => $item]);
        $this->fail('The batch must fail.');
      }
      catch (SearchApiException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * An embedder managed in Meilisearch gets no vectors from Drupal.
   */
  public function testManagedEmbedderGetsNoVectors(): void {
    $this->setServerConfig(['embedder' => 'managed', 'embedder_dimensions' => 0]);
    $this->assertSame([], $this->embedders(), 'The declared embedder is removed, none is added.');
    $this->assertSame($this->totalItems(), $this->indexItems($this->indexId));
    $this->assertNull(\Drupal::state()->get('quebracho_meilisearch_test.documents_context')['embedder'], 'The hook is told.');

    // Without the guard, documents would get vectors for an embedder "".
    $prepare = \Closure::bind(function (array $documents): array {
      $this->prepareVectors($documents);
      return $documents;
    }, $this->meilisearchBackend(), MeilisearchBackend::class);
    $this->assertSame([['search_api_id' => 'x']], $prepare([['search_api_id' => 'x']]));
  }

  /**
   * Another embedder name, or none, removes the one declared before.
   */
  public function testReplacedEmbedderIsRemoved(): void {
    $this->indexItems($this->indexId);
    $this->assertGreaterThan(0, Index::load($this->indexId)->getTrackerInstance()->getIndexedItemsCount());
    $this->setServerConfig(['embedder' => 'other']);
    $this->assertSame(['other'], array_keys($this->embedders()));
    $warnings = array_map('strval', \Drupal::messenger()->messagesByType('warning'));
    $this->assertStringContainsString('drupal', implode(' ', $warnings), 'The admin is told.');
    $this->assertSame(0, Index::load($this->indexId)->getTrackerInstance()->getIndexedItemsCount(), 'Items are queued for re-indexing.');

    $this->setServerConfig(['embedder_dimensions' => 0]);
    $this->assertSame([], $this->embedders());
  }

  /**
   * Vectors can be turned on for an index that already has documents.
   */
  public function testDeclaringOnPopulatedIndex(): void {
    $this->setServerConfig(['embedder_dimensions' => 0]);
    $this->indexItems($this->indexId);
    $this->setServerConfig(['embedder_dimensions' => 3]);
    $this->assertSame('userProvided', $this->embedders()['drupal']['source']);
    $this->assertSame($this->totalItems(), $this->indexItems($this->indexId));
    $this->assertEquals([[0.0, 0.0, 1.0]], $this->storedVectors([4])[4]);
  }

  /**
   * New dimensions on a populated index take the new vectors.
   */
  public function testChangingTheDimensionsReindexes(): void {
    $this->indexItems($this->indexId);
    \Drupal::state()->set('quebracho_meilisearch_test.vectors', [1 => [1.0, 0.0, 0.0, 0.0]]);
    \Drupal::messenger()->deleteAll();
    $this->setServerConfig(['embedder_dimensions' => 4]);
    $this->assertSame(4, $this->embedders()['drupal']['dimensions']);
    $this->assertSame([], \Drupal::messenger()->messagesByType('warning'), 'No embedder was removed.');

    $this->indexItems($this->indexId);
    $this->assertEquals([[1.0, 0.0, 0.0, 0.0]], $this->storedVectors([1])[1]);
  }

  /**
   * Keyword searches still work on a hybrid server with these vectors.
   */
  public function testKeywordSearchOnHybridServer(): void {
    $this->setServerConfig(['search_mode' => 'hybrid']);
    $this->indexItems($this->indexId);
    $results = Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertGreaterThan(0, $results->getResultCount());
  }

  /**
   * A read-only index is left alone when the embedder changes.
   */
  public function testReadOnlyIndexIsLeftAlone(): void {
    $this->indexItems($this->indexId);
    $index = Index::load($this->indexId);
    $index->set('read_only', TRUE)->save();
    $this->setServerConfig(['embedder' => 'other']);
    $this->assertSame(['drupal'], array_keys($this->embedders()));
    $this->assertEquals([[0.0, 0.0, 1.0]], $this->storedVectors([4])[4], 'Documents are kept.');
  }

  /**
   * A new result limit is pushed to the indexes, without re-indexing.
   */
  public function testResultLimitIsPushed(): void {
    $indexed = $this->indexItems($this->indexId);
    $this->assertGreaterThan(0, $indexed);
    $this->setServerConfig(['max_total_hits' => 123]);
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    $this->assertSame(123, $this->meilisearchClient()->index($uid)->getSettings()['pagination']['maxTotalHits']);
    $this->assertSame($indexed, Index::load($this->indexId)->getTrackerInstance()->getIndexedItemsCount());
  }

  /**
   * A server saved before this setting existed has no vectors from Drupal.
   */
  public function testDimensionsDefaultToZero(): void {
    $server = Server::create([
      'id' => 'older',
      'name' => 'Older',
      'backend' => 'meilisearch',
      'backend_config' => ['url' => 'http://127.0.0.1:1', 'embedder' => 'x'],
    ]);
    $this->assertSame(0, $server->getBackend()->getConfiguration()['embedder_dimensions']);
  }

  /**
   * The server form refuses settings Meilisearch cannot work with.
   */
  public function testServerFormValidation(): void {
    $backend = $this->meilisearchBackend();
    $errors = function (array $values) use ($backend): array {
      $form_state = new FormState();
      $form = $backend->buildConfigurationForm([], $form_state);
      $form_state->setValues($values + $backend->getConfiguration());
      $backend->validateConfigurationForm($form, $form_state);
      return array_keys($form_state->getErrors());
    };
    $this->assertSame([], $errors([]));
    $this->assertSame([], $errors(['embedder' => '', 'embedder_dimensions' => 0, 'search_mode' => 'keyword']));
    $this->assertSame([], $errors(['embedder' => 'managed', 'embedder_dimensions' => 0, 'search_mode' => 'semantic']));
    $this->assertSame(['embedder'], $errors(['embedder' => '', 'embedder_dimensions' => 5]));
    $this->assertSame(['search_mode'], $errors(['search_mode' => 'semantic']));
    $this->assertSame([], $errors(['search_mode' => 'hybrid']));

    $submit = function (int $dimensions) use ($backend): int {
      $form_state = new FormState();
      $form = $backend->buildConfigurationForm([], $form_state);
      $form_state->setValues(['embedder_dimensions' => $dimensions] + $backend->getConfiguration());
      $backend->submitConfigurationForm($form, $form_state);
      return $backend->getConfiguration()['embedder_dimensions'];
    };
    $this->assertSame(3, $backend->buildConfigurationForm([], new FormState())['embedder_dimensions']['#default_value']);
    $this->assertSame(5, $submit(5));
    $this->assertSame(0, $submit(-3));
  }

  /**
   * A query with a vector of its own is searched by that vector.
   */
  public function testVectorQuery(): void {
    $this->indexItems($this->indexId);
    $query = Index::load($this->indexId)->query();
    $query->setOption('quebracho_meilisearch_vector', [0.0, 0.1, 0.9]);
    $this->assertSame([4, 3], array_slice($this->entityIds($query), 0, 2));

    // Only a hybrid server mixes in the keys by default.
    $this->setServerConfig(['semantic_ratio' => 0.25]);
    $this->recordParams();
    $query = Index::load($this->indexId)->query();
    $query->setOption('quebracho_meilisearch_vector', [0.0, 0.1, 0.9]);
    $query->execute();
    $this->assertSame(1.0, $this->recordedParams()['main'][0]['hybrid']['semanticRatio']);
  }

  /**
   * A query vector Meilisearch would refuse throws before reaching it.
   */
  public function testInvalidQueryVectorsThrow(): void {
    $this->recordParams();
    foreach ([[0.0, 1.0], ['0', '1', '0'], 'vector'] as $vector) {
      $query = Index::load($this->indexId)->query();
      $query->setOption('quebracho_meilisearch_vector', $vector);
      try {
        $query->execute();
        $this->fail('An invalid vector must throw.');
      }
      catch (SearchApiException) {
        $this->addToAssertionCount(1);
      }
    }
    $this->assertSame([], $this->recordedParams(), 'No search was sent.');
  }

  /**
   * On a hybrid server, a vector and the keys mix by the server's ratio.
   *
   * Facet count queries carry the same vector.
   */
  public function testVectorOnHybridServer(): void {
    $this->setServerConfig(['search_mode' => 'hybrid', 'semantic_ratio' => 0.25]);
    $this->indexItems($this->indexId);
    $this->recordParams();
    $query = Index::load($this->indexId)->query()->keys('foo');
    $query->setOption('quebracho_meilisearch_vector', [0.0, 0.0, 1.0]);
    $query->createAndAddConditionGroup('OR', ['facet:category'])->addCondition('category', 'item_category');
    $query->setOption('search_api_facets', [
      'category' => ['field' => 'category', 'limit' => 0, 'min_count' => 1, 'missing' => FALSE, 'operator' => 'or'],
    ]);
    $query->execute();

    $params = $this->recordedParams();
    $this->assertSame(0.25, $params['main'][0]['hybrid']['semanticRatio']);
    $this->assertSame([0.0, 0.0, 1.0], $params['main'][0]['vector']);
    $this->assertNotEmpty($params['facet_values']);
    foreach ($params['facet_values'] as $facet_params) {
      $this->assertSame([0.0, 0.0, 1.0], $facet_params['vector']);
    }
  }

  /**
   * A query can set its own semantic ratio, from 0 to 1, and nothing else.
   */
  public function testSemanticRatioOption(): void {
    $this->indexItems($this->indexId);
    foreach ([0, 0.3, 1] as $ratio) {
      $this->recordParams();
      $query = Index::load($this->indexId)->query()->keys('foo');
      $query->setOption('quebracho_meilisearch_vector', [0.0, 0.0, 1.0]);
      $query->setOption('quebracho_meilisearch_semantic_ratio', $ratio);
      $query->execute();
      $this->assertSame((float) $ratio, $this->recordedParams()['main'][0]['hybrid']['semanticRatio']);
    }
    foreach ([-0.1, 1.1, 'x', NAN, INF] as $ratio) {
      $this->recordParams();
      $query = Index::load($this->indexId)->query();
      $query->setOption('quebracho_meilisearch_vector', [0.0, 0.0, 1.0]);
      $query->setOption('quebracho_meilisearch_semantic_ratio', $ratio);
      try {
        $query->execute();
        $this->fail('An invalid ratio must throw.');
      }
      catch (SearchApiException) {
        $this->assertSame([], $this->recordedParams(), 'Refused before any search.');
      }
    }
  }

  /**
   * Keys alone: hybrid on a hybrid server, plain keyword search otherwise.
   */
  public function testKeysAloneUseTheServerMode(): void {
    $this->indexItems($this->indexId);
    $this->recordParams();
    Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertArrayNotHasKey('hybrid', $this->recordedParams()['main'][0]);

    $this->setServerConfig(['search_mode' => 'hybrid', 'semantic_ratio' => 0.25]);
    $this->recordParams();
    Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertSame(['embedder' => 'drupal', 'semanticRatio' => 0.25], $this->recordedParams()['main'][0]['hybrid']);
  }

  /**
   * With an embedder managed in Meilisearch, any length of vector is sent.
   *
   * Drupal does not know its dimensions; Meilisearch checks them.
   */
  public function testVectorForManagedEmbedder(): void {
    $this->setServerConfig(['embedder' => 'managed', 'embedder_dimensions' => 0]);
    $this->recordParams();
    $query = Index::load($this->indexId)->query();
    $query->setOption('quebracho_meilisearch_vector', [0.5, 0.5]);
    try {
      $query->execute();
    }
    catch (SearchApiException) {
      // The test index has no embedder of that name.
    }
    $this->assertSame([0.5, 0.5], $this->recordedParams()['main'][0]['vector']);

    foreach ([[], 'vector', ['a']] as $vector) {
      $this->recordParams();
      $query = Index::load($this->indexId)->query();
      $query->setOption('quebracho_meilisearch_vector', $vector);
      try {
        $query->execute();
        $this->fail('An invalid vector must throw.');
      }
      catch (SearchApiException) {
        $this->assertSame([], $this->recordedParams(), 'Refused before any search.');
      }
    }
  }

  /**
   * Without an embedder a query cannot be searched by vector.
   */
  public function testVectorQueryNeedsAnEmbedder(): void {
    $this->setServerConfig(['embedder' => '', 'embedder_dimensions' => 0]);
    $this->recordParams();
    $query = Index::load($this->indexId)->query();
    $query->setOption('quebracho_meilisearch_vector', [0.0, 0.0, 1.0]);
    try {
      $query->execute();
      $this->fail('A vector without an embedder must throw.');
    }
    catch (SearchApiException) {
      $this->assertSame([], $this->recordedParams(), 'Refused before any search.');
    }
  }

  /**
   * Returns the embedders of the test index in Meilisearch.
   */
  protected function embedders(): array {
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    return iterator_to_array($this->meilisearchClient()->index($uid)->getSettings()['embedders']);
  }

  /**
   * Returns the stored vectors of the English items of these entities.
   *
   * @param int[] $entity_ids
   *   Entity IDs.
   *
   * @return array<int, array>
   *   The vectors of the "drupal" embedder, keyed by entity ID.
   */
  protected function storedVectors(array $entity_ids): array {
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    $item_ids = array_map(fn($id) => '"entity:entity_test_mulrev_changed/' . $id . ':en"', $entity_ids);
    $documents = $this->meilisearchClient()->index($uid)->getDocuments((new DocumentsQuery())
      ->setFilter(['search_api_id IN [' . implode(', ', $item_ids) . ']'])
      ->setRetrieveVectors(TRUE))->getResults();
    $vectors = [];
    foreach ($documents as $document) {
      $vectors[(int) explode(':', explode('/', $document['search_api_id'])[1])[0]] = $document['_vectors']['drupal']['embeddings'];
    }
    return $vectors;
  }

  /**
   * Returns the number of items the test index tracks.
   */
  protected function totalItems(): int {
    return Index::load($this->indexId)->getTrackerInstance()->getTotalItemsCount();
  }

}

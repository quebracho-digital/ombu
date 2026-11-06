<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\quebracho_meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\Entity\Server;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesQuery;

/**
 * Connects kernel tests to the Meilisearch instance given by the environment.
 *
 * Set MEILISEARCH_TEST_URL (and MEILISEARCH_TEST_KEY when the instance has a
 * master key). Tests are skipped when the URL is missing. Every test gets its
 * own index prefix, and its indexes are deleted afterwards.
 */
trait MeilisearchTestTrait {

  /**
   * The index prefix used by the current test.
   */
  protected string $meilisearchPrefix = '';

  /**
   * Points the test servers at the Meilisearch instance under test.
   *
   * Must run before parent::setUp() so installed configuration picks it up.
   *
   * @param string[] $server_ids
   *   The IDs of the servers to override.
   */
  protected function setUpMeilisearchConnection(array $server_ids): void {
    $url = getenv('MEILISEARCH_TEST_URL');
    if (!$url) {
      $this->markTestSkipped('Set MEILISEARCH_TEST_URL to run tests against Meilisearch.');
    }
    $this->meilisearchPrefix = 'drupal_test_' . bin2hex(random_bytes(4)) . '_';
    foreach ($server_ids as $i => $server_id) {
      $GLOBALS['config']["search_api.server.$server_id"]['backend_config'] = [
        'url' => $url,
        'api_key' => (string) getenv('MEILISEARCH_TEST_KEY'),
        'index_prefix' => $this->meilisearchPrefix . $i . '_',
      ];
    }
  }

  /**
   * Returns the Meilisearch backend of a server.
   */
  protected function meilisearchBackend(string $server_id = 'quebracho_meilisearch_test_server'): MeilisearchBackend {
    $backend = Server::load($server_id)->getBackend();
    assert($backend instanceof MeilisearchBackend);
    return $backend;
  }

  /**
   * Returns a raw client for asserting on Meilisearch state.
   */
  protected function meilisearchClient(): Client {
    return new Client((string) getenv('MEILISEARCH_TEST_URL'), (string) getenv('MEILISEARCH_TEST_KEY'));
  }

  /**
   * Returns the UIDs of the Meilisearch indexes created by this test.
   *
   * @return string[]
   *   Index UIDs.
   */
  protected function testIndexUids(): array {
    $uids = [];
    foreach ($this->meilisearchClient()->getIndexes((new IndexesQuery())->setLimit(1000))->getResults() as $index) {
      if (str_starts_with($index->getUid(), $this->meilisearchPrefix)) {
        $uids[] = $index->getUid();
      }
    }
    return $uids;
  }

  /**
   * Deletes the indexes created by this test and removes the overrides.
   */
  protected function tearDownMeilisearch(): void {
    if ($this->meilisearchPrefix !== '') {
      $client = $this->meilisearchClient();
      foreach ($this->testIndexUids() as $uid) {
        $client->waitForTask($client->deleteIndex($uid)['taskUid']);
      }
    }
    foreach (array_keys($GLOBALS['config'] ?? []) as $name) {
      if (str_starts_with($name, 'search_api.server.')) {
        unset($GLOBALS['config'][$name]);
      }
    }
  }

}

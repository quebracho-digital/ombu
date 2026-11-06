<?php

/**
 * @file
 * Hooks provided by the Meilisearch module.
 *
 * Search API query options this backend reads, besides Search API's own:
 * - quebracho_meilisearch_vector: a vector of the caller's, searched with the
 *   server's embedder (a list of finite numbers, of the declared dimensions
 *   for vectors provided by Drupal).
 * - quebracho_meilisearch_semantic_ratio: 0 to 1, how much the vector counts against
 *   the keys; defaults to the server's ratio on a hybrid server, else 1.
 *   With vectors provided by Drupal, Meilisearch cannot embed the keys: a
 *   query with keys, no vector and a ratio of 1 fails.
 * - quebracho_meilisearch_highlighting: set by the Meilisearch highlighting processor.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the HTTP headers sent with every request of a server.
 *
 * Called once per server and request, when its Meilisearch client is created.
 *
 * @param array<string, string> $headers
 *   The headers, keyed by name.
 * @param \Drupal\search_api\ServerInterface|null $server
 *   The Search API server, if known.
 */
function hook_quebracho_meilisearch_request_headers_alter(array &$headers, ?\Drupal\search_api\ServerInterface $server): void {
  $headers['X-MS-USER-ID'] = 'user-' . \Drupal::currentUser()->id();
}

/**
 * Alters the Meilisearch search parameters of a Search API query.
 *
 * Called for the main search and for each extra query computing facet
 * counts (OR facets, zero counts, missing values), so a filter added here
 * restricts the facet counts too.
 *
 * @param array $params
 *   The search parameters, as documented at
 *   https://www.meilisearch.com/docs/reference/api/search.
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   The Search API query.
 * @param array $context
 *   Which query this is. The "query" key is "main", "facet_values",
 *   "facet_all_values" or "facet_missing"; facet queries also have the facet
 *   ID in "facet". Paging and facet parameters of facet queries are reset
 *   after the hook. "More like this" queries are "similar": their parameters
 *   are those of the similar documents route,
 *   https://www.meilisearch.com/docs/reference/api/similar, without "id",
 *   so there is no "q".
 */
function hook_quebracho_meilisearch_search_params_alter(array &$params, \Drupal\search_api\Query\QueryInterface $query, array $context): void {
  // Only show the current tenant's documents, in results and facet counts.
  $tenant = \Drupal::service('mymodule.tenant')->id();
  $params['filter'] = isset($params['filter']) ? "({$params['filter']}) AND tenant = $tenant" : "tenant = $tenant";
}

/**
 * Alters the documents sent to Meilisearch when items are indexed.
 *
 * Use it to send what Search API fields cannot carry, such as the vectors of
 * a userProvided embedder: set "Vectors provided by Drupal" on the server,
 * then add each document's vector under "_vectors" and the embedder's name.
 * Load the vectors of the whole batch at once: there are up to the index's
 * batch size of documents, and each vector is as large as its dimensions.
 *
 * Documents replace the stored ones: a document sent without a vector, or
 * with one that is not a list of the declared number of numbers, has none
 * afterwards (invalid vectors are logged). Implementations must not throw:
 * that would fail the whole batch.
 *
 * @param array[] $documents
 *   The documents, as built by the document converter.
 * @param \Drupal\search_api\IndexInterface $index
 *   The Search API index.
 * @param array $context
 *   - items: the items being indexed, keyed by item ID.
 *   - embedder: the name of the embedder whose vectors Drupal provides, or
 *     NULL when the server has none.
 *   - dimensions: how many numbers each of its vectors has.
 */
function hook_quebracho_meilisearch_documents_alter(array &$documents, \Drupal\search_api\IndexInterface $index, array $context): void {
  if ($context['embedder'] === NULL) {
    return;
  }
  // The IDs are item IDs, with the language ("entity:node/12:en"), not entity
  // IDs. The service returns the vectors keyed by them: a list of floats each.
  $vectors = \Drupal::service('mymodule.vectors')->getMultiple(array_column($documents, 'search_api_id'));
  foreach ($documents as &$document) {
    if (isset($vectors[$document['search_api_id']])) {
      $document['_vectors'][$context['embedder']] = $vectors[$document['search_api_id']];
    }
  }
  unset($document);
}

/**
 * @} End of "addtogroup hooks".
 */

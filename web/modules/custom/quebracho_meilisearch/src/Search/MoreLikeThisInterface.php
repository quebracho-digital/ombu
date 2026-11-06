<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Search;

use Drupal\search_api\Query\QueryInterface;

/**
 * Finds the reference items of Search API's "More like this" option.
 */
interface MoreLikeThisInterface {

  /**
   * Returns the item IDs a search_api_mlt option stands for, preferred first.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query.
   * @param array $mlt
   *   The option: "id", an item ID or an entity ID; optionally "datasource",
   *   the one datasource to look the entity ID up in.
   *
   * @return string[]
   *   The item itself; or the items of the entity of that ID in each entity
   *   datasource of the index (or the one given): in the order of the
   *   query's languages, or all its translations, the default one first.
   */
  public function referenceItemIds(QueryInterface $query, array $mlt): array;

}

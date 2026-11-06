<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;

/**
 * Converts Search API condition groups to Meilisearch filter strings.
 */
interface FilterBuilderInterface {

  /**
   * Builds a Meilisearch filter from a condition group.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $group
   *   The condition group.
   * @param \Drupal\search_api\IndexInterface $index
   *   The index being searched.
   * @param string[] $excludeTags
   *   Condition groups carrying any of these tags are left out. Used for
   *   OR facets, which must ignore their own filter.
   *
   * @return string|null
   *   The filter, or NULL if there is nothing to filter on.
   *
   * @throws \Drupal\quebracho_meilisearch\Filter\MeilisearchFilterException
   *   When a condition uses an unknown field or cannot be expressed.
   */
  public function build(ConditionGroupInterface $group, IndexInterface $index, array $excludeTags = []): ?string;

}

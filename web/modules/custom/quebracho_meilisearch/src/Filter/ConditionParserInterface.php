<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Contract for individual condition parsers.
 */
interface ConditionParserInterface {

  /**
   * Returns TRUE if this parser handles the given condition.
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool;

  /**
   * Returns a Meilisearch filter expression for the condition.
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string;

}

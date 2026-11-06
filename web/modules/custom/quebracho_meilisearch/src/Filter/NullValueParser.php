<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses null value conditions (IS NULL / IS NOT NULL).
 */
class NullValueParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return $condition->getValue() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    // Documents leave out fields without values, and never store NULL.
    $field = $condition->getField();
    if (in_array($condition->getOperator(), ['!=', '<>'], TRUE)) {
      return sprintf('(%1$s EXISTS AND NOT %1$s IS NULL)', $field);
    }
    return sprintf('(%1$s NOT EXISTS OR %1$s IS NULL)', $field);
  }

}

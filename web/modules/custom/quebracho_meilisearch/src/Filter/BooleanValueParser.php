<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses boolean value conditions.
 */
class BooleanValueParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return is_bool($condition->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $operator = $condition->getOperator() === '<>' ? '!=' : $condition->getOperator();
    return sprintf('%s %s %s', $condition->getField(), $operator, FilterValue::format($condition->getValue(), 'boolean'));
  }

}

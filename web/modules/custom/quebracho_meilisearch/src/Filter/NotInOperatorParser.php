<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses NOT IN operator conditions.
 */
class NotInOperatorParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return $condition->getOperator() === 'NOT IN' && is_array($condition->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $type = FilterValue::fieldType($index, $condition->getField());
    $values = array_map(fn($v) => FilterValue::format($v, $type), $condition->getValue());
    return sprintf('%s NOT IN [%s]', $condition->getField(), implode(', ', $values));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses BETWEEN operator conditions.
 */
class BetweenOperatorParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    $value = $condition->getValue();
    return $condition->getOperator() === 'BETWEEN' && is_array($value) && count($value) === 2;
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $type = FilterValue::fieldType($index, $condition->getField());
    [$min, $max] = array_values($condition->getValue());
    return sprintf('%s %s TO %s', $condition->getField(), FilterValue::format($min, $type), FilterValue::format($max, $type));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses scalar value conditions (=, !=, <, <=, >, >=).
 */
class ScalarValueParser implements ConditionParserInterface {

  protected const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    $value = $condition->getValue();
    $operator = $condition->getOperator();
    return !is_array($value)
      && $value !== NULL
      && !is_bool($value)
      && in_array($operator, static::OPERATORS, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = $condition->getOperator() === '<>' ? '!=' : $condition->getOperator();

    $formatted = FilterValue::format($value, FilterValue::fieldType($index, $field));

    return sprintf('%s %s %s', $field, $operator, $formatted);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Default implementation of the filter builder.
 *
 * Each condition is handed to the first condition parser (services tagged
 * "meilisearch.condition_parser", by priority) that supports it.
 */
class FilterBuilder implements FilterBuilderInterface {

  /**
   * The condition parsers, in priority order.
   *
   * @var \Drupal\quebracho_meilisearch\Filter\ConditionParserInterface[]
   */
  protected array $parsers = [];

  /**
   * Adds a condition parser. Called by the service collector.
   */
  public function addConditionParser(ConditionParserInterface $parser): void {
    $this->parsers[] = $parser;
  }

  /**
   * {@inheritdoc}
   */
  public function build(ConditionGroupInterface $group, IndexInterface $index, array $excludeTags = []): ?string {
    $parts = [];
    foreach ($group->getConditions() as $item) {
      if ($item instanceof ConditionGroupInterface) {
        if ($excludeTags && array_intersect($excludeTags, $item->getTags())) {
          continue;
        }
        $nested = $this->build($item, $index, $excludeTags);
        if ($nested !== NULL) {
          $parts[] = $nested;
        }
      }
      elseif ($item instanceof ConditionInterface) {
        $parts[] = $this->parseCondition($item, $index);
      }
    }

    if (!$parts) {
      return NULL;
    }
    if (count($parts) === 1) {
      return $parts[0];
    }
    return '(' . implode(' ' . $group->getConjunction() . ' ', $parts) . ')';
  }

  /**
   * Returns the filter expression of the first parser supporting a condition.
   *
   * @throws \Drupal\quebracho_meilisearch\Filter\MeilisearchFilterException
   */
  protected function parseCondition(ConditionInterface $condition, IndexInterface $index): string {
    $field = $condition->getField();
    if (FilterValue::fieldType($index, $field) === NULL) {
      throw new MeilisearchFilterException(sprintf('Cannot filter on "%s": the field is not indexed on index "%s".', $field, $index->id()));
    }
    foreach ($this->parsers as $parser) {
      if ($parser->supports($condition, $index)) {
        return $parser->parse($condition, $index);
      }
    }
    throw new MeilisearchFilterException(sprintf(
      'Meilisearch cannot express the condition on "%s" with operator "%s" and a value of type %s.',
      $field,
      $condition->getOperator(),
      get_debug_type($condition->getValue()),
    ));
  }

}

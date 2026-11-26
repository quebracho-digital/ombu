<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\facets\processor;

use Drupal\Core\Cache\UnchangingCacheableDependencyTrait;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\PostQueryProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Drupal\ombu\Catalog\ListLabels;

/**
 * Shows a list field's name for a value instead of the value itself.
 *
 * A list field (`field_source`, `field_activity`) stores a key and holds the
 * name in its own allowed values, so a facet needs no second list of labels
 * to keep in step: a new value is named once, in the field.
 *
 * Not a plugin itself: each facet that wants it subclasses it, names its
 * field and carries the plugin annotation.
 */
abstract class ListLabelProcessorBase extends ProcessorPluginBase implements PostQueryProcessorInterface {

  use UnchangingCacheableDependencyTrait;

  /**
   * The field whose allowed values name the facet's raw values.
   */
  abstract protected function field(): string;

  /**
   * The names, by value, read once per request.
   *
   * @var array<string, string>
   */
  private static array $names = [];

  /**
   * {@inheritdoc}
   */
  public function postQuery(FacetInterface $facet) {
    $names = $this->names();
    foreach ($facet->getResults() as $result) {
      $raw = (string) $result->getRawValue();
      $result->setDisplayValue($names[$raw] ?? $raw);
    }
  }

  /**
   * The field's allowed values, as value => name.
   *
   * @return array<string, string>
   *   The names, by value.
   */
  private function names(): array {
    $field = $this->field();
    return self::$names[$field] ??= ListLabels::of($field);
  }

}

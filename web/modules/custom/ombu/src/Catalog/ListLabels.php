<?php

declare(strict_types=1);

namespace Drupal\ombu\Catalog;

use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * The names a list field gives its values, by value.
 *
 * A list field (`field_source`, `field_activity`) stores a key and holds the
 * name in its own allowed values, so neither a facet nor the JSON of
 * /market.json needs a second list of labels to keep in step: a new value is
 * named once, in the field.
 */
final class ListLabels {

  /**
   * The names already read, by entity type and field.
   *
   * @var array<string, array<string, string>>
   */
  private static array $names = [];

  /**
   * The names of a list field's values.
   *
   * @param string $field
   *   The field name.
   * @param string $entityType
   *   The entity type the field belongs to.
   *
   * @return array<string, string>
   *   The names, by value.
   */
  public static function of(string $field, string $entityType = 'commerce_product'): array {
    $key = "$entityType.$field";
    if (!isset(self::$names[$key])) {
      self::$names[$key] = [];
      $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entityType);
      $storage = $definitions[$field] ?? NULL;
      if ($storage instanceof FieldStorageDefinitionInterface) {
        foreach ((array) $storage->getSetting('allowed_values') as $allowed) {
          if (is_array($allowed) && isset($allowed['value'], $allowed['label'])) {
            self::$names[$key][(string) $allowed['value']] = (string) $allowed['label'];
          }
        }
        // A field saved as value => label keeps the pairs in the same shape
        // only after its settings are read back through the options module.
        foreach ((array) $storage->getSetting('allowed_values') as $value => $label) {
          if (is_string($value) && is_string($label)) {
            self::$names[$key][$value] = $label;
          }
        }
      }
    }
    return self::$names[$key];
  }

  /**
   * The name of one value, or the value itself when the field has no name.
   */
  public static function one(string $field, string $value): string {
    return self::of($field)[$value] ?? $value;
  }

}

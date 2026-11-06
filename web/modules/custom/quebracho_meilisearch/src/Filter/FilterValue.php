<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\quebracho_meilisearch\Converter\DocumentConverterInterface;
use Drupal\search_api\IndexInterface;

/**
 * Formats condition values for Meilisearch filter expressions.
 */
final class FilterValue {

  /**
   * Returns the Search API type of a filterable field.
   *
   * Special fields are strings. Returns NULL for fields the index does not
   * have.
   */
  public static function fieldType(IndexInterface $index, string $field): ?string {
    if (in_array($field, DocumentConverterInterface::SPECIAL_FIELDS, TRUE)) {
      return 'string';
    }
    return $index->getField($field)?->getType();
  }

  /**
   * Formats a value as a filter literal, following the field type.
   *
   * Values are converted the same way DocumentConverter stores them: numbers
   * bare, booleans as true/false, dates as timestamps, everything else as a
   * quoted string. With an unknown type, numeric values are left bare.
   */
  public static function format(mixed $value, ?string $type): string {
    switch ($type) {
      case 'boolean':
        return self::toBool($value) ? 'true' : 'false';

      case 'integer':
      case 'decimal':
        if (is_numeric($value)) {
          return (string) (0 + $value);
        }
        break;

      case 'date':
        if (is_numeric($value)) {
          return (string) (int) $value;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp !== FALSE) {
          return (string) $timestamp;
        }
        break;

      case NULL:
        if (is_bool($value)) {
          return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
          return (string) $value;
        }
        break;
    }
    return self::quote((string) $value);
  }

  /**
   * Quotes and escapes a string literal.
   */
  public static function quote(string $value): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
  }

  /**
   * Interprets a value the way Search API's boolean data type does.
   */
  protected static function toBool(mixed $value): bool {
    if (is_string($value)) {
      return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], TRUE);
    }
    return (bool) $value;
  }

}

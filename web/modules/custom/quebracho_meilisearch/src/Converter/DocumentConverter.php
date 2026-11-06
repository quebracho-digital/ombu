<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Converter;

use Drupal\quebracho_meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Item\FieldInterface;

/**
 * Default document converter.
 */
class DocumentConverter implements DocumentConverterInterface {

  /**
   * {@inheritdoc}
   */
  public function convertToDocuments(array $items): array {
    $documents = [];
    foreach ($items as $item) {
      $doc = [
        self::PRIMARY_KEY => MeilisearchUtils::encodeDocumentId($item->getId()),
        'search_api_id' => $item->getId(),
        'search_api_datasource' => $item->getDatasourceId(),
        'search_api_language' => $item->getLanguage(),
      ];
      foreach ($item->getFields() as $field) {
        // Meilisearch reserves field names starting with "_".
        if (!str_starts_with($field->getFieldIdentifier(), '_')) {
          $this->applyField($doc, $field);
        }
      }
      $documents[] = $doc;
    }
    return $documents;
  }

  /**
   * Applies a field's values to the document array.
   */
  protected function applyField(array &$doc, FieldInterface $field): void {
    $id = $field->getFieldIdentifier();
    $type = $field->getType();
    $values = $field->getValues();

    if ($type === 'location') {
      // Meilisearch supports one _geo point per document: the first value of
      // the first location field wins.
      if (isset($doc['_geo'])) {
        return;
      }
      $first = reset($values);
      $lat = $lng = NULL;
      if (is_string($first) && str_contains($first, ',')) {
        [$lat, $lng] = array_map('trim', explode(',', $first, 2));
      }
      elseif (is_array($first) && isset($first['lat'], $first['lng'])) {
        ['lat' => $lat, 'lng' => $lng] = $first;
      }
      // Meilisearch rejects the whole batch for one invalid point: drop it.
      if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
        $doc['_geo'] = ['lat' => (float) $lat, 'lng' => (float) $lng];
      }
      return;
    }

    // Cast each value, then drop any that are NULL (e.g. unparseable dates).
    // Fields without values are left out of the document: the filter builder
    // matches "IS NULL" conditions with "NOT EXISTS".
    $converted = array_filter(
      array_map(fn($v) => $this->castValue($v, $type), $values),
      static fn($v) => $v !== NULL,
    );
    $converted = array_values($converted);
    if (count($converted) === 1) {
      $doc[$id] = $converted[0];
    }
    elseif (count($converted) > 1) {
      $doc[$id] = $converted;
    }
  }

  /**
   * Casts a single value to its Meilisearch-appropriate type.
   *
   * Returns NULL for values that cannot be safely converted (e.g. an
   * unparseable date string). Callers must treat NULL as "skip this value"
   * rather than indexing it — Meilisearch rejects documents whose fields
   * contain values incompatible with the field's declared type.
   */
  protected function castValue(mixed $value, string $type): mixed {
    return match ($type) {
      'integer' => is_numeric($value) ? (int) $value : NULL,
      'decimal' => is_numeric($value) ? (float) $value : NULL,
      'boolean' => (bool) $value,
      'date' => $this->castDate($value),
      default => (string) $value,
    };
  }

  /**
   * Casts a value to a Unix timestamp, returning NULL if it can't be parsed.
   */
  protected function castDate(mixed $value): ?int {
    if (is_numeric($value)) {
      return (int) $value;
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === FALSE ? NULL : $timestamp;
  }

}

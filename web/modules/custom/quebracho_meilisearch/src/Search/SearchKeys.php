<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Search;

/**
 * Converts Search API search keys into a Meilisearch query string.
 *
 * Meilisearch has no boolean operators: nested groups are flattened into
 * words, multi-word keys become phrases, and negated keys use "-". How many
 * words must match is controlled by the server's matching strategy.
 */
final class SearchKeys {

  /**
   * Converts keys as returned by QueryInterface::getKeys().
   *
   * @param string|array|null $keys
   *   The keys: NULL, a plain string (passed through, so Meilisearch syntax
   *   such as phrases and "-" works), or parsed keys.
   */
  public static function toMeilisearch(string|array|null $keys): string {
    if ($keys === NULL) {
      return '';
    }
    if (is_string($keys)) {
      return trim($keys);
    }
    return implode(' ', self::terms($keys, FALSE));
  }

  /**
   * Flattens parsed keys into Meilisearch terms.
   *
   * @return string[]
   *   The terms.
   */
  protected static function terms(array $keys, bool $negated): array {
    $negated = $negated || !empty($keys['#negation']);
    $terms = [];
    foreach ($keys as $key => $value) {
      if (is_string($key) && str_starts_with($key, '#')) {
        continue;
      }
      if (is_array($value)) {
        array_push($terms, ...self::terms($value, $negated));
        continue;
      }
      $value = trim((string) $value);
      if ($value === '') {
        continue;
      }
      if (preg_match('/\s/', $value)) {
        $value = '"' . str_replace('"', '', $value) . '"';
      }
      $terms[] = ($negated ? '-' : '') . $value;
    }
    return $terms;
  }

}

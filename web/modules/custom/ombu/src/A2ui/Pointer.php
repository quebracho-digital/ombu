<?php

declare(strict_types=1);

namespace Drupal\ombu\A2ui;

/**
 * JSON Pointer (RFC 6901) over a PHP array: the data model's addresses.
 *
 * A2UI binds a component to `{"@path": "/contact/email"}`. Only absolute
 * pointers are resolved here; relative ones (inside a template's scope) are
 * not part of this first cut and read as missing.
 */
final class Pointer {

  /**
   * Deepest pointer taken: a seller's path is not a way to burn CPU.
   */
  private const MAX_TOKENS = 32;

  /**
   * Splits a pointer into its unescaped tokens; NULL if it is not absolute.
   *
   * @return string[]|null
   *   The tokens: none for the root ("" or "/").
   */
  public static function tokens(string $path): ?array {
    if ($path === '' || $path === '/') {
      return [];
    }
    if ($path[0] !== '/') {
      return NULL;
    }
    $tokens = explode('/', substr($path, 1));
    if (count($tokens) > self::MAX_TOKENS) {
      return NULL;
    }
    // "~1" first, then "~0": the order RFC 6901 gives, so "~01" stays "~1".
    return array_map(static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token), $tokens);
  }

  /**
   * Reads the value at a pointer; NULL when it is not there.
   */
  public static function get(array $data, string $path): mixed {
    $tokens = self::tokens($path);
    if ($tokens === NULL) {
      return NULL;
    }
    $node = $data;
    foreach ($tokens as $token) {
      if (!is_array($node) || !array_key_exists($token, $node)) {
        return NULL;
      }
      $node = $node[$token];
    }
    return $node;
  }

  /**
   * Writes a value at a pointer; NULL removes the key (A2UI v1.0).
   *
   * @throws \Drupal\ombu\A2ui\A2uiException
   *   If the pointer is not absolute, or the root is replaced by a scalar.
   */
  public static function set(array &$data, string $path, mixed $value): void {
    $tokens = self::tokens($path);
    if ($tokens === NULL) {
      throw new A2uiException('A data model path must be an absolute JSON Pointer.');
    }
    if ($tokens === []) {
      if ($value !== NULL && !is_array($value)) {
        throw new A2uiException('The data model root must be an object.');
      }
      $data = $value ?? [];
      return;
    }
    $node = &$data;
    $last = array_pop($tokens);
    foreach ($tokens as $token) {
      if (!isset($node[$token]) || !is_array($node[$token])) {
        if ($value === NULL) {
          // Nothing there to remove.
          return;
        }
        $node[$token] = [];
      }
      $node = &$node[$token];
    }
    if ($value === NULL) {
      unset($node[$last]);
      return;
    }
    $node[$last] = $value;
  }

}

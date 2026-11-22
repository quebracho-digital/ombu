<?php

declare(strict_types=1);

namespace Drupal\ombu\A2ui;

/**
 * The basic catalog's functions that only compute: no side effects, no I/O.
 *
 * `openUrl` and the like are not here and never will be: a payload does not
 * get to open anything. The Renderer resolves each argument (a literal, a
 * path or another call) and hands the values over.
 */
final class Functions {

  /**
   * Longest string a function returns.
   */
  private const MAX_LENGTH = 5000;

  /**
   * The functions this renderer runs.
   */
  public const NAMES = ['formatNumber', 'formatCurrency', 'pluralize', '@index'];

  /**
   * Runs one function on resolved arguments.
   *
   * @param string $name
   *   One of self::NAMES.
   * @param array<string, mixed> $args
   *   The arguments, already resolved.
   * @param int|null $index
   *   The current item's index when drawing a template, otherwise NULL.
   *
   * @return mixed
   *   The result; NULL when the arguments do not fit.
   */
  public static function call(string $name, array $args, ?int $index): mixed {
    return match ($name) {
      'formatNumber' => self::number($args['value'] ?? NULL, $args['decimals'] ?? NULL, $args['grouping'] ?? TRUE),
      'formatCurrency' => self::currency($args),
      'pluralize' => self::pluralize($args),
      '@index' => $index === NULL ? NULL : $index + (is_numeric($args['offset'] ?? NULL) ? (int) $args['offset'] : 0),
      default => NULL,
    };
  }

  /**
   * Interpolates `${/path}` and `${relative/path}` in a string.
   *
   * A function call inside `${}` is not run: it reads as empty and its name
   * is added to $skipped. `\${` is a literal `${`.
   *
   * @param string $template
   *   The text with its expressions.
   * @param callable(string): mixed $resolve
   *   Reads a path from the data model.
   * @param string[] $skipped
   *   Receives the expressions that were not run.
   */
  public static function interpolate(string $template, callable $resolve, array &$skipped = []): string {
    $out = preg_replace_callback('/(\\\\\$\{)|\$\{([^}]*)\}/', static function (array $match) use ($resolve, &$skipped): string {
      if ($match[1] !== '') {
        return '${';
      }
      $expression = trim($match[2]);
      if ($expression === '' || !preg_match('#^[A-Za-z0-9_./~-]+$#', $expression)) {
        $skipped[] = 'expression';
        return '';
      }
      $value = $resolve($expression);
      if (is_bool($value)) {
        return $value ? 'true' : 'false';
      }
      return is_scalar($value) ? (string) $value : '';
    }, $template);
    return mb_substr((string) $out, 0, self::MAX_LENGTH);
  }

  /**
   * Formats a number with a decimal point and, by default, thousands commas.
   */
  private static function number(mixed $value, mixed $decimals, mixed $grouping): ?string {
    if (!is_numeric($value)) {
      return NULL;
    }
    $number = (float) $value;
    $places = is_numeric($decimals) ? max(0, min(10, (int) $decimals)) : (floor($number) === $number ? 0 : 2);
    return number_format($number, $places, '.', $grouping === FALSE ? '' : ',');
  }

  /**
   * Formats an amount with its currency code after it: "1,234.50 USDC".
   *
   * @param array<string, mixed> $args
   *   Resolved arguments: value, currency, decimals, grouping.
   */
  private static function currency(array $args): ?string {
    $code = $args['currency'] ?? NULL;
    if (!is_string($code) || !preg_match('/^[A-Z]{3,5}$/', $code)) {
      return NULL;
    }
    $amount = self::number($args['value'] ?? NULL, $args['decimals'] ?? 2, $args['grouping'] ?? TRUE);
    return $amount === NULL ? NULL : $amount . ' ' . $code;
  }

  /**
   * Picks the English plural form: zero (if given), one, or other.
   *
   * @param array<string, mixed> $args
   *   Resolved arguments: value, zero, one, other.
   */
  private static function pluralize(array $args): ?string {
    $count = $args['value'] ?? NULL;
    if (!is_numeric($count) || !is_string($args['other'] ?? NULL)) {
      return NULL;
    }
    $key = match (TRUE) {
      (float) $count === 0.0 && is_string($args['zero'] ?? NULL) => 'zero',
      (float) $count === 1.0 && is_string($args['one'] ?? NULL) => 'one',
      default => 'other',
    };
    return mb_substr($args[$key], 0, self::MAX_LENGTH);
  }

}

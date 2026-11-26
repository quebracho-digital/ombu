<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Symfony\Component\Yaml\Yaml;

/**
 * The one operation of an OpenAPI spec that documents an endpoint.
 */
final class OpenApi {

  /**
   * How deep $refs are followed: enough for request and response bodies.
   */
  private const DEPTH = 6;

  /**
   * How many nodes one operation may expand to.
   *
   * A schema with K properties that each point back to it grows as K^6; a
   * spec of a few hundred bytes can exhaust memory. Past this budget the
   * $ref is returned unexpanded instead of inlined.
   */
  private const MAX_NODES = 10000;

  /**
   * A spec as an array, from JSON or YAML; NULL if it is neither.
   */
  public static function parse(string $body): ?array {
    $spec = json_decode($body, TRUE);
    if (!is_array($spec)) {
      try {
        $spec = Yaml::parse($body);
      }
      catch (\Exception) {
        return NULL;
      }
    }
    return is_array($spec) && isset($spec['paths']) && is_array($spec['paths']) ? $spec : NULL;
  }

  /**
   * The operation for this method and URL, with its $refs inlined.
   *
   * A spec path matches the end of the URL's path, so a spec written
   * relative to a base path ("/v1") still matches; {templates} match one
   * segment. The longest matching path wins.
   */
  public static function operation(array $spec, string $url, string $method): ?array {
    $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/') ?: '/';
    $method = strtolower($method);
    $found = NULL;
    foreach ($spec['paths'] as $template => $operations) {
      if (!is_array($operations) || !is_array($operations[$method] ?? NULL)) {
        continue;
      }
      $pattern = preg_replace('~\\\\\{[^/]+?\\\\\}~', '[^/]+', preg_quote(rtrim((string) $template, '/'), '~'));
      if (preg_match("~$pattern\$~", $path) && strlen((string) $template) > strlen((string) $found)) {
        $found = (string) $template;
      }
    }
    if ($found === NULL) {
      return NULL;
    }
    // Parameters shared by every method of the path belong to it too.
    $operation = $spec['paths'][$found][$method];
    $shared = $spec['paths'][$found]['parameters'] ?? [];
    if (is_array($shared) && $shared) {
      $operation['parameters'] = array_merge($shared, $operation['parameters'] ?? []);
    }
    $nodes = 0;
    return ['path' => $found] + self::inline($operation, $spec, self::DEPTH, $nodes);
  }

  /**
   * Replaces local $refs ("#/components/...") with what they point to.
   */
  private static function inline(mixed $node, array $spec, int $depth, int &$nodes): mixed {
    if (!is_array($node)) {
      return $node;
    }
    if (++$nodes > self::MAX_NODES) {
      // Past the budget: return the reference (or the raw subtree) without
      // expanding it. The caller keeps a bounded operation, not a fan-out.
      return isset($node['$ref']) && is_string($node['$ref']) ? ['$ref' => $node['$ref']] : $node;
    }
    if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/')) {
      if ($depth === 0) {
        return ['$ref' => $node['$ref']];
      }
      $target = $spec;
      foreach (explode('/', substr($node['$ref'], 2)) as $key) {
        $key = str_replace(['~1', '~0'], ['/', '~'], $key);
        if (!is_array($target) || !array_key_exists($key, $target)) {
          return $node;
        }
        $target = $target[$key];
      }
      return self::inline($target, $spec, $depth - 1, $nodes);
    }
    foreach ($node as $key => $value) {
      $node[$key] = self::inline($value, $spec, $depth, $nodes);
    }
    return $node;
  }

}

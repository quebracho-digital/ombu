<?php

declare(strict_types=1);

namespace Drupal\ombu;

/**
 * What the share block (ShareBlock) shares: the address and its links.
 *
 * Plain links to each network's own share page: nothing is loaded from them
 * until a person follows one, so the page has no third-party script and no
 * tracker.
 */
final class Share {

  /**
   * The /market query parameters that make a subset worth sharing: its
   * exposed filters. Anything else (page, utm_*, a session's leftovers) is
   * not part of the subset and stays out of the address.
   */
  public const FILTERS = ['category', 'network', 'payment', 'provider', 'search', 'seller', 'tag'];

  private const MAX_VALUE = 200;

  /**
   * The filters of a /market query, cleaned and in a fixed order.
   *
   * @param array $query
   *   The request's query.
   *
   * @return array<string, string|string[]>
   *   Only FILTERS with a value; a value is a string or a list of strings.
   */
  public static function marketQuery(array $query): array {
    $clean = [];
    foreach (self::FILTERS as $name) {
      $value = $query[$name] ?? NULL;
      if (is_array($value)) {
        $value = array_values(array_filter(array_map(fn($v) => self::scalar($v), $value), fn($v) => $v !== ''));
        if ($value) {
          $clean[$name] = $value;
        }
      }
      elseif (($value = self::scalar($value)) !== '') {
        $clean[$name] = $value;
      }
    }
    return $clean;
  }

  /**
   * The share link of each network for an address and its text.
   *
   * @return array<string, string>
   *   Network id => address to follow.
   */
  public static function links(string $url, string $text): array {
    $u = rawurlencode($url);
    $t = rawurlencode($text);
    $both = rawurlencode($text . ' ' . $url);
    return [
      'bluesky' => "https://bsky.app/intent/compose?text=$both",
      'mastodon' => "https://mastodon.social/share?text=$both",
      'x' => "https://x.com/intent/post?text=$t&url=$u",
      'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url=$u",
      'whatsapp' => "https://wa.me/?text=$both",
      'telegram' => "https://t.me/share/url?url=$u&text=$t",
      'email' => "mailto:?subject=$t&body=$u",
    ];
  }

  /**
   * A preview description from a field's markup: text only, one line, cut at
   * a word with an ellipsis.
   */
  public static function description(string $markup, int $max = 200): string {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\\b.*?</\\1>#is', '', $markup)), ENT_QUOTES | ENT_HTML5)));
    if (mb_strlen($text) <= $max) {
      return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space !== FALSE && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
  }

  /**
   * A request value as a short trimmed string; '' when it is not a scalar.
   */
  private static function scalar(mixed $value): string {
    return is_string($value) || is_int($value) ? mb_substr(trim((string) $value), 0, self::MAX_VALUE) : '';
  }

}

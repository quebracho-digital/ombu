<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Component\Utility\Unicode;

/**
 * Text from sellers and visitors, made safe to put in the markdown we write.
 */
final class Markdown {

  /**
   * A seller's title as the text of a markdown link, as long as on a card.
   *
   * Or any line of theirs, longer.
   * No brackets to break the link, no line breaks or control characters to
   * start a block, no HTML or code spans for the reader to interpret.
   */
  public static function linkText(string $title, int $max = 90): string {
    $title = preg_replace('/[\p{Cc}\s]+/u', ' ', $title);
    // A backslash would escape the closing bracket.
    $title = strtr($title, ['[' => '(', ']' => ')', '<' => '‹', '>' => '›', '`' => "'", '\\' => '/']);
    return Unicode::truncate(trim($title), $max, TRUE, TRUE);
  }

}

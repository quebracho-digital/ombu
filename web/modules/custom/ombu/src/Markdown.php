<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ombu\Controller\CatalogueController;
use Drupal\ombu\EventSubscriber\ListingMarkdownSubscriber;
use Drupal\x402_pay\Delivery;

/**
 * The markdown we write: where a page reads as it, and seller text in it.
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

  /**
   * Where a route's page reads as markdown, free; NULL if it does not.
   *
   * The market answers its own .md (AgentLinkSubscriber), with the search;
   * a listing too (ListingMarkdownSubscriber); any other page, x402_pay.
   */
  public static function url(RouteMatchInterface $route_match, CacheableMetadata $cacheability, array $query = []): ?string {
    $cacheability->addCacheContexts(['route']);
    return $route_match->getRouteName() === 'view.market.page_1'
      ? CatalogueController::marketMd($query)
      : ListingMarkdownSubscriber::markdownUrl($route_match, $cacheability) ?? Delivery::markdownUrl($route_match, $cacheability);
  }

}

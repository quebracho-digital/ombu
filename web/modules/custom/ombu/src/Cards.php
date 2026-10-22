<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\Product;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Render\Markup;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\Entity\User;

/**
 * Listings as small cards: a seller's listings, the similar ones.
 *
 * Belgrade's teaser is laid out for /market only. A card's picture is the
 * listing's own image, else its seller's account picture (the account whose
 * wallet it pays), else its provider's initial on a color of their own.
 * As a slider, two cards show and the rest scroll sideways (cards.js).
 * The markup is in ombu-cards.html.twig and ombu-card.html.twig.
 */
final class Cards {

  /**
   * A titled list of cards, in the order of $ids.
   *
   * Those the visitor may not see are left out; empty when none is left.
   */
  public static function build(array $ids, string $title, bool $provider = TRUE, bool $slider = FALSE): array {
    $cache = ['tags' => ['commerce_product_list', 'comment_list', 'user_list']];
    $cards = [];
    foreach (Product::loadMultiple($ids) as $listing) {
      if ($listing->access('view')) {
        $cards[] = self::card($listing, $provider);
      }
    }
    if (!$cards) {
      return ['#cache' => $cache];
    }
    return [
      '#theme' => 'ombu_cards',
      '#title' => $title,
      '#cards' => $cards,
      '#slider' => $slider,
      '#attached' => ['library' => ['ombu/cards']],
      '#cache' => $cache,
    ];
  }

  /**
   * One card.
   */
  private static function card(Product $listing, bool $show_provider): array {
    $provider = (string) $listing->get('field_provider_term')->entity?->label();
    $price = $listing->getDefaultVariation()?->getPrice();
    $rating = Rating::of($listing);
    // The card's hue comes from the provider's name: the same provider
    // keeps its color on every page.
    $name = $provider ?: (string) $listing->label();
    // What it does, when it says more than the title.
    $summary = Unicode::truncate(trim(strip_tags((string) $listing->get('body')->value)), 160, TRUE, TRUE);
    if (str_starts_with(mb_strtolower((string) $listing->label()), mb_strtolower(rtrim($summary, '….')))) {
      $summary = '';
    }
    return [
      '#theme' => 'ombu_card',
      '#url' => $listing->toUrl()->toString(),
      '#title' => Unicode::truncate((string) $listing->label(), 90, TRUE, TRUE),
      '#summary' => $summary,
      '#logo' => self::logo($listing),
      '#initial' => mb_strtoupper(mb_substr($name, 0, 1)),
      '#hue' => hexdec(substr(md5($name), 0, 4)) % 360,
      '#provider' => $show_provider ? $provider : '',
      '#category' => (string) $listing->get('field_category')->entity?->label(),
      '#price' => $price ? \Drupal::service('commerce_price.currency_formatter')->format($price->getNumber(), $price->getCurrencyCode(), [
        'minimum_fraction_digits' => 0,
        'maximum_fraction_digits' => 6,
      ]) : '',
      // Rating::stars() prints only stars and a translated label.
      '#stars' => $rating ? Markup::create(Rating::stars($rating['average'])) : NULL,
      '#reviews' => $rating['count'] ?? 0,
      '#chains' => array_values(array_unique(array_map(fn ($n) => Chains::name((string) $n['value']), $listing->get('field_network')->getValue()))),
    ];
  }

  /**
   * The URL of the card's picture, or NULL for the initial.
   */
  private static function logo(Product $listing): ?string {
    $file = $listing->hasField('images') ? $listing->get('images')->entity : NULL;
    if (!$file && ($uid = \Drupal::service('ombu.review_verifier')->sellerOf($listing))) {
      $file = User::load($uid)?->get('user_picture')->entity;
    }
    if (!$file) {
      return NULL;
    }
    // Thumbnail scales to 100 px, keeping a logo's proportions.
    return ImageStyle::load('thumbnail')?->buildUrl($file->getFileUri())
      ?? \Drupal::service('file_url_generator')->generateString($file->getFileUri());
  }

}

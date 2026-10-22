<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\Product;
use Drupal\Component\Utility\Html;
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
 */
final class Cards {

  /**
   * A titled grid of cards, in the order of $ids.
   *
   * Those the visitor may not see are left out; empty when none is left.
   */
  public static function build(array $ids, string $title, bool $provider = TRUE, bool $slider = FALSE): array {
    $cache = ['tags' => ['commerce_product_list', 'comment_list', 'user_list']];
    $cards = [];
    foreach (Product::loadMultiple($ids) as $listing) {
      if ($listing->access('view')) {
        // Markup: every value in it is escaped, and Xss::filterAdmin() would
        // drop the avatar's style.
        $cards[] = ['#markup' => Markup::create(self::card($listing, $provider))];
      }
    }
    if (!$cards) {
      return ['#cache' => $cache];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => $slider ? ['ombu-cards-block', 'ombu-cards-block--slider'] : ['ombu-cards-block']],
      '#attached' => ['library' => ['ombu/cards']],
      '#cache' => $cache,
      'title' => ['#markup' => '<h2>' . Html::escape($title) . '</h2>'],
      // Buttons for a pointer; the list scrolls by keyboard and touch too.
      'nav' => $slider ? [
        '#markup' => Markup::create(sprintf(
        '<div class="ombu-cards__nav"><button type="button" class="ombu-cards__prev" aria-label="%s">‹</button><button type="button" class="ombu-cards__next" aria-label="%s">›</button></div>',
        t('Previous'),
        t('Next'),
        )),
      ] : [],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $cards,
        '#attributes' => $slider
          ? ['class' => ['ombu-cards', 'ombu-cards--slider'], 'tabindex' => 0, 'aria-label' => $title]
          : ['class' => ['ombu-cards']],
      ],
    ];
  }

  /**
   * One card's markup.
   */
  private static function card(Product $listing, bool $show_provider): string {
    $provider = (string) $listing->get('field_provider_term')->entity?->label();
    $category = (string) $listing->get('field_category')->entity?->label();
    $price = $listing->getDefaultVariation()?->getPrice();
    $rating = Rating::of($listing);
    $chains = array_map(fn ($n) => Chains::name((string) $n['value']), $listing->get('field_network')->getValue());

    // The card's hue comes from the provider's name: the same provider
    // keeps its color on every page.
    $name = $provider ?: (string) $listing->label();
    $hue = hexdec(substr(md5($name), 0, 4)) % 360;
    $logo = self::logo($listing);
    $avatar = $logo
      ? sprintf('<img class="ombu-card__avatar ombu-card__avatar--logo" src="%s" alt="" width="40" height="40" loading="lazy">', Html::escape($logo))
      : sprintf('<span class="ombu-card__avatar" aria-hidden="true">%s</span>', Html::escape(mb_strtoupper(mb_substr($name, 0, 1))));

    $pills = [];
    if ($show_provider && $provider) {
      $pills[] = '<span class="ombu-card__pill">' . Html::escape($provider) . '</span>';
    }
    if ($category) {
      $pills[] = '<span class="ombu-card__pill ombu-card__pill--muted">' . Html::escape($category) . '</span>';
    }

    // What it does, when it says more than the title.
    $summary = Unicode::truncate(trim(strip_tags((string) $listing->get('body')->value)), 160, TRUE, TRUE);
    if (str_starts_with(mb_strtolower((string) $listing->label()), mb_strtolower(rtrim($summary, '….')))) {
      $summary = '';
    }

    $meta = [];
    if ($price) {
      $formatted = \Drupal::service('commerce_price.currency_formatter')->format($price->getNumber(), $price->getCurrencyCode(), [
        'minimum_fraction_digits' => 0,
        'maximum_fraction_digits' => 6,
      ]);
      $meta[] = '<strong class="ombu-card__price">' . $formatted . '</strong>';
    }
    $meta[] = $rating
      ? Rating::stars($rating['average']) . ' <small>(' . $rating['count'] . ')</small>'
      : '<small>' . t('No reviews yet') . '</small>';
    if ($chains) {
      $meta[] = '<small class="ombu-card__chains">' . Html::escape(implode(' · ', array_unique($chains))) . '</small>';
    }

    return '<article class="ombu-card" style="--ombu-hue:' . $hue . '">'
      . '<header class="ombu-card__head">' . $avatar
      . $listing->toLink(Unicode::truncate((string) $listing->label(), 90, TRUE, TRUE), 'canonical', [
        'attributes' => ['class' => ['ombu-card__title', 'stretched-link']],
      ])->toString()
      . '</header>'
      . ($summary ? '<p class="ombu-card__summary">' . Html::escape($summary) . '</p>' : '')
      . ($pills ? '<p class="ombu-card__pills">' . implode('', $pills) . '</p>' : '')
      . '<p class="ombu-card__meta">' . implode(' ', $meta) . '</p>'
      . '</article>';
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

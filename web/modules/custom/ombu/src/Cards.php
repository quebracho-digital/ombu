<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\Product;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Render\Markup;

/**
 * Listings as small cards: a seller's listings, the similar ones.
 *
 * Belgrade's teaser is laid out for /market only. No listing has an image,
 * so a card shows its provider's initial on a color of their own.
 */
final class Cards {

  /**
   * A titled grid of cards, in the order of $ids.
   *
   * Those the visitor may not see are left out; empty when none is left.
   */
  public static function build(array $ids, string $title, bool $provider = TRUE): array {
    $cache = ['tags' => ['commerce_product_list', 'comment_list']];
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
      '#attributes' => ['class' => ['ombu-cards-block']],
      '#attached' => ['library' => ['ombu/cards']],
      '#cache' => $cache,
      'title' => ['#markup' => '<h2>' . Html::escape($title) . '</h2>'],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $cards,
        '#attributes' => ['class' => ['ombu-cards']],
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

    // The avatar's hue comes from the provider's name: the same provider
    // keeps its color on every page.
    $name = $provider ?: (string) $listing->label();
    $hue = hexdec(substr(md5($name), 0, 4)) % 360;
    $avatar = sprintf('<span class="ombu-card__avatar" style="--ombu-hue:%d" aria-hidden="true">%s</span>', $hue, Html::escape(mb_strtoupper(mb_substr($name, 0, 1))));

    $pills = [];
    if ($show_provider && $provider) {
      $pills[] = '<span class="ombu-card__pill">' . Html::escape($provider) . '</span>';
    }
    if ($category) {
      $pills[] = '<span class="ombu-card__pill ombu-card__pill--muted">' . Html::escape($category) . '</span>';
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

    return '<article class="ombu-card">'
      . '<header class="ombu-card__head">' . $avatar
      . $listing->toLink(Unicode::truncate((string) $listing->label(), 90, TRUE, TRUE), 'canonical', [
        'attributes' => ['class' => ['ombu-card__title', 'stretched-link']],
      ])->toString()
      . '</header>'
      . ($pills ? '<p class="ombu-card__pills">' . implode('', $pills) . '</p>' : '')
      . '<p class="ombu-card__meta">' . implode(' ', $meta) . '</p>'
      . '</article>';
  }

}

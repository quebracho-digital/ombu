<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\ombu\Rating;
use Drupal\ombu\ReviewVerifier;
use Drupal\ombu\Sales;
use Drupal\user\UserInterface;
use Drupal\x402_pay\Networks;

/**
 * A seller's page is their account page: who they are, their reputation
 * over every listing, their wallets, listings and latest sales.
 *
 * Public for sellers only: a buyer's account stays private.
 */
final class SellerPageHooks {

  use StringTranslationTrait;

  public function __construct(private readonly ReviewVerifier $verifier) {}

  /**
   * Anyone may see the page of an account that sells; so "Submitted by"
   * under a seller's reply links to it.
   */
  #[Hook('user_access')]
  public function access(UserInterface $account, string $operation, AccountInterface $viewer): AccessResultInterface {
    // ponytail: tagged by listings only; a wallet linked later shows once
    // a listing changes or the cache is cleared.
    return AccessResult::allowedIf(in_array($operation, ['view', 'view linked label'], TRUE) && $account->isActive() && $this->verifier->listingsOf((int) $account->id()))
      ->addCacheableDependency($account)
      ->addCacheTags(['commerce_product_list']);
  }

  #[Hook('user_view')]
  public function view(array &$build, UserInterface $account, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($view_mode !== 'full' || !($ids = $this->verifier->listingsOf((int) $account->id()))) {
      return;
    }
    $build['#attached']['library'][] = 'ombu/seller';
    $build['#cache']['tags'] = [...$build['#cache']['tags'] ?? [], 'commerce_product_list', 'comment_list'];

    $rating = Rating::ofListings($ids);
    $build['ombu_reputation'] = [
      '#markup' => '<p class="seller-reputation">' . ($rating
        ? Rating::stars($rating['average']) . ' ' . $this->formatPlural($rating['count'], '@avg from 1 verified review', '@avg from @count verified reviews', ['@avg' => $rating['average']])
        : $this->t('No reviews yet')) . '</p>',
      '#weight' => -20,
    ];

    // The wallets their listings pay: what proves the listings are theirs.
    $network = (string) FieldConfig::loadByName('commerce_product', 'listing', 'field_x402_price')?->getSetting('network');
    $explorer = isset(Networks::ALL[$network]['explorer']) ? str_replace('/tx/', '/address/', Networks::ALL[$network]['explorer']) : NULL;
    $wallets = array_map(fn (string $w) => $explorer
      ? ['#type' => 'link', '#title' => substr($w, 0, 6) . '…' . substr($w, -4), '#url' => Url::fromUri($explorer . $w), '#attributes' => ['title' => $w]]
      : ['#plain_text' => $w], $this->verifier->wallets((int) $account->id()));
    $build['ombu_wallets'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Paid to'),
      '#items' => $wallets,
      '#attributes' => ['class' => ['seller-wallets']],
      '#weight' => 10,
    ];

    // Our own small cards: Belgrade's teaser is laid out for /market only.
    $cards = [];
    foreach (Product::loadMultiple($ids) as $listing) {
      if (!$listing->access('view')) {
        continue;
      }
      $price = $listing->getDefaultVariation()?->getPrice();
      $stars = Rating::of($listing);
      $cards[] = [
        'title' => $listing->toLink()->toRenderable() + ['#attributes' => ['class' => ['seller-listing__title']]],
        'meta' => ['#markup' => '<span class="seller-listing__meta">'
          . ($price ? '<strong>' . \Drupal::service('commerce_price.currency_formatter')->format($price->getNumber(), $price->getCurrencyCode(), ['minimum_fraction_digits' => 0, 'maximum_fraction_digits' => 6]) . '</strong> ' : '')
          . ($stars ? Rating::stars($stars['average']) . ' (' . $stars['count'] . ')' : '') . '</span>'],
      ];
    }
    $build['ombu_listings'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Listings'),
      '#items' => $cards,
      '#attributes' => ['class' => ['seller-listings']],
      '#weight' => 20,
    ];
    $build['ombu_sales'] = Sales::build($ids) + ['#weight' => 30];
  }

}

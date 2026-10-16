<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\views\Views;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\Signer;

/**
 * The market's sales on chain, from x402_pay's log: proof it trades.
 *
 * Only settled sales on the network the site charges (Arc mainnet in
 * production): a testnet sale is not money.
 */
final class Sales {

  /**
   * Invalidated on every settled sale (SellerSubscriber::onPayment()).
   */
  public const TAG = 'ombu_sales';

  /**
   * The latest sales, of one listing, of several (a seller's), or of the
   * whole market.
   *
   * The market's also shows totals: sales, USDC moved, listings.
   */
  public static function build(ProductInterface|array|null $listing = NULL): array {
    if ($listing instanceof ProductInterface) {
      $listing = [(int) $listing->id()];
    }
    $network = (string) FieldConfig::loadByName('commerce_product', 'listing', 'field_x402_price')?->getSetting('network');
    $cache = ['tags' => [self::TAG, 'commerce_product_list', 'config:x402_pay.settings']];
    // The list is the ombu_sales view, so admins can change it (and place
    // its blocks elsewhere, or link its page /market/sales).
    $view = Views::getView('ombu_sales');
    $args = $listing ? [$network, implode('+', $listing)] : [$network];
    $list = $view?->buildRenderable($listing ? 'listing' : 'latest', $args);
    if ($listing && $view) {
      $view->setDisplay('listing');
      $view->setArguments($args);
      $view->execute();
      if (!$view->result) {
        return ['#cache' => $cache];
      }
    }
    $explorer = Networks::ALL[$network]['explorer'] ?? NULL;

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ombu-sales']],
      '#attached' => ['library' => ['ombu/sales']],
      '#cache' => $cache,
      'title' => ['#markup' => '<h2 class="ombu-sales__title">' . t('Latest sales on chain') . '</h2>'],
    ];
    if (!$listing) {
      $totals = \Drupal::database()->select('x402_pay_log', 'l')->condition('status', 'settled')->condition('network', $network);
      $totals->addExpression('COUNT(*)', 'n');
      // ponytail: USDC's 6 decimals summed in SQL; amounts are varchar,
      // fine until one sale passes 2^53 atomic units.
      $totals->addExpression('SUM(amount)', 'sum');
      $totals = $totals->execute()->fetchAssoc();
      $count = \Drupal::entityQuery('commerce_product')->accessCheck(TRUE)->condition('type', 'listing')->condition('status', 1)->count()->execute();
      $build['stats'] = [
        '#theme' => 'item_list',
        '#attributes' => ['class' => ['ombu-sales__stats']],
        '#items' => [
          ['#markup' => '<strong>' . (int) $totals['n'] . '</strong> ' . t('sales')],
          ['#markup' => '<strong>' . self::usdc((string) ($totals['sum'] ?? '0')) . '</strong> ' . t('USDC moved')],
          ['#markup' => '<strong>' . $count . '</strong> ' . t('listings')],
        ],
      ];
    }
    $build['list'] = $list ?? [];
    $build['all'] = $listing ? [] : ['#markup' => '<p class="ombu-sales__all"><a href="' . Url::fromRoute('view.ombu_sales.page')->toString() . '">' . t('All sales') . '</a></p>'];
    $note = t('Paid in USDC on @chain; each sale links to its transaction.', ['@chain' => Chains::name($network)]);
    if ($explorer && ($settler = self::settler($network))) {
      // Who sent them on chain: anyone can check a sale went through Ombú.
      $note .= ' ' . t('All settled by Ombú from <a href=":url">@address</a>.', [
        ':url' => str_replace('/tx/', '/address/', $explorer) . $settler,
        '@address' => substr($settler, 0, 6) . '…' . substr($settler, -4),
      ]);
    }
    $build['note'] = ['#markup' => '<p class="ombu-sales__note"><small>' . $note . '</small></p>'];
    return $build;
  }

  /**
   * The gas wallet that sends our sales on chain; NULL when Circle does.
   */
  private static function settler(string $network): ?string {
    $settings = \Drupal::config('x402_pay.settings')->get('facilitator') ?? [];
    if (!in_array($network, $settings['own_networks'] ?? [], TRUE)) {
      return NULL;
    }
    $key = \Drupal::service('key.repository')->getKey((string) ($settings['own_key_id'] ?? ''));
    try {
      return $key ? (new Signer(trim((string) $key->getKeyValue())))->address() : NULL;
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Atomic units (6 decimals) as USDC, without trailing zeros.
   */
  public static function usdc(string $atomic): string {
    $whole = intdiv((int) $atomic, 1000000);
    $fraction = rtrim(str_pad((string) ((int) $atomic % 1000000), 6, '0', STR_PAD_LEFT), '0');
    return $fraction === '' ? (string) $whole : "$whole.$fraction";
  }

}

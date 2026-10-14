<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;

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
   * The latest sales, of one listing or of the whole market.
   *
   * The market's also shows totals: sales, USDC moved, listings.
   */
  public static function build(?ProductInterface $listing = NULL, int $limit = 5): array {
    $network = (string) FieldConfig::loadByName('commerce_product', 'listing', 'field_x402_price')?->getSetting('network');
    $db = \Drupal::database();
    $query = $db->select('x402_pay_log', 'l')
      ->condition('status', 'settled')
      ->condition('network', $network);
    if ($listing) {
      // Bought at its agent door or at its own URL (canonical charging).
      $query->condition($query->orConditionGroup()
        ->condition('resource', '%/commerce_product/' . $listing->id() . '/%', 'LIKE')
        ->condition('resource', '%' . $db->escapeLike('/product/' . $listing->id()), 'LIKE'));
    }
    $totals = (clone $query);
    $totals->addExpression('COUNT(*)', 'n');
    // ponytail: USDC's 6 decimals summed in SQL; amounts are varchar, fine
    // until one sale passes 2^53 atomic units.
    $totals->addExpression('SUM(amount)', 'sum');
    $totals = $totals->execute()->fetchAssoc();
    $rows = $query->fields('l', ['created', 'resource', 'amount', 'transaction'])
      ->orderBy('id', 'DESC')->range(0, $limit)->execute()->fetchAll();

    $cache = ['tags' => [self::TAG, 'commerce_product_list']];
    if ($listing && !$rows) {
      return ['#cache' => $cache];
    }

    $ids = [];
    foreach ($rows as $row) {
      if (preg_match('#/(?:commerce_)?product/(\d+)(?:/|$)#', (string) parse_url($row->resource, PHP_URL_PATH), $m)) {
        $ids[$row->transaction] = $m[1];
      }
    }
    $products = \Drupal::entityTypeManager()->getStorage('commerce_product')->loadMultiple(array_unique($ids));
    $repository = \Drupal::service('entity.repository');
    $explorer = ReviewVerifier::EXPLORER[$network] ?? NULL;

    $items = [];
    foreach ($rows as $row) {
      $product = $products[$ids[$row->transaction] ?? 0] ?? NULL;
      $tx = (string) $row->transaction;
      $items[] = [
        'when' => ['#type' => 'html_tag', '#tag' => 'time', '#attributes' => ['datetime' => gmdate('c', (int) $row->created)], '#value' => gmdate('Y-m-d H:i', (int) $row->created) . ' UTC'],
        'what' => $listing || !$product ? [] : ['#type' => 'link', '#title' => $repository->getTranslationFromContext($product)->label(), '#url' => $product->toUrl()],
        'amount' => ['#markup' => '<span class="ombu-sales__amount">' . self::usdc((string) $row->amount) . ' USDC</span>'],
        'tx' => $explorer && $tx ? ['#type' => 'link', '#title' => substr($tx, 0, 10) . '…', '#url' => Url::fromUri($explorer . $tx), '#attributes' => ['class' => ['ombu-sales__tx']]] : [],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ombu-sales']],
      '#attached' => ['library' => ['ombu/sales']],
      '#cache' => $cache,
      'title' => ['#markup' => '<h2 class="ombu-sales__title">' . t('Latest sales on chain') . '</h2>'],
    ];
    if (!$listing) {
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
    $build['list'] = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['ombu-sales__list']],
      '#items' => $items,
      '#empty' => t('No sales yet: be the first.'),
    ];
    $build['note'] = ['#markup' => '<p class="ombu-sales__note"><small>' . t('Paid in USDC on @chain; each sale links to its transaction.', ['@chain' => Chains::name($network)]) . '</small></p>'];
    return $build;
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

<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\views\argument_default;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_product\Entity\Product;
use Drupal\ombu\Sellers;
use Drupal\views\Attribute\ViewsArgumentDefault;
use Drupal\views\Plugin\views\argument_default\ArgumentDefaultPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The listings the current account sells: they pay one of its wallets.
 *
 * Product IDs joined by "+", for a product ID argument with several values.
 * The same rule as ReviewVerifier::sells(): our listings pay their cold
 * address, Circle's their payTo. ponytail: a listing paid to a seller key
 * with no cold address is left out (none left on the site).
 */
#[ViewsArgumentDefault(
  id: 'ombu_seller_listings',
  title: new TranslatableMarkup('Listings the current account sells'),
)]
final class SellerListings extends ArgumentDefaultPluginBase implements CacheableDependencyInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Connection $database,
    private readonly AccountInterface $currentUser,
    private readonly Sellers $sellers,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('database'), $container->get('current_user'), $container->get('ombu.sellers'));
  }

  /**
   * {@inheritdoc}
   */
  public function getArgument(): string {
    $wallets = $this->database->select('wallet_auth_wallet', 'w')->fields('w', ['address'])
      ->condition('uid', $this->currentUser->id())->condition('namespace', 'eip155')->execute()->fetchCol();
    if (!$wallets) {
      // No wallet, no listing: an ID that matches nothing.
      return '0';
    }
    // The columns compare case-insensitively: checksummed values match.
    $ids = [];
    foreach (['commerce_product__field_pay_to' => 'field_pay_to_value', 'commerce_product__field_seller_address' => 'field_seller_address_value'] as $table => $column) {
      $ids = [...$ids, ...$this->database->select($table, 't')->fields('t', ['entity_id'])
        ->condition($column, $wallets, 'IN')->execute()->fetchCol()];
    }
    // Ours paid to a seller key (no address on the listing): the key's
    // address, as Sellers::payTo() and ReviewVerifier::sells() see it.
    $keyed = $this->database->select('commerce_product__field_seller_key', 'k')->fields('k', ['entity_id'])->execute()->fetchCol();
    $lower = array_map('strtolower', $wallets);
    foreach (Product::loadMultiple(array_diff($keyed, $ids)) as $listing) {
      if (in_array(strtolower((string) $this->sellers->payTo($listing)), $lower, TRUE)) {
        $ids[] = $listing->id();
      }
    }
    return $ids ? implode('+', array_unique($ids)) : '0';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['user'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return ['commerce_product_list'];
  }

}

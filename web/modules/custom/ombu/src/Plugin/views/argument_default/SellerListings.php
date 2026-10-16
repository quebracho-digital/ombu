<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\views\argument_default;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ombu\ReviewVerifier;
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
    private readonly AccountInterface $currentUser,
    private readonly ReviewVerifier $verifier,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'), $container->get('ombu.review_verifier'));
  }

  /**
   * {@inheritdoc}
   */
  public function getArgument(): string {
    // No wallet, no listing: an ID that matches nothing.
    return implode('+', $this->verifier->listingsOf((int) $this->currentUser->id())) ?: '0';
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

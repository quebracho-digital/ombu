<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\comment\CommentInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;
use Drupal\ombu\ReviewRefusedException;
use Drupal\ombu\ReviewVerifier;

/**
 * Reviews are verified purchases: checked on save, counted only if they are.
 */
final class ReviewHooks {

  public function __construct(private readonly ReviewVerifier $verifier) {}

  /**
   * Every review's tx hash must be a purchase, by form or by API.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function fieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() === 'comment' && $bundle === 'review' && isset($fields['field_tx_hash'])) {
      $fields['field_tx_hash']->addConstraint('OmbuVerifiedPurchase');
    }
  }

  /**
   * The paying wallet is what makes a review count: set here, never typed.
   */
  #[Hook('comment_presave')]
  public function presave(CommentInterface $review): void {
    if ($review->bundle() !== 'review' || !($listing = $review->getCommentedEntity()) instanceof ProductInterface) {
      return;
    }
    $tx = strtolower(trim((string) $review->get('field_tx_hash')->value));
    if (!$review->isNew() && $tx === strtolower((string) $review->original?->get('field_tx_hash')->value)) {
      return;
    }
    $review->set('field_tx_hash', $tx ?: NULL);
    try {
      $review->set('field_wallet', $tx ? $this->verifier->payer($listing, $tx, (int) $review->getOwnerId(), $review->isNew() ? NULL : (int) $review->id()) : NULL);
    }
    catch (ReviewRefusedException) {
      // Saved without validation: kept, never counted.
      $review->set('field_wallet', NULL);
    }
  }

  /**
   * A verified purchase links to the chain, where anyone can check it.
   */
  #[Hook('comment_view')]
  public function view(array &$build, CommentInterface $review, EntityViewDisplayInterface $display, string $view_mode): void {
    $tx = (string) $review->get('field_tx_hash')->value;
    if (!isset($build['field_tx_hash'][0]) || $tx === '' || $review->get('field_wallet')->isEmpty()) {
      return;
    }
    $listing = $review->getCommentedEntity();
    if ($listing instanceof ProductInterface && ($url = $this->verifier->explorerUrl($listing, $tx))) {
      $build['field_tx_hash'][0] = [
        '#type' => 'link',
        '#title' => substr($tx, 0, 10) . '…' . substr($tx, -4),
        '#url' => Url::fromUri($url),
        '#attributes' => ['title' => $tx],
      ];
    }
  }

}

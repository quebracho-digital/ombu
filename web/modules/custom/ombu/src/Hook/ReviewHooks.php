<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\comment\CommentInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;
use Drupal\ombu\Rating;
use Drupal\ombu\ReviewRefusedException;
use Drupal\ombu\ReviewVerifier;

/**
 * Reviews are verified purchases: checked on save, counted only if they are.
 */
final class ReviewHooks {

  public function __construct(private readonly ReviewVerifier $verifier) {}

  /**
   * A review's rating as stars, not a digit.
   */
  #[Hook('preprocess_field__field_rating')]
  public function preprocessRating(array &$variables): void {
    foreach ($variables['items'] as $delta => &$item) {
      $item['content'] = ['#markup' => Rating::stars((float) $variables['element']['#items'][$delta]->value)];
    }
  }

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
   * What a buyer said stands as said: no one edits a saved review's text,
   * rating or purchase, admins included. Abuse is handled by unpublishing
   * or deleting it, both logged (moderated()).
   */
  // An alter, not entity_field_access: core keys those results by module,
  // so SellerHooks' answer for ombu would replace this one.
  #[Hook('entity_field_access_alter')]
  public function fieldAccess(array &$grants, array $context): void {
    $review = $context['items']?->getEntity();
    if ($context['operation'] === 'edit' && $review instanceof CommentInterface && $review->bundle() === 'review' && !$review->isNew()
      && in_array($context['field_definition']->getName(), ['comment_body', 'subject', 'field_rating', 'field_tx_hash', 'field_reviewer_kind'], TRUE)) {
      $grants['ombu_review_as_said'] = AccessResult::forbidden('A review stands as its buyer said it.');
    }
  }

  /**
   * Who unpublished or deleted a review, and when: the trail for a report.
   */
  #[Hook('comment_update')]
  public function moderated(CommentInterface $review): void {
    if ($review->bundle() === 'review' && $review->isPublished() !== (bool) $review->getOriginal()?->isPublished()) {
      \Drupal::logger('ombu')->notice('Review @id @what by @user.', ['@id' => $review->id(), '@what' => $review->isPublished() ? 'republished' : 'unpublished', '@user' => \Drupal::currentUser()->getAccountName()]);
    }
  }

  #[Hook('comment_delete')]
  public function deleted(CommentInterface $review): void {
    if ($review->bundle() === 'review') {
      \Drupal::logger('ombu')->notice('Review @id deleted by @user: "@body" (tx @tx).', ['@id' => $review->id(), '@user' => \Drupal::currentUser()->getAccountName() ?: 'drush', '@body' => mb_substr((string) $review->get('comment_body')->value, 0, 200), '@tx' => $review->get('field_tx_hash')->value ?? 'none, a reply']);
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
    if ($review->hasParentComment()) {
      // The seller's answer: never counted, whatever it carries.
      $review->set('field_reviewer_kind', 'seller');
      $review->set('field_rating', NULL);
      $review->set('field_tx_hash', NULL);
      $review->set('field_wallet', NULL);
      return;
    }
    $tx = ReviewVerifier::normalize((string) $review->get('field_tx_hash')->value);
    if (!$review->isNew() && $tx === ReviewVerifier::normalize((string) $review->original?->get('field_tx_hash')->value)) {
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

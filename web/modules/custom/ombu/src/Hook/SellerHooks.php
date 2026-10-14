<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\comment\CommentInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ombu\ReviewVerifier;

/**
 * A listing's seller edits what is theirs and answers its reviews.
 *
 * The seller is whoever signs in with a wallet the listing pays (our
 * seller's key, a Circle listing's payTo). They edit only what the catalogue
 * sync never writes: their note, images, videos and files, and in a
 * translation its title and description. Price, payTo and what Circle
 * publishes stay as they are.
 */
final class SellerHooks {

  /**
   * What a seller may edit on their listing.
   */
  public const FIELDS = ['field_seller_note', 'images', 'field_videos', 'field_files'];

  public function __construct(private readonly ReviewVerifier $verifier) {}

  /**
   * The seller may open their listing's edit form.
   */
  #[Hook('commerce_product_access')]
  public function access(ProductInterface $listing, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'update' || $listing->bundle() !== 'listing') {
      return AccessResult::neutral();
    }
    return AccessResult::allowedIf($this->verifier->sells($listing, (int) $account->id()))
      ->cachePerUser()->addCacheableDependency($listing);
  }

  /**
   * On the form, a seller who is not staff sees only their fields.
   */
  #[Hook('entity_field_access')]
  public function fieldAccess(string $operation, FieldDefinitionInterface $field, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($operation !== 'edit' || $field->getTargetEntityTypeId() !== 'commerce_product') {
      return AccessResult::neutral();
    }
    $listing = $items?->getEntity();
    if (!$listing instanceof ProductInterface || $listing->bundle() !== 'listing' || $account->hasPermission('administer commerce_product') || $account->hasPermission('update any listing commerce_product')) {
      return AccessResult::neutral()->cachePerPermissions();
    }
    // In a translation the seller also words the title and description:
    // the original stays as the catalogue sync writes it.
    $fields = $listing->isDefaultTranslation() ? self::FIELDS : [...self::FIELDS, 'title', 'body'];
    return AccessResult::forbiddenIf(!in_array($field->getName(), $fields, TRUE))->cachePerPermissions();
  }

  /**
   * Replies are the seller's: only they get the link.
   */
  #[Hook('comment_links_alter')]
  public function links(array &$links, CommentInterface $review, array $context): void {
    $listing = $review->getCommentedEntity();
    if ($review->bundle() === 'review' && $listing instanceof ProductInterface && !$this->verifier->sells($listing, (int) \Drupal::currentUser()->id())) {
      unset($links['comment']['#links']['comment-reply']);
    }
    $links['comment']['#cache']['contexts'][] = 'user';
  }

  /**
   * A reply is not a review: no rating, no purchase.
   */
  #[Hook('form_comment_review_form_alter')]
  public function replyForm(array &$form, $form_state): void {
    // Plain text, as over the API: no editor, no format to pick, no subject
    // (core takes it from the text).
    $form['comment_body']['widget'][0]['#allowed_formats'] = ['plain_text'];
    $form['comment_body']['widget'][0]['#after_build'][] = [self::class, 'noFormatPicker'];
    $form['subject']['#access'] = FALSE;
    if ($form_state->getFormObject()->getEntity()->hasParentComment()) {
      $form['field_rating']['#access'] = FALSE;
      $form['field_tx_hash']['#access'] = FALSE;
      return;
    }
    // Stars, not radios (listing.css). The field stays optional for replies,
    // so its widget offers "N/A"; a review must rate (VerifiedPurchase).
    unset($form['field_rating']['widget']['#options']['_none']);
    $form['field_rating']['widget']['#attributes']['class'][] = 'ombu-stars';
    // Instead of the theme's "Add new comment", hidden by fieldPreprocess().
    $form['heading'] = [
      '#markup' => '<h2 class="review-heading">' . new TranslatableMarkup('Review this listing') . '</h2>',
      '#weight' => -100,
    ];
  }

  /**
   * The format's select and help, gone: there is only one.
   */
  public static function noFormatPicker(array $element): array {
    $element['format']['#access'] = FALSE;
    return $element;
  }

  /**
   * A listing's reviews: the review form brings its own heading.
   */
  #[Hook('preprocess_field__comment')]
  public function fieldPreprocess(array &$variables): void {
    if ($variables['field_name'] === 'field_reviews') {
      $variables['content_attributes']['class'][] = 'hidden';
    }
  }

}

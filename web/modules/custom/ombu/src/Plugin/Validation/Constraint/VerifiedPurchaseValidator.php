<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Validation\Constraint;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\ombu\ReviewRefusedException;
use Drupal\ombu\ReviewVerifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks the purchase on chain when a review is written or its tx changes.
 */
final class VerifiedPurchaseValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(private readonly ReviewVerifier $verifier) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('ombu.review_verifier'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    /** @var \Drupal\comment\CommentInterface $review */
    $review = $items->getEntity();
    $listing = $review->getCommentedEntity();
    if (!$listing instanceof ProductInterface) {
      return;
    }
    // A reply is the seller's answer, not a review: no purchase, no rating.
    if ($review->hasParentComment()) {
      if (!$this->verifier->sells($listing, (int) $review->getOwnerId())) {
        $this->context->addViolation('Only the listing\'s seller replies to reviews; to review it, buy it.');
      }
      return;
    }
    if ($review->isNew() && $review->get('field_rating')->isEmpty()) {
      $this->context->addViolation('Rate it from 1 to 5.');
    }
    $tx = (string) $items->value;
    // Already checked when it was written: the chain does not change.
    if (!$review->isNew() && $tx === (string) $review->original?->get('field_tx_hash')->value) {
      return;
    }
    if ($tx === '') {
      $this->context->addViolation('A review needs a purchase: the hash of the transaction that paid for it.');
      return;
    }
    try {
      $this->verifier->payer($listing, $tx, (int) $review->getOwnerId(), $review->isNew() ? NULL : (int) $review->id());
    }
    catch (ReviewRefusedException $e) {
      $this->context->addViolation($e->getMessage());
    }
  }

}

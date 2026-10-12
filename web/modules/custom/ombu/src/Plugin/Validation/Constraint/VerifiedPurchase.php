<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A review's tx hash is a purchase of the listing from the reviewer's wallet.
 */
#[Constraint(
  id: 'OmbuVerifiedPurchase',
  label: new TranslatableMarkup('Verified purchase', [], ['context' => 'Validation']),
)]
final class VerifiedPurchase extends SymfonyConstraint {}

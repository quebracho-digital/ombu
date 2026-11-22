<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A listing's A2UI field holds a payload Ombú can read and draw.
 */
#[Constraint(
  id: 'OmbuA2uiSurface',
  label: new TranslatableMarkup('A2UI surface', [], ['context' => 'Validation']),
)]
final class A2uiSurface extends SymfonyConstraint {

  /**
   * The message; the reason from the reader follows.
   */
  public string $message = 'This is not an A2UI surface Ombú can draw: @reason';

}

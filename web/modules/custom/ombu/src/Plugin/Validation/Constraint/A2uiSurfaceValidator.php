<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\ombu\A2ui\A2uiException;
use Drupal\ombu\A2ui\ListingSurface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Reads and draws the payload once, as the listing page will.
 */
final class A2uiSurfaceValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof A2uiSurface || !$value instanceof FieldItemListInterface) {
      return;
    }
    $json = trim((string) $value->value);
    if ($json === '') {
      return;
    }
    try {
      ListingSurface::build($json);
    }
    catch (A2uiException $e) {
      $this->context->addViolation($constraint->message, ['@reason' => $e->getMessage()]);
    }
  }

}

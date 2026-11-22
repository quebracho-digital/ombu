<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * The listing's A2UI field.
 */
final class A2uiHooks {

  /**
   * Whatever saves a listing (form, API, drush) is held to the same check.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The bundle's fields.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function constrain(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() === 'commerce_product' && $bundle === 'listing' && isset($fields['field_a2ui'])) {
      $fields['field_a2ui']->addConstraint('OmbuA2uiSurface');
    }
  }

}

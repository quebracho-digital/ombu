<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * The listing's A2UI field, and the posts that show a surface.
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

  /**
   * Gives the layout to a post that carries a drawn surface.
   *
   * A post may show a surface (a preview of a form), already drawn into its
   * body; its stylesheet is not on a blog page otherwise.
   *
   * @param array<string, mixed> $build
   *   The render array of the entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   *   The display.
   * @param string $view_mode
   *   The view mode.
   */
  #[Hook('entity_view')]
  public function attachToPosts(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($entity instanceof ContentEntityInterface && $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'blog' && $view_mode === 'full'
      && $entity->hasField('body') && str_contains((string) $entity->get('body')->value, 'data-a2ui-surface')) {
      $build['#attached']['library'][] = 'ombu/a2ui';
    }
  }

}

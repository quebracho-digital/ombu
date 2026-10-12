<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Ombú's accessible pager, over whatever pager the theme brings.
 */
final class ThemeHooks {

  /**
   * Implements hook_theme_registry_alter().
   */
  #[Hook('theme_registry_alter')]
  public function themeRegistryAlter(array &$theme_registry): void {
    // ponytail: Belgrade's markup and classes, two fixes; a theme of our
    // own would carry this template instead.
    $theme_registry['pager']['path'] = \Drupal::service('extension.list.module')->getPath('ombu') . '/templates';
    $theme_registry['pager']['template'] = 'pager';
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Ombú's accessible pager and the header's two dropdowns, over Belgrade.
 */
final class ThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    // Belgrade has neither template, so these are used.
    return [
      'menu__account' => ['base hook' => 'menu'],
      'links__language_block' => ['base hook' => 'links'],
      // Listings as cards (Cards): a theme can override either template.
      'ombu_cards' => [
        'variables' => ['title' => '', 'cards' => [], 'slider' => FALSE, 'attributes' => []],
      ],
      'ombu_card' => [
        'variables' => [
          'id' => NULL,
          'url' => NULL,
          'title' => '',
          'summary' => '',
          'logo' => NULL,
          'initial' => '',
          'hue' => 0,
          'provider' => '',
          'category' => '',
          'price' => '',
          'stars' => NULL,
          'reviews' => 0,
          'chains' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK() for links__language_block.
   */
  #[Hook('preprocess_links__language_block')]
  public function preprocessLanguageBlock(array &$variables): void {
    // The block is cached per interface language, so this is safe.
    $variables['current'] = \Drupal::languageManager()->getCurrentLanguage()->getId();
    foreach ($variables['links'] as &$item) {
      $item['link']['#options']['attributes']['class'][] = 'dropdown-item';
    }
  }

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

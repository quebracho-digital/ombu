<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Registers the dashboard area's title for the Views UI.
 *
 * The ViewsArea attribute only carries an id; without a title the "Add area"
 * list would show a blank row.
 */
final class DashboardHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_plugins_area_alter().
   */
  #[Hook('views_plugins_area_alter')]
  public function viewsPluginsAreaAlter(array &$plugins): void {
    if (!isset($plugins['ombu_dashboard'])) {
      return;
    }
    $plugins['ombu_dashboard']['title'] = $this->t('Ombú dashboard');
    $plugins['ombu_dashboard']['help'] = $this->t('The site vitals, one card per vital.');
    $plugins['ombu_dashboard']['group'] = $this->t('Ombú');
  }

}

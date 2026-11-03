<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * What /llms.txt says follows the modules that serve agents.
 */
final class AgentHooks {

  public function __construct(private readonly ModuleHandlerInterface $moduleHandler) {}

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function installed(array $modules, bool $is_syncing): void {
    $this->rewrite($modules, $is_syncing);
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  #[Hook('modules_uninstalled')]
  public function uninstalled(array $modules, bool $is_syncing): void {
    $this->rewrite($modules, $is_syncing);
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * The MCP link of the footer exists only where its route does.
   */
  #[Hook('menu_links_discovered_alter')]
  public function links(array &$links): void {
    if (!$this->moduleHandler->moduleExists('mcp_server')) {
      unset($links['ombu.mcp']);
    }
  }

  /**
   * Rewrites /llms.txt when mcp_server comes or goes (its MCP section).
   */
  private function rewrite(array $modules, bool $is_syncing): void {
    if (!$is_syncing && in_array('mcp_server', $modules, TRUE)) {
      $this->moduleHandler->loadInclude('ombu', 'install');
      _ombu_llms_txt();
    }
  }

}

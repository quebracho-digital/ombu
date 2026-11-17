<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\views\area;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ombu\Dashboard\Vitals;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Ombú vitals as a Views area, so the dashboard is a view.
 *
 * The view at /admin/ombu renders this area and nothing else. Editing the
 * view (title, header, footer, path, access) changes the page without code;
 * the blocks are also block plugins, for Layout Builder.
 *
 * The view's access plugin already requires "view ombu dashboard"; the check
 * here keeps the area empty if it is ever placed in another view.
 */
#[ViewsArea('ombu_dashboard')]
final class Dashboard extends AreaPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Vitals $vitals,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ombu.dashboard'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    if (!$this->currentUser->hasPermission('view ombu dashboard')) {
      return [];
    }
    $page = $this->vitals->page();
    return [
      '#theme' => 'ombu_dashboard',
      '#blocks' => $page['blocks'],
      '#summary' => $page['summary'],
      // Live numbers: a cached page would lie.
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['ombu/dashboard']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function adminLabel($short = FALSE) {
    return $this->t('Ombú dashboard');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ombu\Dashboard\Vitals;
use Drupal\ombu\Plugin\Derivative\DashboardBlockDeriver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One vital as a block, so Layout Builder can place and reorder it.
 *
 * The base plugin is never placed: the deriver makes one derivative per
 * vital. Every derivative is readable only with "view ombu dashboard", the
 * same permission as the /admin/ombu page.
 */
#[Block(
  id: 'ombu_dashboard',
  admin_label: new TranslatableMarkup('Ombú dashboard'),
  category: new TranslatableMarkup('Ombú'),
  deriver: DashboardBlockDeriver::class,
)]
final class DashboardBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Vitals $vitals,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('ombu.dashboard'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $id = (string) $this->getDerivativeId();
    return [
      '#theme' => 'ombu_dashboard',
      '#blocks' => [$this->vitals->block($id)],
      // Live numbers: a cached block would lie.
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['ombu/dashboard']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'view ombu dashboard');
  }

}

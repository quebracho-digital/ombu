<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ombu\Dashboard\Vitals;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One dashboard block per vital.
 *
 * The ids match Vitals::PARTS, so a block placed in Layout Builder and the
 * default /admin/ombu layout name the same data.
 */
final class DashboardBlockDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    $instance = new static();
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $titles = [
      'stop' => $this->t('Stop switch'),
      'sales' => $this->t('Sales'),
      'search' => $this->t('Search'),
      'cron' => $this->t('Cron and queues'),
      'imports' => $this->t('Catalogue imports'),
      'screening' => $this->t('Screening'),
      'wallet_changes' => $this->t('Changed wallets'),
      'errors' => $this->t('Errors'),
      'moderation' => $this->t('Moderation'),
      'blog' => $this->t('Blog'),
    ];
    foreach (array_keys(Vitals::PARTS) as $id) {
      $this->derivatives[$id] = [
        'admin_label' => $this->t('Ombú: @title', ['@title' => $titles[$id] ?? $id]),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}

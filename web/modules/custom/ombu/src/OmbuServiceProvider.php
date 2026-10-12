<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Swaps in a comment manager that does not offer a closed registration.
 */
final class OmbuServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('comment.manager')) {
      $container->getDefinition('comment.manager')->setClass(CommentManager::class);
    }
  }

}

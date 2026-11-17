<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\ombu\Dashboard\Vitals;

/**
 * The site's vitals at /admin/ombu, read-only.
 *
 * The controller only draws what Vitals computed, so the numbers can be
 * tested without HTML. Nothing here changes the site.
 */
final class DashboardController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(private readonly Vitals $vitals) {}

  /**
   * {@inheritdoc}
   */
  public function __invoke(): array {
    return [
      '#theme' => 'ombu_dashboard',
      '#blocks' => $this->vitals->blocks(),
      // Every block reads live data: a cached page would lie.
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['ombu/dashboard']],
    ];
  }

}

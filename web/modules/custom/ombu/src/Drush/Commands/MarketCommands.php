<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\ombu\CircleCatalogImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the market.
 */
final class MarketCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'ombu.circle_importer')]
    private readonly CircleCatalogImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports the Circle Agent Marketplace catalogue as listings.
   */
  #[CLI\Command(name: 'ombu:import-circle')]
  #[CLI\Option(name: 'max', description: 'Stop after this many resources (0 = all).')]
  #[CLI\Usage(name: 'drush ombu:import-circle --max=50', description: 'Import the first 50.')]
  public function importCircle(array $options = ['max' => 0]): void {
    $counts = $this->importer->import((int) $options['max']);
    $this->logger()->success(json_encode($counts));
  }

}

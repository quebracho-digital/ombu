<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\ombu\DiscoveryCatalogImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Imports the x402 discovery catalogues (CDP Bazaar, PayAI).
 *
 * By hand, not from cron: a run asks every new resource's seller and a node
 * (needs ALCHEMY_API_KEY), and only what Ombú verified itself is listed.
 */
final class CatalogImportCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'ombu.bazaar_importer')]
    private readonly DiscoveryCatalogImporter $bazaar,
    #[Autowire(service: 'ombu.payai_importer')]
    private readonly DiscoveryCatalogImporter $payai,
  ) {
    parent::__construct();
  }

  /**
   * Imports a catalogue's verified resources as listings.
   */
  #[CLI\Command(name: 'ombu:import-catalog')]
  #[CLI\Argument(name: 'source', description: 'bazaar or payai.')]
  #[CLI\Option(name: 'max', description: 'Stop after reading this many resources (0 = all). A partial run retires nothing.')]
  #[CLI\Usage(name: 'drush ombu:import-catalog bazaar --max=300', description: 'Read the first 300 resources of Bazaar and list the verified ones.')]
  public function import(string $source, array $options = ['max' => 0]): void {
    $importer = match ($source) {
      'bazaar' => $this->bazaar,
      'payai' => $this->payai,
      default => throw new \InvalidArgumentException("Unknown source: $source (bazaar or payai)."),
    };
    $counts = $importer->import((int) $options['max']);
    $this->logger()->success(json_encode($counts, JSON_UNESCAPED_SLASHES));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports the x402 discovery catalogues (CDP Bazaar, PayAI).
 *
 * By hand, not from cron: a run asks every new resource's seller and a node
 * (needs ALCHEMY_API_KEY), and only what Ombú verified itself is listed.
 */
final class CatalogImportCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Takes the container, not the importers: building them needs the node's
   * key, and a missing key must be said by the command, not hide it.
   */
  public function __construct(
    #[Autowire(service: 'service_container')]
    private readonly ContainerInterface $container,
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
    if (!in_array($source, ['bazaar', 'payai'], TRUE)) {
      throw new \InvalidArgumentException("Unknown source: $source (bazaar or payai).");
    }
    /** @var \Drupal\ombu\DiscoveryCatalogImporter $importer */
    $importer = $this->container->get("ombu.{$source}_importer");
    $counts = $importer->import((int) $options['max']);
    $this->logger()->success(json_encode($counts, JSON_UNESCAPED_SLASHES));
  }

}

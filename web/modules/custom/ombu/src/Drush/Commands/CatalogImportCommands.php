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

  /**
   * Lists the listings whose seller now names another wallet, or accepts one.
   */
  #[CLI\Command(name: 'ombu:wallet-changes')]
  #[CLI\Argument(name: 'source', description: 'bazaar or payai.')]
  #[CLI\Option(name: 'accept', description: 'The id of a listing whose new wallet you looked at and accept.')]
  #[CLI\Usage(name: 'drush ombu:wallet-changes bazaar', description: 'The listings retired because they pay a wallet they never declared.')]
  #[CLI\Usage(name: 'drush ombu:wallet-changes bazaar --accept=123', description: 'Check listing 123 again and keep the wallet it answers with now.')]
  public function walletChanges(string $source, array $options = ['accept' => 0]): int {
    if (!in_array($source, ['bazaar', 'payai'], TRUE)) {
      throw new \InvalidArgumentException("Unknown source: $source (bazaar or payai).");
    }
    /** @var \Drupal\ombu\DiscoveryCatalogImporter $importer */
    $importer = $this->container->get("ombu.{$source}_importer");
    if (!$options['accept']) {
      foreach ($importer->walletChanges() as $id => $change) {
        $this->io()->writeln(sprintf('%d  %s  %s -> %s  (%s)', $id, $change['url'], implode(',', $change['old']), implode(',', $change['new']), date('Y-m-d', $change['time'])));
      }
      return self::EXIT_SUCCESS;
    }
    $listing = $this->container->get('entity_type.manager')->getStorage('commerce_product')->load((int) $options['accept'])
      ?? throw new \InvalidArgumentException("No listing {$options['accept']}.");
    $report = $importer->acceptWallet($listing);
    foreach ($report['checks'] as $at => [$verdict, $detail]) {
      $this->io()->writeln("$verdict  $at: $detail");
    }
    $taken = $report['verdict'] === 'pass';
    $this->io()->writeln($taken ? 'Accepted: the listing comes back on the next import.' : 'Not accepted: the wallet on file stays.');
    return $taken ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}

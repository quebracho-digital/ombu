<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\ombu\DiscoveryCatalogImporter;
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
   *
   * The granularity of what comes in: --only-tags narrows to resources
   * carrying one of the tags; --min-price (atomic units, e.g. 5000 = $0.005)
   * and --max-age-days (a window, e.g. 30) demand that payTo's incoming
   * transfers look like real sales: one of at least the listing's own price,
   * inside the window. The budget and the minimum transfer count stay the
   * service's (200, 1).
   */
  #[CLI\Command(name: 'ombu:import-catalog')]
  #[CLI\Argument(name: 'source', description: 'bazaar or payai.')]
  #[CLI\Option(name: 'max', description: 'Stop after reading this many resources (0 = all). A partial run retires nothing.')]
  #[CLI\Option(name: 'only-tags', description: 'Comma-separated tags: import only resources carrying one of them.')]
  #[CLI\Option(name: 'min-price', description: 'In atomic units: payTo must have received a transfer of at least this (0 = any).')]
  #[CLI\Option(name: 'max-age-days', description: 'The newest incoming transfer no older than this many days (0 = any).')]
  #[CLI\Usage(name: 'drush ombu:import-catalog bazaar --max=300', description: 'Read the first 300 resources of Bazaar and list the verified ones.')]
  #[CLI\Usage(name: 'drush ombu:import-catalog payai --only-tags=ai,inference --max-age-days=30', description: 'Only AI-tagged resources with a recent USDC sale.')]
  public function import(string $source, array $options = ['max' => 0, 'only-tags' => '', 'min-price' => 0, 'max-age-days' => 0]): void {
    if (!in_array($source, ['bazaar', 'payai'], TRUE)) {
      throw new \InvalidArgumentException("Unknown source: $source (bazaar or payai).");
    }
    $importer = $this->narrow($source, $options);
    $counts = $importer->import((int) $options['max']);
    $this->logger()->success(json_encode($counts, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Builds the importer with this run's granularity over the service's base.
   */
  private function narrow(string $source, array $options): DiscoveryCatalogImporter {
    /** @var \Drupal\ombu\DiscoveryCatalogImporter $base */
    $base = $this->container->get("ombu.{$source}_importer");
    if (!$options['only-tags'] && !(int) $options['min-price'] && !(int) $options['max-age-days']) {
      return $base;
    }
    return new DiscoveryCatalogImporter(
      $source,
      $this->api($source),
      \Drupal::service('http_client'),
      \Drupal::service('entity_type.manager'),
      \Drupal::service('logger.factory'),
      \Drupal::service('lock'),
      \Drupal::service('ombu.resource_verifier'),
      \Drupal::service('state'),
      \Drupal::service('datetime.time'),
      200,
      1,
      array_values(array_filter(array_map('trim', explode(',', (string) $options['only-tags'])))),
      (int) $options['min-price'],
      (int) $options['max-age-days'],
    );
  }

  /**
   * The catalogue URL of a source, as ombu.services.yml declares it.
   */
  private function api(string $source): string {
    return $source === 'bazaar'
      ? 'https://api.cdp.coinbase.com/platform/v2/x402/discovery/resources'
      : 'https://facilitator.payai.network/discovery/resources';
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

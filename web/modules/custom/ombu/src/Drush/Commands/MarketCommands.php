<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\ombu\Embeddings;
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
    #[Autowire(service: 'ombu.embeddings')]
    private readonly Embeddings $embeddings,
    private readonly QueueFactory $queueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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

  /**
   * Queues the listings whose text has no current vector.
   *
   * Then run the queue: each text is embedded once (Embeddings).
   */
  #[CLI\Command(name: 'ombu:embed')]
  #[CLI\Usage(name: 'drush ombu:embed && drush queue:run ombu_embed', description: 'Embed every listing that needs it.')]
  public function embed(): void {
    if (!$this->embeddings->available() || !$this->embeddings->model()) {
      throw new \RuntimeException('Needs the ombu_listing_vector table (MariaDB 11.7+, drush updb) and a default AI provider for embeddings.');
    }
    $queue = $this->queueFactory->get(Embeddings::QUEUE);
    $queued = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'listing')->execute();
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $listing) {
        if ($listing instanceof ProductInterface && $this->embeddings->stale($listing)) {
          $queue->createItem($listing->id());
          $queued++;
        }
      }
      $storage->resetCache($chunk);
    }
    $this->logger()->success("$queued listings queued; " . $queue->numberOfItems() . ' in the queue.');
  }

}

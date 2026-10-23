<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ombu\Embeddings;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Utility\Utility;

/**
 * Keeps the listings' vectors current (Embeddings), and Meilisearch's.
 *
 * "More like this" itself is SimilarDatabase on the database server, and
 * Meilisearch's /similar on a Meilisearch one.
 */
final class SimilarHooks {

  public function __construct(
    private readonly Embeddings $embeddings,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * A listing's text changed: embed it again, on cron.
   */
  #[Hook('commerce_product_insert')]
  #[Hook('commerce_product_update')]
  public function save(ProductInterface $product): void {
    if ($product->bundle() === 'listing' && $this->embeddings->stale($product)) {
      $this->queueFactory->get(Embeddings::QUEUE)->createItem($product->id());
    }
  }

  /**
   * A deleted listing takes its vector along.
   */
  #[Hook('commerce_product_delete')]
  public function delete(ProductInterface $product): void {
    $this->embeddings->delete((int) $product->id());
  }

  /**
   * Meilisearch gets each listing's vector: the model is asked once, here.
   *
   * Only for servers whose embedder takes vectors from Drupal ("Vectors
   * provided by Drupal"); a listing without one is sent without.
   */
  #[Hook('meilisearch_documents_alter')]
  public function meilisearchDocuments(array &$documents, IndexInterface $index, array $items): void {
    $config = $index->getServerInstance()->getBackendConfig();
    if (empty($config['embedder_dimensions'])) {
      return;
    }
    $ids = [];
    foreach ($documents as $key => $document) {
      // "entity:commerce_product/123:en".
      [$datasource, $raw] = Utility::splitCombinedId($document['search_api_id']);
      if ($datasource === 'entity:commerce_product') {
        $ids[$key] = (int) $raw;
      }
    }
    $vectors = $this->embeddings->vectors(array_unique($ids));
    foreach ($ids as $key => $id) {
      if (isset($vectors[$id])) {
        $documents[$key]['_vectors'][$config['embedder']] = $vectors[$id];
      }
    }
  }

}

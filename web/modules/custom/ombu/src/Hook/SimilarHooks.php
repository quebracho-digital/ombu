<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ombu\Embeddings;

/**
 * Keeps the listings' vectors current (Embeddings).
 *
 * "More like this" itself is SimilarDatabase.
 */
final class SimilarHooks {

  /**
   * A listing's text changed: embed it again, on cron.
   */
  #[Hook('commerce_product_insert')]
  #[Hook('commerce_product_update')]
  public function save(ProductInterface $product): void {
    if ($product->bundle() === 'listing' && Embeddings::stale($product)) {
      \Drupal::queue(Embeddings::QUEUE)->createItem($product->id());
    }
  }

  /**
   * A deleted listing takes its vector along.
   */
  #[Hook('commerce_product_delete')]
  public function delete(ProductInterface $product): void {
    if (Embeddings::available()) {
      \Drupal::database()->delete(Embeddings::TABLE)->condition('product_id', $product->id())->execute();
    }
  }

}

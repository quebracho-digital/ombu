<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\QueueWorker;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ombu\Embeddings;

/**
 * Embeds a listing's text when it changed (Embeddings::update()).
 *
 * On cron, a minute a run; the backlog with drush queue:run ombu_embed.
 */
#[QueueWorker(
  id: Embeddings::QUEUE,
  title: new TranslatableMarkup('Embed listings for similar services'),
  cron: ['time' => 60],
)]
final class Embed extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!Embeddings::model()) {
      // No embeddings model set: keep the items until there is one.
      throw new SuspendQueueException('No default AI provider for embeddings.');
    }
    if ($listing = Product::load($data)) {
      Embeddings::update($listing);
    }
  }

}

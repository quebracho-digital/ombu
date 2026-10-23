<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\QueueWorker;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ombu\Embeddings;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
final class Embed extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Embeddings $embeddings,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('ombu.embeddings'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!$this->embeddings->model()) {
      // No embeddings model set: keep the items until there is one.
      throw new SuspendQueueException('No default AI provider for embeddings.');
    }
    $listing = $this->entityTypeManager->getStorage('commerce_product')->load($data);
    if ($listing instanceof ProductInterface) {
      $this->embeddings->update($listing);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\ombu\Plugin\search_api\backend\SimilarDatabase;
use Drupal\search_api\Event\GatheringPluginInfoEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives Search API's database backend "More like this" (SimilarDatabase).
 */
final class SimilarBackendSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::GATHERING_BACKENDS => 'backends'];
  }

  /**
   * The "search_api_db" plugin runs as SimilarDatabase.
   */
  public function backends(GatheringPluginInfoEvent $event): void {
    $definitions = &$event->getDefinitions();
    if (isset($definitions['search_api_db'])) {
      $definitions['search_api_db']['class'] = SimilarDatabase::class;
    }
  }

}

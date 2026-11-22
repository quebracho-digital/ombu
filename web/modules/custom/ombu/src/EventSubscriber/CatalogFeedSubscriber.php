<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\feeds\Event\InitEvent;
use Drupal\ombu\Catalog\CatalogRun;
use Drupal\ombu\Feeds\Processor\ListingProcessorBase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Starts a catalogue feed's run when Feeds starts fetching its first page.
 *
 * Feeds runs an import from many places (drush, the feed's page as a batch,
 * cron as a queue), and every one of them fetches first. Here, before that
 * first fetch, the run takes its options, checks that no other import of the
 * same catalogue is running and forgets the keys of the last run
 * (ListingProcessorBase::begin()). A run that cannot start fails before any
 * page is read.
 *
 * The event's name is written out: this class is compiled into the
 * container even where Feeds is not enabled (some kernel tests).
 */
final class CatalogFeedSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['feeds.init_import' => ['onInitImport', 100]];
  }

  /**
   * Begins the run, once, at the fetch stage.
   */
  public function onInitImport(InitEvent $event): void {
    if ($event->getStage() !== 'fetch') {
      return;
    }
    $feed = $event->getFeed();
    $processor = $feed->getType()->getProcessor();
    if (!$processor instanceof ListingProcessorBase) {
      return;
    }
    $run = CatalogRun::of($feed);
    if (!$run->started) {
      $processor->begin($feed, $run);
    }
  }

}

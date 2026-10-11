<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Points /market to the same search as JSON, for agents.
 *
 * Derived from the URL alone, so the page cache may keep it.
 */
final class MarketLinkSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  /**
   * Adds the Link header to the market page.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if ($event->isMainRequest() && $request->attributes->get('_route') === 'view.market.page_1') {
      $json = Url::fromRoute('ombu.catalogue', [], ['query' => $request->query->all(), 'absolute' => TRUE])->toString();
      $event->getResponse()->headers->set('Link', "<$json>; rel=\"alternate\"; type=\"application/json\"", FALSE);
    }
  }

}

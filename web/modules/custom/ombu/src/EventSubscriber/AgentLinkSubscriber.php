<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells agents where to look: on every page, /llms.txt and /openapi.json;
 * on /market, the same search as JSON.
 *
 * Derived from the URL alone, so the page cache may keep it.
 */
final class AgentLinkSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  /**
   * Adds the Link headers to HTML pages.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
      return;
    }
    $links = [
      '</llms.txt>; rel="describedby"; type="text/markdown"',
      '</openapi.json>; rel="service-desc"; type="application/json"',
    ];
    $request = $event->getRequest();
    if ($request->attributes->get('_route') === 'view.market.page_1') {
      $json = Url::fromRoute('ombu.catalogue', [], ['query' => $request->query->all(), 'absolute' => TRUE])->toString();
      $links[] = "<$json>; rel=\"alternate\"; type=\"application/json\"";
    }
    foreach ($links as $link) {
      $response->headers->set('Link', $link, FALSE);
    }
  }

}

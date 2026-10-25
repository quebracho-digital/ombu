<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Url;
use Drupal\ombu\Controller\CatalogueController;
use Drupal\x402_pay\PathProcessor\MarkdownPathProcessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells agents where to look, and answers the market as markdown.
 *
 * On every page, /llms.txt and /openapi.json; on /market, the same search
 * as JSON and as markdown.
 *
 * Derived from the URL alone, so the page cache may keep it.
 */
final class AgentLinkSubscriber implements EventSubscriberInterface {

  /**
   * The market's page, for people.
   */
  private const MARKET = 'view.market.page_1';

  public function __construct(private readonly ClassResolverInterface $classResolver) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing (32), before x402_pay's gate (30), which answers 404
      // to a .md path no one answered.
      KernelEvents::REQUEST => ['onRequest', 31],
      KernelEvents::RESPONSE => 'onResponse',
    ];
  }

  /**
   * The search as markdown: /market.md, or /market asked for text/markdown.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($event->isMainRequest() && $request->isMethodCacheable() && $request->attributes->get('_route') === self::MARKET
      && (MarkdownPathProcessor::asked($request) || str_contains((string) $request->headers->get('Accept'), 'text/markdown'))) {
      $event->setResponse($this->classResolver->getInstanceFromDefinition(CatalogueController::class)->markdown($request));
    }
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
    if ($request->attributes->get('_route') === self::MARKET) {
      $json = Url::fromRoute('ombu.catalogue', [], ['query' => $request->query->all(), 'absolute' => TRUE])->toString();
      $links[] = "<$json>; rel=\"alternate\"; type=\"application/json\"";
      $links[] = '<' . CatalogueController::marketMd($request->query->all()) . '>; rel="alternate"; type="text/markdown"';
      // One URL, HTML or markdown by Accept: shared caches must know.
      $response->headers->set('Vary', 'Accept', FALSE);
    }
    foreach ($links as $link) {
      $response->headers->set('Link', $link, FALSE);
    }
  }

}

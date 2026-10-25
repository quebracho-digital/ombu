<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ombu\Markdown;
use Drupal\ombu\Similar;
use Drupal\ombu\SimilarLog;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\PathProcessor\MarkdownPathProcessor;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A listing's page as markdown, free, ending with its similar services.
 *
 * Free as its page is for people: what a listing sells is a call to its
 * service at its x402 URL, not the page, so its .md (or Accept:
 * text/markdown) is answered here, before x402_pay's gate would charge the
 * full view mode its price field names.
 *
 *
 * The markdown x402_pay renders has the display's fields only, not the
 * block under the page (Similar::build()). Agents follow these links as
 * people follow the cards: the same listings, in the page's language. The
 * markdown page is not page-cached (x402_pay).
 *
 * What was shown, and which link an agent followed (the links carry
 * ombu_from and ombu_pos), go to SimilarLog.
 */
final class ListingMarkdownSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly Similar $similar,
    private readonly SimilarLog $log,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    TranslationInterface $string_translation,
    private readonly Delivery $delivery,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing and its access check (32), before x402_pay's gate (30).
      KernelEvents::REQUEST => ['onRequest', 31],
      KernelEvents::RESPONSE => 'onResponse',
    ];
  }

  /**
   * The listing a route shows as its page, if nothing charges on that page.
   */
  private static function freeListing(RouteMatchInterface|Request $route): ?ProductInterface {
    $route = $route instanceof Request ? RouteMatch::createFromRequest($route) : $route;
    $product = $route->getRouteName() === 'entity.commerce_product.canonical' ? $route->getParameter('commerce_product') : NULL;
    return $product instanceof ProductInterface && $product->bundle() === 'listing' && !X402PriceItem::find($product) ? $product : NULL;
  }

  /**
   * Where agents read this listing's page as markdown, if the route shows one.
   */
  public static function markdownUrl(RouteMatchInterface $route_match, CacheableMetadata $cacheability): ?string {
    $cacheability->addCacheContexts(['route']);
    $product = $route_match->getParameter('commerce_product');
    if ($product instanceof ProductInterface) {
      // It may start charging on its page: the product and its price field.
      $cacheability->addCacheableDependency($product);
      if ($product->hasField('field_x402_price')) {
        $cacheability->addCacheableDependency($product->getFieldDefinition('field_x402_price'));
      }
    }
    if (!$product = self::freeListing($route_match)) {
      return NULL;
    }
    $url = $product->toUrl()->toString(TRUE);
    $cacheability->addCacheableDependency($url);
    return $url->getGeneratedUrl() . '.md';
  }

  /**
   * A listing's page asked for as markdown: its full view, free.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($event->isMainRequest() && $request->isMethodCacheable()
      && (MarkdownPathProcessor::asked($request) || str_contains((string) $request->headers->get('Accept'), 'text/markdown'))
      && ($product = self::freeListing($request))) {
      $event->setResponse($this->delivery->markdown($product, 'full'));
    }
  }

  /**
   * Adds the "Similar services" section to a listing's markdown.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();
    $product = $request->attributes->get('commerce_product');
    if (!$event->isMainRequest()
      || $request->attributes->get('_route') !== 'entity.commerce_product.canonical'
      || !$product instanceof ProductInterface || $product->bundle() !== 'listing'
      || $response->getStatusCode() !== 200
      || !str_starts_with((string) $response->headers->get('Content-Type'), 'text/markdown')) {
      return;
    }
    // A followed link names the listing it was under and its place.
    $from = $this->log->listings([$request->query->get('ombu_from')]);
    $position = $request->query->get('ombu_pos');
    if ($from && is_string($position) && ctype_digit($position)) {
      $this->log->event('follow', $from[0], (int) $product->id(), (int) $position, 'markdown');
    }
    $suggestion = $this->similar->suggest($product);
    $lines = [];
    $shown = [];
    foreach ($this->entityTypeManager->getStorage('commerce_product')->loadMultiple(array_keys($suggestion['items'])) as $id => $other) {
      $other = $this->entityRepository->getTranslationFromContext($other);
      if (!$other->access('view')) {
        continue;
      }
      $query = ['ombu_from' => $product->id(), 'ombu_pos' => count($shown)];
      $url = $other->toUrl()->setAbsolute()->setOption('query', $query);
      $lines[] = sprintf('- [%s](%s)', Markdown::linkText((string) $other->label()), $url->toString());
      $shown[$id] = $suggestion['items'][$id];
    }
    if ($shown) {
      $this->log->shown((int) $product->id(), $shown, 'markdown', $suggestion['engine'], $suggestion['fell_back']);
    }
    if ($lines) {
      $response->setContent(sprintf("%s\n\n## %s\n\n%s\n", rtrim((string) $response->getContent()), $this->t('Similar services'), implode("\n", $lines)));
    }
  }

}

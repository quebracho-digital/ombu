<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\ombu\Similar;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A listing read as markdown ends with its similar services, as links.
 *
 * The markdown x402_pay renders has the display's fields only, not the
 * cards under the page (ListingHooks). Agents follow these links as people
 * follow the cards. The markdown page is not page-cached (x402_pay).
 */
final class ListingMarkdownSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  /**
   * Adds the "Similar services" section to a listing's markdown.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    $product = $event->getRequest()->attributes->get('commerce_product');
    if (!$event->isMainRequest() || !$product instanceof ProductInterface || $product->bundle() !== 'listing'
      || $response->getStatusCode() !== 200
      || !str_starts_with((string) $response->headers->get('Content-Type'), 'text/markdown')) {
      return;
    }
    $lines = [];
    foreach (Product::loadMultiple(Similar::ids($product)) as $other) {
      // A title is the seller's text: no brackets to break the link.
      $title = strtr((string) $other->label(), ['[' => '(', ']' => ')', "\n" => ' ']);
      $lines[] = sprintf('- [%s](%s)', $title, $other->toUrl()->setAbsolute()->toString());
    }
    if ($lines) {
      $response->setContent(rtrim((string) $response->getContent()) . "\n\n## " . t('Similar services') . "\n\n" . implode("\n", $lines) . "\n");
    }
  }

}

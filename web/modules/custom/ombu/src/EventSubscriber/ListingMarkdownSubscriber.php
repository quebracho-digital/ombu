<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ombu\Similar;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A listing read as markdown ends with its similar services, as links.
 *
 * The markdown x402_pay renders has the display's fields only, not the
 * block under the page (Similar::build()). Agents follow these links as
 * people follow the cards: the same listings, in the page's language. The
 * markdown page is not page-cached (x402_pay).
 */
final class ListingMarkdownSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly Similar $similar,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  /**
   * A seller's title as the text of a markdown link, as long as on a card.
   *
   * No brackets to break the link, no line breaks or control characters to
   * start a block, no HTML or code spans for the reader to interpret.
   */
  private function linkText(string $title): string {
    $title = preg_replace('/[\p{Cc}\s]+/u', ' ', $title);
    $title = strtr($title, ['[' => '(', ']' => ')', '<' => '‹', '>' => '›', '`' => "'"]);
    return Unicode::truncate(trim($title), 90, TRUE, TRUE);
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
    $lines = [];
    foreach ($this->entityTypeManager->getStorage('commerce_product')->loadMultiple($this->similar->ids($product)) as $other) {
      $other = $this->entityRepository->getTranslationFromContext($other);
      if (!$other->access('view')) {
        continue;
      }
      $title = $this->linkText((string) $other->label());
      $lines[] = sprintf('- [%s](%s)', $title, $other->toUrl()->setAbsolute()->toString());
    }
    if ($lines) {
      $response->setContent(sprintf("%s\n\n## %s\n\n%s\n", rtrim((string) $response->getContent()), $this->t('Similar services'), implode("\n", $lines)));
    }
  }

}

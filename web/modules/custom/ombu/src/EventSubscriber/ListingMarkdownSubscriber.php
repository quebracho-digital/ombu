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
use Drupal\ombu\SimilarLog;
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
      $lines[] = sprintf('- [%s](%s)', $this->linkText((string) $other->label()), $url->toString());
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

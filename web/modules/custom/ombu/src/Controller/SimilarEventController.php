<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ombu\Similar;
use Drupal\ombu\SimilarLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes similar.js's reports: the similar services shown, and the clicks.
 *
 * Small and forgiving: a report that is not JSON, is larger than 4 KB,
 * names an unknown engine, or is about a listing the visitor cannot view,
 * is dropped with a 204 like a good one, so the page never waits on it.
 * Only published listings are kept among the shown and clicked ids. Over
 * the flood limit, a 429.
 */
final class SimilarEventController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Reports a visitor may send an hour: a busy hour of browsing.
   */
  private const FLOOD = 120;

  /**
   * The largest report, in bytes: a shown block's ids fit in a few hundred.
   */
  private const MAX_BYTES = 4096;

  public function __construct(
    private readonly SimilarLog $log,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * Logs one report.
   */
  public function post(Request $request): Response {
    if (!$this->flood->isAllowed('ombu.similar_event', self::FLOOD)) {
      return new Response('', 429);
    }
    $this->flood->register('ombu.similar_event');
    if (!str_starts_with((string) $request->headers->get('Content-Type'), 'application/json') || (int) $request->headers->get('Content-Length') > self::MAX_BYTES) {
      return new Response('', 204);
    }
    $body = (string) $request->getContent();
    $report = strlen($body) <= self::MAX_BYTES ? json_decode($body, TRUE) : NULL;
    $listing = is_array($report) ? $this->listing($report['listing'] ?? NULL) : NULL;
    $engine = (string) ($report['engine'] ?? '');
    if (!$listing || !in_array($engine, [...Similar::VIEWS, 'scores'], TRUE)) {
      return new Response('', 204);
    }
    if (($report['kind'] ?? NULL) === 'shown' && is_array($report['items'] ?? NULL)) {
      // What the page showed; the engine's scores are not known here.
      $ids = $this->log->listings(array_slice($report['items'], 0, 2 * Similar::MAX));
      $this->log->shown((int) $listing->id(), array_fill_keys($ids, NULL), 'page', $engine);
    }
    elseif (($report['kind'] ?? NULL) === 'click' && is_int($report['position'] ?? NULL)) {
      $target = $this->log->listings([$report['target'] ?? NULL]);
      if ($target) {
        $this->log->event('click', (int) $listing->id(), $target[0], $report['position'], 'page', $engine);
      }
    }
    return new Response('', 204);
  }

  /**
   * The listing a report is about, if the visitor may view it.
   */
  private function listing(mixed $id): ?ProductInterface {
    $ids = $this->log->listings([$id]);
    $listing = $ids ? $this->entityTypeManager->getStorage('commerce_product')->load($ids[0]) : NULL;
    return $listing instanceof ProductInterface && $listing->access('view') ? $listing : NULL;
  }

}

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
 * Small and forgiving: a report that does not parse, or about a listing
 * the visitor cannot view, is dropped with a 204 like a good one, so the
 * page never waits on it. Over the flood limit, a 429.
 */
final class SimilarEventController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Reports a visitor may send an hour: a busy hour of browsing.
   */
  private const FLOOD = 600;

  public function __construct(
    private readonly SimilarLog $log,
    private readonly Similar $similar,
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
    $report = json_decode((string) $request->getContent(), TRUE);
    $listing = is_array($report) ? $this->listing($report['listing'] ?? NULL) : NULL;
    if ($listing) {
      $engine = mb_substr(preg_replace('/[^a-z0-9_]/', '', (string) ($report['engine'] ?? '')), 0, 64);
      if (($report['kind'] ?? NULL) === 'shown' && is_array($report['items'] ?? NULL)) {
        // What the page showed, with the scores of what would show now.
        $ids = array_slice(array_values(array_filter(array_map('intval', $report['items']))), 0, 2 * Similar::MAX);
        $scores = $this->similar->suggest($listing)['items'];
        $this->log->shown((int) $listing->id(), array_combine($ids, array_map(fn ($id) => $scores[$id] ?? NULL, $ids)) ?: [], 'page', $engine);
      }
      elseif (($report['kind'] ?? NULL) === 'click' && is_numeric($report['target'] ?? NULL) && is_numeric($report['position'] ?? NULL)) {
        $this->log->event('click', (int) $listing->id(), (int) $report['target'], max(0, (int) $report['position']), 'page', $engine);
      }
    }
    return new Response('', 204);
  }

  /**
   * The listing a report is about, if the visitor may view it.
   */
  private function listing(mixed $id): ?ProductInterface {
    $listing = is_numeric($id) ? $this->entityTypeManager->getStorage('commerce_product')->load((int) $id) : NULL;
    return $listing instanceof ProductInterface && $listing->bundle() === 'listing' && $listing->access('view') ? $listing : NULL;
  }

}

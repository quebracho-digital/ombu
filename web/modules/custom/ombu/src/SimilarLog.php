<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What similar services were shown, why, and what visitors did with them.
 *
 * One row per event in ombu_similar_log, to learn which suggestions work
 * and to find bugs:
 * - shown: the suggestions under a listing, in order, with the engine's
 *   score and Similar's reasons (Similar::explain()) for each.
 * - click: a person clicked one (similar.js).
 * - follow: an agent followed a link of a listing's markdown.
 *
 * Who: a visitor hash, not the IP (sha256 of a monthly salt, the IP and
 * the user agent: the same visitor within a month, no address kept), the
 * user id, the user agent, the language and the referring page without
 * its query. Rows older than KEEP are removed on cron.
 */
final class SimilarLog {

  public const TABLE = 'ombu_similar_log';

  /**
   * How long rows are kept, in seconds: a year.
   */
  public const KEEP = 365 * 86400;

  public function __construct(
    private readonly Connection $database,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LanguageManagerInterface $languageManager,
    private readonly Similar $similar,
  ) {}

  /**
   * Logs the suggestions shown under a listing, and why.
   *
   * @param int $listing
   *   The listing.
   * @param array $items
   *   Suggested listing id => the engine's score, in order.
   * @param string $channel
   *   Where: "page" or "markdown".
   * @param string $engine
   *   Who chose them: a view's name, or "scores" (Similar::suggest()).
   * @param bool $fell_back
   *   Whether a preferred engine was skipped, its server down.
   */
  public function shown(int $listing, array $items, string $channel, string $engine, bool $fell_back = FALSE): void {
    $reasons = array_intersect_key($this->similar->explain($listing), $items);
    $suggestions = [];
    foreach (array_keys($items) as $position => $id) {
      $suggestions[] = [
        'id' => $id,
        'position' => $position,
        'score' => $items[$id],
        'reasons' => $reasons[$id] ?? NULL,
      ];
    }
    $this->write([
      'kind' => 'shown',
      'channel' => $channel,
      'listing_id' => $listing,
      'engine' => $engine,
      'data' => json_encode(['fell_back' => $fell_back, 'suggestions' => $suggestions]),
    ]);
  }

  /**
   * Logs what a visitor did with a suggestion.
   *
   * @param string $kind
   *   What: "click" or "follow".
   * @param int $listing
   *   The listing it was suggested under.
   * @param int $target
   *   The suggested listing.
   * @param int $position
   *   Its place among the suggestions, from 0.
   * @param string $channel
   *   Where: "page" or "markdown".
   * @param string $engine
   *   Who chose it, when known.
   */
  public function event(string $kind, int $listing, int $target, int $position, string $channel, string $engine = ''): void {
    $this->write([
      'kind' => $kind,
      'channel' => $channel,
      'listing_id' => $listing,
      'target_id' => $target,
      'position' => $position,
      'engine' => $engine,
    ]);
  }

  /**
   * Removes rows older than KEEP.
   */
  public function purge(): void {
    $this->database->delete(self::TABLE)->condition('created', $this->time->getRequestTime() - self::KEEP, '<')->execute();
  }

  /**
   * Writes a row with who and when.
   */
  private function write(array $row): void {
    $request = $this->requestStack->getCurrentRequest() ?? new Request();
    $referer = (string) $request->headers->get('Referer');
    $this->database->insert(self::TABLE)->fields($row + [
      'created' => $this->time->getRequestTime(),
      'visitor' => $this->visitor($request),
      'uid' => (int) $this->currentUser->id(),
      'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
      // The page, not its query: a query can carry what is not ours to keep.
      'referer' => mb_substr((string) strtok($referer, '?#'), 0, 255),
    ])->execute();
  }

  /**
   * The visitor's hash for this month: the same visitor, no address kept.
   */
  private function visitor(Request $request): string {
    $month = date('Y-m', $this->time->getRequestTime());
    $salt = $this->state->get('ombu.similar_log.salt');
    if (($salt['month'] ?? NULL) !== $month) {
      $salt = ['month' => $month, 'value' => bin2hex(random_bytes(32))];
      $this->state->set('ombu.similar_log.salt', $salt);
    }
    return hash('sha256', $salt['value'] . '|' . $request->getClientIp() . '|' . $request->headers->get('User-Agent'));
  }

}

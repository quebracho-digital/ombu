<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What similar services were shown, why, and what visitors did with them.
 *
 * One row per event in ombu_similar_log, to learn which suggestions work
 * and to find bugs:
 * - shown: the suggestions under a listing, in order, with the engine's
 *   score when known and Similar's reasons (Similar::explain()) for each.
 * - click: a person clicked one (similar.js).
 * - follow: an agent followed a link of a listing's markdown.
 *
 * Who, pseudonymously: an HMAC of the month, the IP (an IPv6 address by its
 * /64) and the user agent, keyed with the site's hash_salt (settings.php,
 * not the database): the same visitor within a month, no address kept, and
 * no linking across months. Whether they were signed in, not who; the user
 * agent, the language, the referring page without user, query or fragment.
 * A visitor sending Global Privacy Control or Do Not Track is not logged.
 * The same event by the same visitor counts once an hour. Logging never
 * breaks a page: a failed write is logged and dropped. Rows older than
 * KEEP are removed on cron.
 */
final class SimilarLog {

  public const TABLE = 'ombu_similar_log';

  /**
   * How long rows are kept, in seconds: a year.
   */
  public const KEEP = 365 * 86400;

  /**
   * The largest id or position the table holds (int unsigned, kept signed).
   */
  private const MAX_INT = 2147483647;

  /**
   * How long the same event by the same visitor counts once, in seconds.
   */
  private const ONCE = 3600;

  public function __construct(
    private readonly Connection $database,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LanguageManagerInterface $languageManager,
    private readonly Similar $similar,
    private readonly CacheBackendInterface $cache,
    private readonly Settings $settings,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Logs the suggestions shown under a listing, and why.
   *
   * @param int $listing
   *   The listing.
   * @param array $items
   *   Suggested listing id => the engine's score (NULL when not known), in
   *   order.
   * @param string $channel
   *   Where: "page" or "markdown".
   * @param string $engine
   *   Who chose them: a view's name, or "scores" (Similar::suggest()).
   * @param bool $fell_back
   *   Whether a preferred engine was skipped, its server down.
   */
  public function shown(int $listing, array $items, string $channel, string $engine, bool $fell_back = FALSE): void {
    if (!$items || !$this->once("shown:$channel:$listing")) {
      return;
    }
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
    if (!$this->once("$kind:$channel:$listing:$target")) {
      return;
    }
    $this->write([
      'kind' => $kind,
      'channel' => $channel,
      'listing_id' => $listing,
      'target_id' => $target,
      'position' => min(max(0, $position), 2 * Similar::MAX),
      'engine' => $engine,
    ]);
  }

  /**
   * Of these values, the ids of published listings, in their order.
   *
   * What a visitor sends is checked here: a value that is not an id the
   * table can hold, or not a published listing, is left out.
   *
   * @param array $values
   *   Values from a request.
   *
   * @return int[]
   *   Listing ids.
   */
  public function listings(array $values): array {
    $ids = [];
    foreach ($values as $value) {
      if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 && (int) $value <= self::MAX_INT) {
        $ids[] = (int) $value;
      }
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
      return [];
    }
    $live = $this->database->query("SELECT product_id FROM {commerce_product_field_data} WHERE product_id IN (:ids[]) AND type = 'listing' AND status = 1", [':ids[]' => $ids])->fetchCol();
    return array_values(array_intersect($ids, array_map('intval', $live)));
  }

  /**
   * Removes rows older than KEEP, a batch at a time.
   */
  public function purge(): void {
    $before = $this->time->getRequestTime() - self::KEEP;
    do {
      $ids = $this->database->select(self::TABLE, 'l')->fields('l', ['id'])->condition('created', $before, '<')->range(0, 5000)->execute()->fetchCol();
      if ($ids) {
        $this->database->delete(self::TABLE)->condition('id', $ids, 'IN')->execute();
      }
    } while (count($ids) === 5000);
  }

  /**
   * Whether an event of this visitor counts: once an hour, unless opted out.
   */
  private function once(string $event): bool {
    $request = $this->request();
    if ($request->headers->get('Sec-GPC') === '1' || $request->headers->get('DNT') === '1') {
      return FALSE;
    }
    $cid = 'ombu.similar_log:' . hash('sha256', $this->visitor($request) . '|' . $event);
    if ($this->cache->get($cid)) {
      return FALSE;
    }
    $this->cache->set($cid, TRUE, $this->time->getRequestTime() + self::ONCE);
    return TRUE;
  }

  /**
   * Writes a row with who and when; a failure is logged, never thrown.
   */
  private function write(array $row): void {
    $request = $this->request();
    try {
      $this->database->insert(self::TABLE)->fields($row + [
        'created' => $this->time->getRequestTime(),
        'visitor' => $this->visitor($request),
        'authenticated' => (int) $this->currentUser->isAuthenticated(),
        'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
        'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
        'referer' => $this->referer((string) $request->headers->get('Referer')),
      ])->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('Similar services log: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * The visitor this month: an HMAC keyed with the site's hash_salt.
   */
  private function visitor(Request $request): string {
    $ip = (string) $request->getClientIp();
    $packed = @inet_pton($ip);
    // Privacy extensions change an IPv6 address's last 64 bits.
    if ($packed !== FALSE && strlen($packed) === 16) {
      $ip = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
    }
    $month = date('Y-m', $this->time->getRequestTime());
    return hash_hmac('sha256', $month . '|' . $ip . '|' . $request->headers->get('User-Agent'), (string) $this->settings->get('hash_salt'));
  }

  /**
   * The referring page: scheme, host and path only.
   */
  private function referer(string $referer): string {
    $parts = parse_url($referer);
    if (!$parts || !isset($parts['host'])) {
      return '';
    }
    return mb_substr(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? ''), 0, 255);
  }

  /**
   * The request being served.
   */
  private function request(): Request {
    return $this->requestStack->getCurrentRequest() ?? new Request();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\x402_pay\Stop as PaymentsStop;
use Psr\Log\LoggerInterface;

/**
 * Ombú's stop switch: payments first, then the whole site read-only.
 *
 * Stopping pauses payments with x402_pay's switch (the library's flag file,
 * then the module's state), and only then marks the site as stopped. While it
 * is, StopSubscriber refuses every write and every payment with a temporary
 * error, shows no wallet in any response and turns a 402 into a 503.
 * Resuming goes the other way: the site first, then payments.
 *
 * Pausing payments alone stays possible (drush x402:stop): that is not a stop
 * of the site and writes no post. A site stopped keeps refusing payments by
 * itself, even if someone resumes x402_pay's switch alone.
 *
 * Each stop and each resume is a blog post (StopPosts), from templates.
 * What it does not stop: Drush, cron and the importers, which write as staff.
 */
class Stop {

  /**
   * The cache tag of everything that differs while stopped.
   */
  public const TAG = 'ombu_stop';

  private const STATE = 'ombu.stopped';

  public function __construct(
    private readonly PaymentsStop $payments,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $tags,
    private readonly LoggerInterface $logger,
    private readonly StopPosts $posts,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Whether the site is stopped. Unsure (state unreadable), it is.
   */
  public function engaged(): bool {
    try {
      return $this->state->get(self::STATE) !== NULL;
    }
    catch (\Throwable) {
      return TRUE;
    }
  }

  /**
   * When it was stopped, why, and by which user; NULL when it is not.
   *
   * @return array{time: int, why: string, uid: int}|null
   *   The note of the stop in force.
   */
  public function note(): ?array {
    try {
      $note = $this->state->get(self::STATE);
    }
    catch (\Throwable) {
      return NULL;
    }
    return is_array($note) ? [
      'time' => (int) ($note['time'] ?? 0),
      'why' => (string) ($note['why'] ?? ''),
      'uid' => (int) ($note['uid'] ?? 0),
    ] : NULL;
  }

  /**
   * Stops: payments, then the site. Both are tried whatever the first does.
   *
   * @param string $why
   *   The reason. Public: it goes into the blog post.
   * @param array<string, string> $reasons
   *   The reason in other languages, by language code; $why where missing.
   *
   * @return array{payments: array{library: ?string, module: ?string}, site: ?string, post: ?int}
   *   Why each switch could not be set (NULL when it is), and the post's id
   *   (NULL: none written).
   */
  public function engage(string $why = '', array $reasons = []): array {
    $was = $this->engaged();
    $done = ['payments' => $this->payments->engage($why), 'site' => NULL, 'post' => NULL];
    try {
      if (!$was || $this->note() === NULL) {
        $this->state->set(self::STATE, [
          'time' => $this->time->getCurrentTime(),
          'why' => $why,
          'uid' => (int) $this->currentUser->id(),
        ]);
      }
    }
    catch (\Throwable $e) {
      $done['site'] = $e->getMessage();
    }
    $this->flush();
    $this->logger->warning('Ombú stopped (read-only) by user @uid: @why', [
      '@uid' => (int) $this->currentUser->id(),
      '@why' => $why === '' ? '(no reason given)' : $why,
    ]);
    if (!$was && $done['site'] === NULL) {
      $done['post'] = $this->posts->publish(TRUE, $why, $reasons);
    }
    return $done;
  }

  /**
   * Resumes: the site, then payments.
   *
   * @param string $why
   *   A note for the post; public.
   * @param array<string, string> $reasons
   *   The note in other languages, by language code.
   *
   * @return array{payments: array{library: ?string, module: ?string}, site: ?string, post: ?int}
   *   As engage(): what is still set and why, and the post's id.
   */
  public function release(string $why = '', array $reasons = []): array {
    $was = $this->engaged();
    $done = ['payments' => ['library' => NULL, 'module' => NULL], 'site' => NULL, 'post' => NULL];
    try {
      $this->state->delete(self::STATE);
    }
    catch (\Throwable $e) {
      $done['site'] = $e->getMessage();
    }
    $this->flush();
    $done['payments'] = $this->payments->release();
    $this->logger->notice('Ombú resumed by user @uid', ['@uid' => (int) $this->currentUser->id()]);
    if ($was && $done['site'] === NULL) {
      $done['post'] = $this->posts->publish(FALSE, $why, $reasons);
    }
    return $done;
  }

  /**
   * Drops what was cached for the other state: pages and rendered pieces.
   */
  private function flush(): void {
    try {
      $this->tags->invalidateTags([self::TAG, 'http_response']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Ombú stop: the caches could not be cleared: @error', ['@error' => $e->getMessage()]);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch_analytics;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Identifies the visitor for Meilisearch analytics, pseudonymously.
 *
 * Authenticated users get a keyed hash of their user ID, so Meilisearch never
 * receives the ID itself. Anonymous visitors are identified by the random
 * "quebracho_meilisearch_uid" cookie the click tracker sets.
 */
class UserIdResolver {

  /**
   * The cookie holding the anonymous visitor ID.
   */
  public const COOKIE = 'quebracho_meilisearch_uid';

  /**
   * Constructs a UserIdResolver.
   */
  public function __construct(
    protected AccountInterface $currentUser,
    protected RequestStack $requestStack,
    protected Settings $settings,
  ) {}

  /**
   * Returns the visitor ID, or NULL if the visitor cannot be identified yet.
   */
  public function getUserId(): ?string {
    if ($this->currentUser->isAuthenticated()) {
      return 'u' . substr(hash_hmac('sha256', 'uid:' . $this->currentUser->id(), $this->settings->getHashSalt()), 0, 32);
    }
    $cookie = $this->requestStack->getCurrentRequest()?->cookies->get(self::COOKIE);
    if (is_string($cookie) && preg_match('/^[0-9a-f]{32}$/', $cookie)) {
      return 'a' . $cookie;
    }
    return NULL;
  }

}

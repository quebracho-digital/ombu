<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Api;

/**
 * Thrown for every Meilisearch failure.
 *
 * Covers API errors, network errors, timeouts and tasks that finished with
 * the "failed" status.
 */
class MeilisearchApiException extends \RuntimeException {

  /**
   * Constructs a MeilisearchApiException.
   *
   * @param string $message
   *   The error message.
   * @param string|null $errorCode
   *   The Meilisearch error code (for example "index_not_found"), if known.
   * @param \Throwable|null $previous
   *   The original exception, if any.
   */
  public function __construct(string $message, protected ?string $errorCode = NULL, ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Returns the Meilisearch error code, if known.
   *
   * @see https://www.meilisearch.com/docs/reference/errors/error_codes
   */
  public function getErrorCode(): ?string {
    return $this->errorCode;
  }

}

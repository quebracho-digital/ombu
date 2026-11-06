<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Filter;

use Drupal\search_api\SearchApiException;

/**
 * Thrown when a condition cannot be expressed as a Meilisearch filter.
 *
 * Dropping such a condition would silently widen the results, which is worse
 * than failing: think of access conditions.
 */
class MeilisearchFilterException extends SearchApiException {
}

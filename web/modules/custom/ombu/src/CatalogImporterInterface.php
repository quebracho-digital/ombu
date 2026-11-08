<?php

declare(strict_types=1);

namespace Drupal\ombu;

/**
 * A catalogue Ombú takes listings from (Circle, Bazaar, PayAI).
 *
 * Every importer owns one source: it creates, updates and retires only the
 * listings whose `field_source` is its own, so running one never touches the
 * listings of another. A deleted listing stays deleted, whichever source it
 * came from.
 */
interface CatalogImporterInterface {

  /**
   * The value of `field_source` (and the market) of the listings it imports.
   */
  public function source(): string;

  /**
   * Imports the catalogue; returns the count of each outcome.
   *
   * @param int $max
   *   Stop after this many resources (0 = all). A partial run never retires
   *   anything: only a complete one knows what disappeared.
   *
   * @throws \RuntimeException
   *   When another import of the same source is already running.
   */
  public function import(int $max = 0): array;

}

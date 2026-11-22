<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Parser;

use Drupal\ombu\Catalog\CatalogRun;

/**
 * Parses a page of the Circle Agent Marketplace's Discovery API.
 *
 * Circle's entries carry their method, provider and schemas under
 * `metadata`. An entry is `skipped` when it has no resource or no `exact`
 * requirement (the price is the exact one's); every other way it is paid
 * travels with it. The entry itself goes on as `item`: the processor hashes
 * it whole, as CircleCatalogImporter did, so a listing that importer wrote
 * is `unchanged` here.
 *
 * Unlike the importer, the same resource listed twice in one run is read
 * once (the base class), and `max` counts the skipped entries too, as the
 * importer's sum of every outcome did.
 *
 * @FeedsParser(
 *   id = "ombu_circle",
 *   title = @Translation("Circle Agent Marketplace"),
 *   description = @Translation("Parses the resources of Circle's x402 Discovery API."),
 * )
 */
final class CircleParser extends CatalogParserBase {

  /**
   * {@inheritdoc}
   *
   * @return array|null
   *   Keys: key, url, method, accepts (every complete requirement), exact
   *   (those of the exact scheme) and item (the entry as Circle sent it).
   */
  public static function normalize(array $item): ?array {
    // Every way it is paid, whatever the scheme (upto, batch-settlement):
    // its chains are all of theirs. The price is the exact ones'.
    $accepts = array_values(array_filter(is_array($item['accepts'] ?? NULL) ? $item['accepts'] : [], fn($a) => is_array($a) && isset($a['scheme'], $a['network'], $a['amount'], $a['payTo'])));
    $exact = array_values(array_filter($accepts, fn($a) => $a['scheme'] === 'exact'));
    if (!$exact || empty($item['resource']) || !is_string($item['resource'])) {
      return NULL;
    }
    $method = strtoupper((string) ($item['metadata']['method'] ?? 'GET'));
    return [
      'key' => $method . ' ' . $item['resource'],
      'url' => $item['resource'],
      'method' => $method,
      'accepts' => $accepts,
      'exact' => $exact,
      'item' => $item,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function unusable(): string {
    return 'skipped';
  }

  /**
   * {@inheritdoc}
   */
  protected function counted(CatalogRun $run): int {
    return $run->read + ($run->counts['skipped'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingSources() {
    return array_map(fn($label) => ['label' => $label], [
      'key' => $this->t('Method and URL'),
      'url' => $this->t('Resource URL'),
      'method' => $this->t('HTTP method'),
    ]);
  }

}

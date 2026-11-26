<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Parser;

use Drupal\ombu\Catalog\CatalogRun;
use Drupal\ombu\Chains;

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
    $resource = $item['resource'] ?? NULL;
    if (!is_string($resource) || !preg_match('#^https://[^/\s]+#i', $resource)) {
      return NULL;
    }
    $announced = $item['accepts'] ?? NULL;
    if (!is_array($announced)) {
      return NULL;
    }
    // Circle's v1 endpoint answers `maxAmountRequired`, v2 answers `amount`:
    // read both, with `amount` as the one the price comes from (the feed
    // reads v2 today). A poisoned entry must not reach the processor: a
    // non-numeric amount throws in Calculator::divide and the whole run stops
    // retiring. Validate here and count the entry as skipped; run->fail()
    // stays for real errors.
    $announced = array_map(static function ($a) {
      if (is_array($a) && !isset($a['amount']) && isset($a['maxAmountRequired'])) {
        $a['amount'] = $a['maxAmountRequired'];
      }
      return $a;
    }, $announced);
    $accepts = array_values(array_filter($announced, fn($a) => is_array($a)
      && isset($a['scheme'], $a['network'], $a['amount'], $a['payTo'])
      && is_string($a['scheme']) && is_string($a['network']) && is_string($a['payTo'])
      && is_string($a['amount']) && ctype_digit($a['amount'])
      && isset(Chains::NAMES[$a['network']])
      && isset($a['asset']) && is_string($a['asset']) && $a['asset'] !== ''));
    $exact = array_values(array_filter($accepts, fn($a) => $a['scheme'] === 'exact'));
    if (!$exact) {
      return NULL;
    }
    $metadata = $item['metadata'] ?? NULL;
    $method = is_array($metadata) && isset($metadata['method']) && is_string($metadata['method'])
      ? strtoupper($metadata['method'])
      : 'GET';
    return [
      'key' => $method . ' ' . $resource,
      'url' => $resource,
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

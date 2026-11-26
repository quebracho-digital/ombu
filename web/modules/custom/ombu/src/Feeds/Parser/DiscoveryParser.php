<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Parser;

/**
 * Parses a CDP Bazaar or PayAI discovery page.
 *
 * Both answer `items[]` of `{resource, accepts, description, ...}` and differ
 * in where they put the method and the schemas; the
 * `extensions.bazaar.info` both carry is the common place. An entry without
 * an https resource or without a complete requirement is `unsupported`.
 *
 * normalize() is DiscoveryCatalogImporter::normalize(), moved here: the
 * parser is where a catalogue's shape becomes Ombú's.
 *
 * @FeedsParser(
 *   id = "ombu_discovery",
 *   title = @Translation("x402 discovery (Bazaar, PayAI)"),
 *   description = @Translation("Parses the resources of a CDP Bazaar or PayAI discovery page."),
 * )
 */
final class DiscoveryParser extends CatalogParserBase {

  /**
   * {@inheritdoc}
   *
   * @return array|null
   *   Keys: key, url, method, accepts, title, description, provider, website,
   *   tags, input, output, updated and hash.
   */
  public static function normalize(array $item): ?array {
    $url = $item['resource'] ?? NULL;
    if (!is_string($url) || !preg_match('#^https://[^/\s]+#i', $url)) {
      return NULL;
    }
    // A poisoned entry must not throw: a string where a list belongs is a
    // TypeError that escapes Feeds' \Exception handling and locks the feed.
    $announced = $item['accepts'] ?? NULL;
    if (!is_array($announced)) {
      return NULL;
    }
    $accepts = array_values(array_filter($announced, fn($a) => is_array($a)
      && isset($a['scheme'], $a['network'], $a['payTo'])
      && (isset($a['amount']) || isset($a['maxAmountRequired']))));
    if (!$accepts) {
      return NULL;
    }
    $extensions = $item['extensions'] ?? [];
    if (!is_array($extensions)) {
      return NULL;
    }
    $bazaar = $extensions['bazaar'] ?? [];
    if (!is_array($bazaar)) {
      return NULL;
    }
    $info = $bazaar['info'] ?? [];
    if (!is_array($info)) {
      return NULL;
    }
    $input = $info['input'] ?? [];
    if (!is_array($input)) {
      $input = [];
    }
    $infoMethod = $input['method'] ?? NULL;
    $itemMethod = $item['method'] ?? NULL;
    $method = strtoupper(is_string($infoMethod) ? $infoMethod : (is_string($itemMethod) ? $itemMethod : 'GET'));
    $host = (string) parse_url($url, PHP_URL_HOST);
    $description = $item['description'] ?? '';
    $description = is_scalar($description) ? trim((string) $description) : '';
    $serviceName = $item['serviceName'] ?? '';
    $serviceName = is_scalar($serviceName) ? trim((string) $serviceName) : '';
    $provider = $serviceName ?: $host;
    $lastUpdated = $item['lastUpdated'] ?? '';
    $lastUpdated = is_scalar($lastUpdated) ? (string) $lastUpdated : '';
    // Volatile fields (quality counters, the clock) do not make it "changed".
    $stable = $item;
    unset($stable['quality'], $stable['lastUpdated']);
    return [
      'key' => $method . ' ' . $url,
      'url' => $url,
      'method' => $method,
      'accepts' => $accepts,
      'title' => mb_substr($description !== '' ? $description : $provider . ' ' . (parse_url($url, PHP_URL_PATH) ?: ''), 0, 255),
      'description' => $description,
      'provider' => $provider,
      'website' => 'https://' . $host,
      'tags' => array_slice(array_values(array_filter(array_map(
        fn($t) => mb_substr(trim((string) $t), 0, 40),
        array_filter((array) ($item['tags'] ?? []), 'is_scalar'),
      ))), 0, 10),
      'input' => ($info['input'] ?? $item['inputSchema'] ?? NULL) ?: NULL,
      'output' => ($info['output'] ?? $item['outputSchema'] ?? NULL) ?: NULL,
      'updated' => $lastUpdated,
      'hash' => json_encode($stable),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function unusable(): string {
    return 'unsupported';
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingSources() {
    return array_map(fn($label) => ['label' => $label], [
      'key' => $this->t('Method and URL'),
      'url' => $this->t('Resource URL'),
      'method' => $this->t('HTTP method'),
      'title' => $this->t('Title'),
      'description' => $this->t('Description'),
      'provider' => $this->t('Provider'),
      'website' => $this->t('Provider site'),
      'tags' => $this->t('Tags'),
      'updated' => $this->t('Last updated'),
    ]);
  }

}

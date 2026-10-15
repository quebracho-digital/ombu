<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;

/**
 * Asks a seller's x402 endpoint what it charges: its 402, guarded like any
 * fetch of a URL someone typed (public addresses only, pinned, no redirect).
 */
class SellerProbe extends PageFetcher {

  /**
   * The endpoint's payment requirements: [resource, accepts].
   *
   * @throws \RuntimeException
   *   Not public, not a 402, or no requirements in it.
   */
  public function requirements(string $url, string $method): array {
    $ip = $this->assertPublicUrl($url);
    if (!$this->canPin()) {
      throw PageFetchException::noCurl();
    }
    $parts = parse_url($url);
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    try {
      $response = $this->httpClient->request($method, $url, [
        'timeout' => 15,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'headers' => ['Accept' => 'application/json'] + ($method === 'POST' ? ['Content-Type' => 'application/json'] : []),
        'body' => $method === 'POST' ? '{}' : NULL,
        'curl' => [CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $ip]],
      ]);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('The endpoint could not be reached: ' . $e->getMessage(), 0, $e);
    }
    if ($response->getStatusCode() !== 402) {
      throw new \RuntimeException(sprintf('The endpoint answered %d, not 402 Payment Required.', $response->getStatusCode()));
    }
    // x402 v2 in its header, v1 (and most v2 servers too) in the body.
    $header = $response->getHeaderLine('PAYMENT-REQUIRED');
    $required = $header !== '' ? json_decode((string) base64_decode($header, TRUE), TRUE) : NULL;
    $required ??= json_decode(substr((string) $response->getBody(), 0, 65536), TRUE);
    if (!is_array($required['accepts'] ?? NULL) || !$required['accepts']) {
      throw new \RuntimeException('The 402 lists no payment requirements (accepts).');
    }
    return [$required['resource']['url'] ?? $url, $required['accepts']];
  }

}

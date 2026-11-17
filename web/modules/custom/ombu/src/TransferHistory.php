<?php

declare(strict_types=1);

namespace Drupal\ombu;

use GuzzleHttp\ClientInterface;

/**
 * The incoming USDC transfers of a payTo, with what coherence needs.
 *
 * `ResourceVerifier` counts them; the largest amount and the newest day let
 * an importer demand transfers that look like real sales of a listing (one
 * of at least its price, a recent one). The closure answers
 * ['count' => int, 'maxAmount' => int|null, 'newest' => int|null]: amounts
 * in atomic units of the asset (6 decimals for USDC), days as Unix
 * timestamps.
 */
final class TransferHistory {

  /**
   * Builds the closure, through Alchemy's asset transfers.
   *
   * Asks for the 100 newest incoming transfers (newest first): a wallet that
   * sold more than 100 times still shows its latest sale, which is what the
   * window looks at. The count is capped at 100 either way.
   *
   * @param \GuzzleHttp\ClientInterface $http
   *   The HTTP client.
   * @param string $key
   *   The Alchemy API key.
   *
   * @return \Closure
   *   fn(network, asset, payTo): the count, maxAmount and newest. Throws
   *   when it cannot ask (the verifier reports unchecked).
   */
  public static function closure(ClientInterface $http, string $key): \Closure {
    return function (string $network, string $asset, string $payTo) use ($http, $key): array {
      $chain = str_starts_with($network, 'eip155:') ? (int) substr($network, 7) : 0;
      if (!isset(ResourceVerifierFactory::USDC[$chain])) {
        throw new \RuntimeException("No index for $network.");
      }
      $response = $http->request('POST', 'https://' . ResourceVerifierFactory::USDC[$chain][1] . ".g.alchemy.com/v2/$key", [
        'timeout' => 15,
        'json' => [
          'jsonrpc' => '2.0',
          'id' => 1,
          'method' => 'alchemy_getAssetTransfers',
          'params' => [[
            'toAddress' => $payTo,
            'contractAddresses' => [$asset],
            'category' => ['erc20'],
            'maxCount' => '0x64',
            'excludeZeroValue' => TRUE,
            'order' => 'desc',
            'withMetadata' => TRUE,
          ],
          ],
        ],
      ]);
      $transfers = json_decode((string) $response->getBody(), TRUE)['result']['transfers'] ?? NULL;
      if (!is_array($transfers)) {
        throw new \RuntimeException('No answer.');
      }
      $maxAmount = NULL;
      $newest = NULL;
      foreach ($transfers as $transfer) {
        // The atomic amount is rawContract.value, in hex; `value` is a
        // decimal float and loses precision.
        $raw = (string) ($transfer['rawContract']['value'] ?? '');
        if (preg_match('/^0x[0-9a-f]{1,15}$/i', $raw)) {
          $amount = (int) hexdec($raw);
          $maxAmount = $maxAmount === NULL ? $amount : max($maxAmount, $amount);
        }
        // A transfer without a readable timestamp is not fresh evidence.
        $ts = strtotime((string) ($transfer['metadata']['blockTimestamp'] ?? ''));
        if ($ts !== FALSE) {
          $newest = $newest === NULL ? $ts : max($newest, $ts);
        }
      }
      return ['count' => count($transfers), 'maxAmount' => $maxAmount, 'newest' => $newest];
    };
  }

}

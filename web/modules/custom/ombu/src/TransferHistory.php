<?php

/**
 * @file
 * The transfers a payTo has received, with what coherence needs: the
 * amount and the day of each incoming USDC transfer, capped at 100.
 * `ResourceVerifierFactory::transfers()` counts them; this one answers with
 * detail, so an importer can demand transfers that look like real sales of
 * this listing (at least its price, not two years stale).
 */

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\ombu\ResourceVerifierFactory;
use GuzzleHttp\ClientInterface;

/**
 * A closure fn(string $network, string $asset, string $payTo): array of
 * ['count' => int, 'minAmount' => int|null, 'newest' => int|null], where the
 * amounts are atomic units of the asset (6 decimals for USDC) and the days
 * are Unix timestamps.
 */
final class TransferHistory {

  /**
   * The shape of what closure() answers, with nothing in it.
   *
   * @return array{count: int, minAmount: int|null, newest: int|null}
   */
  public static function empty(): array {
    return ['count' => 0, 'minAmount' => NULL, 'newest' => NULL];
  }

  /**
   * Builds the closure, through Alchemy's asset transfers.
   *
   * @param \GuzzleHttp\ClientInterface $http
   *   The HTTP client.
   * @param string $key
   *   The Alchemy API key.
   *
   * @return \Closure
   *   fn(network, asset, payTo): array{count: int, minAmount: ?int, newest: ?int}
   *   Throws when it cannot ask (the verifier reports unchecked).
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
            // The oldest first, so count covers history, not only the tail.
            'order' => 'asc',
            'withMetadata' => TRUE,
          ],
          ],
        ],
      ]);
      $transfers = json_decode((string) $response->getBody(), TRUE)['result']['transfers'] ?? NULL;
      if (!is_array($transfers)) {
        throw new \RuntimeException('No answer.');
      }
      $minAmount = NULL;
      $newest = NULL;
      foreach ($transfers as $transfer) {
        // Alchemy gives hex amounts ('0x…') and ISO block timestamps.
        $amount = (int) hexdec((string) ($transfer['raw'] ?? '0'));
        $minAmount = $minAmount === NULL ? $amount : min($minAmount, $amount);
        $ts = strtotime((string) ($transfer['metadata']['blockTimestamp'] ?? ''));
        if ($ts !== FALSE) {
          $newest = $newest === NULL ? $ts : max($newest, $ts);
        }
        // A transfer without a readable timestamp is not fresh evidence; its
        // amount still counts.
      }
      return ['count' => count($transfers), 'minAmount' => $minAmount, 'newest' => $newest];
    };
  }

}

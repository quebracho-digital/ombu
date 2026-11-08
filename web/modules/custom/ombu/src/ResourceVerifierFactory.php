<?php

declare(strict_types=1);

namespace Drupal\ombu;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Quebracho\X402\Networks;
use Quebracho\X402\Rpc;
use Quebracho\X402\TokenBlacklist;

/**
 * Builds the ResourceVerifier the importers and the survey share.
 *
 * Mainnet USDC is asked on chain through Alchemy (freeze list and incoming
 * transfers); OFAC's oracle is asked on Ethereum. Arc mainnet and Solana are
 * not in USDC: no verified contract or function here, so a resource paid only
 * there is never "pass" (it is "unchecked" or fails the network filter).
 */
final class ResourceVerifierFactory {

  /**
   * Mainnet USDC checked on chain: chain id => [asset, Alchemy subdomain].
   */
  public const USDC = [
    1 => ['0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 'eth-mainnet'],
    8453 => ['0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'base-mainnet'],
    137 => ['0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 'polygon-mainnet'],
    42161 => ['0xaf88d065e77c8cc2239327c5edb3a432268e5831', 'arb-mainnet'],
    10 => ['0x0b2c639c533813f4aa9d7837caf62653d097ff85', 'opt-mainnet'],
    43114 => ['0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e', 'avax-mainnet'],
  ];

  /**
   * The `network|asset` pairs a verifier can judge: each chain's own USDC.
   *
   * @return string[]
   *   Lower case, such as `eip155:8453|0x833589fcd6edb6e08f4c7c32d4f71b54bda02913`.
   */
  public static function assets(): array {
    $pairs = [];
    foreach (self::USDC as $chain => [$asset]) {
      $pairs[] = strtolower("eip155:$chain|$asset");
    }
    return $pairs;
  }

  /**
   * The CAIP-2 networks a verifier can judge.
   *
   * @return string[]
   *   Such as `eip155:8453`.
   */
  public static function networks(): array {
    return array_map(fn($chain) => "eip155:$chain", array_keys(self::USDC));
  }

  /**
   * Builds the verifier, asking Alchemy's nodes.
   *
   * @throws \RuntimeException
   *   Without ALCHEMY_API_KEY: the blacklist and the activity need a node.
   */
  public static function create(ClientInterface $http, ?string $key = NULL): ResourceVerifier {
    $key ??= (string) getenv('ALCHEMY_API_KEY');
    if ($key === '') {
      throw new \RuntimeException('ALCHEMY_API_KEY is not set (.ddev/.env): the blacklist and the activity need a node.');
    }
    $tokens = [];
    foreach (self::USDC as $chain => [$asset, $sub]) {
      Networks::add($chain, $asset, 'USD Coin', '2', 6, "eip155:$chain", "https://$sub.g.alchemy.com/v2/$key");
      $tokens["eip155:$chain|$asset"] = 'isBlacklisted';
    }
    $factory = new HttpFactory();
    return new ResourceVerifier(
      new SellerProbe($http),
      new TokenBlacklist($http, $factory, $factory, FALSE, $tokens),
      new Rpc($http, $factory, $factory, 'https://eth-mainnet.g.alchemy.com/v2/' . $key),
      self::transfers($http, $key),
      $tokens,
    );
  }

  /**
   * USDC transfers payTo received, through Alchemy (capped at 100).
   */
  private static function transfers(ClientInterface $http, string $key): \Closure {
    return function (string $network, string $asset, string $payTo) use ($http, $key): int {
      $chain = str_starts_with($network, 'eip155:') ? (int) substr($network, 7) : 0;
      if (!isset(self::USDC[$chain])) {
        throw new \RuntimeException("No index for $network.");
      }
      $response = $http->request('POST', 'https://' . self::USDC[$chain][1] . ".g.alchemy.com/v2/$key", [
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
          ],
          ],
        ],
      ]);
      $transfers = json_decode((string) $response->getBody(), TRUE)['result']['transfers'] ?? NULL;
      return is_array($transfers) ? count($transfers) : throw new \RuntimeException('No answer.');
    };
  }

}

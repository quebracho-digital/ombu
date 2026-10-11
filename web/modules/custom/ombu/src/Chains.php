<?php

declare(strict_types=1);

namespace Drupal\ombu;

/**
 * Chain names for people; agents keep the CAIP-2 ids.
 */
final class Chains {

  /**
   * Every chain the Circle catalogue uses, plus Arc testnet (ours).
   */
  public const NAMES = [
    'eip155:5042' => 'Arc',
    'eip155:5042002' => 'Arc testnet',
    'eip155:1' => 'Ethereum',
    'eip155:8453' => 'Base',
    'eip155:84532' => 'Base Sepolia',
    'eip155:137' => 'Polygon',
    'eip155:80002' => 'Polygon Amoy',
    'eip155:42161' => 'Arbitrum',
    'eip155:10' => 'Optimism',
    'eip155:43114' => 'Avalanche',
    'eip155:130' => 'Unichain',
    'eip155:146' => 'Sonic',
    'eip155:1329' => 'Sei',
    'eip155:480' => 'World Chain',
    'eip155:999' => 'HyperEVM',
    'eip155:196' => 'X Layer',
    'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' => 'Solana',
    'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1' => 'Solana devnet',
  ];

  /**
   * The chain's name, or its id when unknown.
   */
  public static function name(string $id): string {
    return self::NAMES[$id] ?? $id;
  }

}

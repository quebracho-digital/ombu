<?php

/**
 * @file
 * Buys one x402 call and prints what came back, with its transaction.
 *
 * Run (BUYER_PRIVATE_KEY from scripts/.env, testnet, or .env.mainnet):
 *   ddev exec bash -c 'set -a; . scripts/.env; drush php:script ../scripts/buy.php -- <url> ["<json input>"]'
 */

use Drupal\ombu\Scripts\Buyer;

require_once __DIR__ . '/lib/buyer.php';

[$url, $input] = $extra + [1 => '{}'];
if (!isset($url)) {
  throw new \InvalidArgumentException('Usage: buy.php -- <url> ["<json input>"]');
}
$buyer = new Buyer();
$paid = $buyer->pay($url, json_decode($input, TRUE) ?? throw new \InvalidArgumentException('The input is not JSON.'));
echo "buyer {$buyer->wallet->address()}\n";
echo "HTTP {$paid['status']}, " . ($paid['tx'] ? 'paid ' . $paid['amount'] . ' (atomic), ' . Buyer::explorer($paid['network'], $paid['tx']) : 'not charged') . "\n";
echo $paid['body'], "\n";

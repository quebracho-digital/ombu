<?php

/**
 * @file
 * A buyer agent's round in the mall: reads /market.json and buys every
 * service Ombú sells itself, on the network the site charges, each with an
 * input built from its schema.
 *
 * Run (BUYER_PRIVATE_KEY from scripts/.env, or .env.mainnet):
 *   ddev exec bash -c 'set -a; . scripts/.env; drush php:script ../scripts/buy-ours.php -- [site]'
 */

use Drupal\ombu\Scripts\Buyer;
use GuzzleHttp\Client;

require_once __DIR__ . '/lib/buyer.php';

$site = rtrim($extra[0] ?? 'https://ombu.ddev.site', '/');
$catalogue = json_decode((string) (new Client())->get("$site/market.json", ['query' => ['seller' => 'ours']])->getBody(), TRUE);
$examples = ['url' => 'https://www.arc.io', 'claim' => 'USDC is issued by Circle.'];
$buyer = new Buyer();
echo "buyer {$buyer->wallet->address()}\n";
foreach ($catalogue['items'] as $item) {
  $input = array_intersect_key($examples, $item['metadata']['input']['properties'] ?? []);
  $paid = $buyer->pay($item['resource'], $input);
  printf("%-45s HTTP %d  %s\n", mb_substr($item['metadata']['description'], 0, 45), $paid['status'], $paid['tx'] ? Buyer::explorer($paid['network'], $paid['tx']) : 'not charged');
}

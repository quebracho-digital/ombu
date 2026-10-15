<?php

/**
 * @file
 * A buyer reviews what it bought: signs in with its wallet (SIWE), then
 * POST /market/listing/{id}/reviews with the purchase's tx. Ombú accepts it
 * only if that tx bought this listing from this wallet.
 *
 * Run (BUYER_PRIVATE_KEY in the environment):
 *   ddev drush php:script ../scripts/review.php -- <listing id> <tx> <rating 1-5> "<review>" [site]
 */

use Drupal\ombu\Scripts\Buyer;

require_once __DIR__ . '/lib/buyer.php';

[$id, $tx, $rating, $body, $site] = $extra + [4 => 'https://ombu.quebracho.digital'];
if (!isset($body)) {
  throw new \InvalidArgumentException('Usage: review.php -- <listing id> <tx> <rating 1-5> "<review>" [site]');
}
echo json_encode((new Buyer())->review(rtrim($site, '/'), $id, $tx, (int) $rating, $body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

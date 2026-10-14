<?php

/**
 * @file
 * Creates the store (if missing) and Quebracho's own verified listings.
 * Idempotent: listings are keyed by SKU.
 *
 * Each listing has its own seller wallet: a key `seller_<sku>` read from the
 * environment variable SELLER_KEY_<SKU> (in the git-ignored .ddev/.env).
 * Sales go to OMBU_SELLER_ADDRESS when set (a cold wallet; the key then
 * only signs ping), else to that key. The network is the price field's.
 * Price, payTo and the agent door come from x402_pay, never typed here.
 *
 * Run: ddev drush php:script ../scripts/seed-listings.php
 */

use Drupal\commerce_price\Price;
use Drupal\key\Entity\Key;

$etm = \Drupal::entityTypeManager();
$store = $etm->getStorage('commerce_store')->loadDefault();
if (!$store) {
  $store = $etm->getStorage('commerce_store')->create([
    'type' => 'online', 'uid' => 1, 'name' => 'Ombú', 'mail' => 'ombu@quebracho.digital',
    'default_currency' => 'USD', 'is_default' => TRUE,
    'address' => ['country_code' => 'AR', 'administrative_area' => 'C', 'locality' => 'Buenos Aires', 'postal_code' => '1000', 'address_line1' => '-'],
  ]);
  $store->save();
}

$term = function (string $vid, string $name) use ($etm): int {
  $storage = $etm->getStorage('taxonomy_term');
  $found = $storage->loadByProperties(['vid' => $vid, 'name' => $name]);
  $t = $found ? reset($found) : $storage->create(['vid' => $vid, 'name' => $name]);
  $t->isNew() && $t->save();
  return (int) $t->id();
};

$listings = [
  ['ping', 'Ping: the cheapest thing in the mall', '0.005', 'Pay half a cent and get a signed timestamp back. Exists to test a wallet, an agent or a checkout end to end.', 'INFRASTRUCTURE', ['type' => 'object', 'properties' => new \stdClass()], ['x402', 'test']],
  ['terms', 'Terms of service and privacy analysis', '0.01', 'Reads a terms of service or privacy policy and reports what matters to you or your agent (data sharing, arbitration, auto-renewal, liability…), each point quoting the clause it comes from. Quotes not found in the text are dropped.', 'COMPLIANCE', ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'format' => 'uri'], 'text' => ['type' => 'string']]], ['compliance', 'legal', 'privacy', 'vendors']],
  ['factcheck', 'Fact-check a claim', '0.02', 'Returns a verdict on one claim: supported, refuted or not enough information. For now the model judges on its own, without web sources; the answer says so.', 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'required' => ['claim'], 'properties' => ['claim' => ['type' => 'string']]], ['fact-check', 'research']],
  ['markdown', 'Web page to clean markdown', '0.01', 'Fetches a public URL and returns its main content as markdown, without navigation or ads.', 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'required' => ['url'], 'properties' => ['url' => ['type' => 'string', 'format' => 'uri']]], ['scraping', 'markdown']],
];

$variations = $etm->getStorage('commerce_product_variation');
$products = $etm->getStorage('commerce_product');
foreach ($listings as [$sku, $title, $price, $desc, $cat, $schema, $tags]) {
  $env = 'SELLER_KEY_' . strtoupper($sku);
  if (!getenv($env)) {
    throw new \RuntimeException("Set $env to the seller's private key.");
  }
  if (!Key::load("seller_$sku")) {
    Key::create(['id' => "seller_$sku", 'label' => "Seller: $title", 'key_type' => 'authentication', 'key_provider' => 'env', 'key_provider_settings' => ['env_variable' => $env, 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]])->save();
  }
  $found = $variations->loadByProperties(['sku' => "ours-$sku"]);
  $v = $found ? reset($found) : $variations->create(['type' => 'listing', 'sku' => "ours-$sku", 'status' => 1]);
  $v->setPrice(new Price($price, 'USD'))->save();
  $p = $v->getProduct() ?? $products->create(['type' => 'listing', 'stores' => [$store], 'variations' => [$v]]);
  $p->setTitle($title);
  $p->set('body', ['value' => $desc, 'format' => 'plain_text']);
  foreach ([
    'field_provider' => 'Quebracho',
    'field_docs_url' => 'https://quebracho.digital',
    'field_http_method' => 'POST',
    'field_service' => $sku,
    'field_seller_key' => "seller_$sku",
    'field_seller_address' => getenv('OMBU_SELLER_ADDRESS') ?: NULL,
    'field_x402_price' => ['enabled' => 1, 'amount' => $price],
    // Derived by x402_pay for our listings (see /market.json).
    'field_pay_to' => NULL,
    'field_accepts' => NULL,
    'field_endpoint' => NULL,
    // Where the price is charged: mainnet on the site, testnet in dev.
    'field_network' => [$p->get('field_x402_price')->getFieldDefinition()->getSetting('network')],
    'field_source' => 'ours',
    'field_verified' => TRUE,
    'field_category' => $term('listing_category', \Drupal\ombu\CircleCatalogImporter::categoryLabel($cat)),
    'field_provider_term' => $term('listing_provider', 'Quebracho'),
    'field_rails' => ['vanilla'],
    'field_tags' => array_map(fn($t) => $term('listing_tag', $t), $tags),
    'field_input_schema' => json_encode($schema),
  ] as $field => $value) {
    $p->set($field, $value);
  }
  $p->save();
  echo $p->id(), " /x402/commerce_product/{$p->id()}/full\n";
}

<?php

/**
 * @file
 * Creates the store (if missing) and Quebracho's own verified listings.
 * Idempotent: listings are keyed by SKU.
 *
 * Run: SELLER_PAY_TO=0x… ddev drush php:script ../scripts/seed-listings.php
 */

use Drupal\commerce_price\Price;

$payTo = getenv('SELLER_PAY_TO') ?: throw new \RuntimeException('Set SELLER_PAY_TO to the seller wallet.');
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
  ['ping', 'Ping: the cheapest thing in the mall', '0.0001', 'Pay a hundredth of a cent and get a signed timestamp back. Exists to test a wallet, an agent or a checkout end to end.', 'INFRASTRUCTURE', ['type' => 'object', 'properties' => new \stdClass()], ['x402', 'test']],
  ['terms', 'Terms of service and privacy analysis', '0.01', 'Reads a terms of service or privacy policy and reports what matters to you or your agent (data sharing, arbitration, auto-renewal, liability…), each point quoting the clause it comes from. Quotes not found in the text are dropped.', 'COMPLIANCE', ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'format' => 'uri'], 'text' => ['type' => 'string']]], ['compliance', 'legal', 'privacy', 'vendors']],
  ['factcheck', 'Fact-check a claim', '0.02', 'Checks a claim against sources and returns a verdict with the evidence and the reliability of each source.', 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'required' => ['claim'], 'properties' => ['claim' => ['type' => 'string']]], ['fact-check', 'research']],
  ['markdown', 'Web page to clean markdown', '0.001', 'Fetches a public URL and returns its main content as markdown, without navigation or ads.', 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'required' => ['url'], 'properties' => ['url' => ['type' => 'string', 'format' => 'uri']]], ['scraping', 'markdown']],
];

$variations = $etm->getStorage('commerce_product_variation');
$products = $etm->getStorage('commerce_product');
foreach ($listings as [$sku, $title, $price, $desc, $cat, $schema, $tags]) {
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
    'field_pay_to' => $payTo,
    'field_network' => ['eip155:5042002'],
    'field_source' => 'ours',
    'field_verified' => TRUE,
    'field_category' => $term('listing_category', $cat),
    'field_tags' => array_map(fn($t) => $term('listing_tag', $t), $tags),
    'field_input_schema' => json_encode($schema),
  ] as $field => $value) {
    $p->set($field, $value);
  }
  $p->save();
  // The agent door is the listing's own URL; x402_pay serves it.
  $p->set('field_endpoint', $p->toUrl('canonical', ['absolute' => TRUE])->toString() . '/call');
  $p->set('field_accepts', json_encode([[
    'scheme' => 'exact',
    'network' => 'eip155:5042002',
    'asset' => '0x3600000000000000000000000000000000000000',
    'payTo' => $payTo,
    'amount' => bcmul($price, '1000000', 0),
    'maxTimeoutSeconds' => 60,
    'extra' => ['name' => 'USDC', 'version' => '2'],
  ]], JSON_UNESCAPED_SLASHES));
  $p->save();
  echo $p->id(), ' ', $p->get('field_endpoint')->value, "\n";
}

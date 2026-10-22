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
 * Run: ddev drush php:script ../scripts/seed-listings.php [sku]
 * (with a sku, only that listing).
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
  ['factcheck', 'Fact-check a claim', '0.02', 'Returns a verdict on one claim, or on each claim found in a text: supported, refuted or not enough information, with the web sources behind it. When no source is found the model judges on its own; the answer says so.', 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'properties' => ['claim' => ['type' => 'string'], 'text' => ['type' => 'string']]], ['fact-check', 'research']],
  ['aitext', 'AI-written text detection', '0.01', 'Estimates how likely a text was written by an AI model (0-100) with a one-line rationale. A model\'s judgement, a hint and not proof.', 'CONTENT_MODERATION', ['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string']]], ['ai-detection', 'writing']],
  ['plagiarism', 'Plagiarism check', '0.02', 'Searches the web for the text\'s most distinctive sentences verbatim and returns each page that has them.', 'CONTENT_MODERATION', ['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string']]], ['plagiarism', 'writing']],
  ['markdown', 'Web page to clean markdown', '0.01', 'Fetches a public URL and returns its main content as markdown, without navigation or ads.', 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'required' => ['url'], 'properties' => ['url' => ['type' => 'string', 'format' => 'uri']]], ['scraping', 'markdown']],
  ['names', 'Name check: domains and trademarks at once', '0.005', "Before you name a product or a company: one call tells you which of its domains are free and which live trademarks already contain the name.\n\nDomains on any TLD, from the registry itself (RDAP, or WHOIS where there is no RDAP): free, or registered and when it expires. A domain is never called free unless its registry says so.\n\nTrademarks in Argentina (INPI) and the United States (USPTO): granted or pending marks whose name contains yours, with their Nice class and owners. Filter by class (35 commerce, 36 finance, 42 software…).\n\nA first look, not legal advice: whether two marks are confusingly similar is for a trademark attorney to say. Compound names are searched as written: also try them with spaces.", 'WEB_SEARCH_RESEARCH', ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'description' => 'One word, no TLD: acme'], 'tlds' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8, 'description' => 'Default: com, com.ar, io, ar'], 'countries' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['ar', 'us']]], 'classes' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 45]]]], ['domains', 'trademarks', 'naming', 'branding']],
];

// Product pages in every language the site speaks: people and agents search in their own.
$translations = [
  'names' => [
    'es' => ['Chequeo de nombre: dominios y marcas en una sola consulta', "Antes de ponerle nombre a un producto o una empresa: en una sola llamada sabés cuáles de sus dominios están libres y qué marcas vivas ya contienen ese nombre.\n\nDominios en cualquier TLD, consultados al registro mismo (RDAP, o WHOIS donde no hay RDAP): libre, o registrado y cuándo vence. Nunca dice libre sin que el registro lo confirme.\n\nMarcas en Argentina (INPI) y Estados Unidos (USPTO): concedidas o en trámite cuyo nombre contiene el tuyo, con su clase de Niza y sus titulares. Filtrá por clase (35 comercio, 36 finanzas, 42 software…).\n\nEs una primera mirada, no asesoramiento legal: si dos marcas se confunden lo dice un agente de la propiedad industrial. Los nombres compuestos se buscan como los escribís: probalos también con espacios."],
    'pt-br' => ['Checagem de nome: domínios e marcas numa só consulta', "Antes de dar nome a um produto ou empresa: numa só chamada você sabe quais domínios estão livres e quais marcas vigentes já contêm esse nome.\n\nDomínios em qualquer TLD, consultados no próprio registro (RDAP, ou WHOIS onde não há RDAP): livre, ou registrado e quando vence. Nunca diz livre sem o registro confirmar.\n\nMarcas na Argentina (INPI) e nos Estados Unidos (USPTO): concedidas ou em andamento cujo nome contém o seu, com a classe de Nice e os titulares. Filtre por classe (35 comércio, 36 finanças, 42 software…).\n\nUma primeira olhada, não aconselhamento jurídico: se duas marcas se confundem, quem diz é um agente de propriedade industrial. Nomes compostos são buscados como escritos: tente também com espaços."],
  ],
];
$only = $extra[0] ?? NULL;

$variations = $etm->getStorage('commerce_product_variation');
$products = $etm->getStorage('commerce_product');
foreach ($listings as [$sku, $title, $price, $desc, $cat, $schema, $tags]) {
  if ($only && $sku !== $only) {
    continue;
  }
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
  foreach ($translations[$sku] ?? [] as $langcode => [$t_title, $t_body]) {
    if (\Drupal::languageManager()->getLanguage($langcode)) {
      $t = $p->hasTranslation($langcode) ? $p->getTranslation($langcode) : $p->addTranslation($langcode);
      $t->setTitle($t_title)->set('body', ['value' => $t_body, 'format' => 'plain_text'])->save();
    }
  }
  echo $p->id(), " /x402/commerce_product/{$p->id()}/full\n";
}

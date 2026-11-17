<?php

/**
 * @file
 * Fixes the third-party paragraph of the terms of use on a live site, in
 * every language present, without touching anything else.
 *
 * The paragraph said that, when a third-party service is called through
 * Ombú's payment door, Ombú may relay the signed payment authorization to
 * the network and pay the network fee. That was never true of the market
 * Ombú runs today: buyers call a third-party seller's endpoint directly,
 * from their own wallet, and pay it on the blockchain; only services sold
 * by Ombú settle through Ombú's own facilitator. The old wording made the
 * Operator look like a transmitter of other sellers' payments, which Ombú
 * does not do and must not promise.
 *
 * Replaces exactly the old paragraph with the seed's new one, by matching
 * its first sentence (stable across translations): a lawyer's edit that
 * rewrote the paragraph no longer matches and is left alone, with a note.
 * Spanish prevails; if a language has no text of its own, nothing to fix.
 *
 * Run once on production, after `git pull`:
 *   ddev drush php:script ../scripts/fix-terms-third-party.php
 * (on SER5: deploy/docker compose exec -T web drush php:script ../scripts/…)
 */

$old_opener = [
  'en' => 'When a third-party service is called through Ombú\'s payment door,',
  'es' => 'Cuando se llama a un servicio de terceros a través de la puerta de pago de Ombú,',
  'pt-br' => 'Quando um serviço de terceiros é chamado pela porta de pagamento do Ombú,',
];
// The seed's own paragraph, without the closing tag; the sentences added
// about the buyer calling the endpoint and the Operator not holding money
// live inside it.
$new_paragraph = [
  'en' => "A purchase from a third-party seller is a contract between the User and that seller only. The User pays the seller directly, on the blockchain, from their own wallet. Delivery, quality, legality, refunds, invoices, taxes and any claim about that service are matters between the User and the seller. Buyers call a third-party seller's endpoint directly, from their own wallet, and pay it on the blockchain: the Operator never holds, transmits or relays any money or payment authorization of these purchases. Read each seller's own terms before buying.",
  'es' => 'Una compra a un vendedor tercero es un contrato exclusivamente entre el Usuario y ese vendedor. El Usuario le paga directamente al vendedor, en la blockchain, desde su propia billetera. La entrega, la calidad, la legalidad, los reembolsos, las facturas, los impuestos y cualquier reclamo sobre ese servicio son cuestiones entre el Usuario y el vendedor. Quien compra llama directamente al endpoint del vendedor tercero, desde su propia billetera, y le paga en la blockchain: el Operador nunca retiene, transmite ni retransmite dinero ni autorizaciones de pago de esas compras. Lee los términos de cada vendedor antes de comprar.',
  'pt-br' => 'Uma compra de um vendedor terceiro é um contrato exclusivamente entre o Usuário e esse vendedor. O Usuário paga diretamente ao vendedor, na blockchain, a partir de sua própria carteira. Entrega, qualidade, legalidade, reembolsos, notas fiscais, impostos e qualquer reclamação sobre esse serviço são questões entre o Usuário e o vendedor. Quem compra chama diretamente o endpoint do vendedor terceiro, a partir de sua própria carteira, e paga a ele na blockchain: o Operador nunca retém, transmite nem retransmite dinheiro nem autorizações de pagamento dessas compras. Leia os termos de cada vendedor antes de comprar.',
];
// The old paragraph, to find it: everything from its <p> up to and including
// "before buying.</p>", whatever the middle held (an earlier edit of the
// first sentences is still matched; a rewrite of the opener is not).
$old_prefix = [
  'en' => 'A purchase from a third-party seller is a contract between the User and that seller only.',
  'es' => 'Una compra a un vendedor tercero es un contrato exclusivamente entre el Usuario y ese vendedor.',
  'pt-br' => 'Uma compra de um vendedor terceiro é um contrato exclusivamente entre o Usuário e esse vendedor.',
];

$nodes = \Drupal::entityTypeManager()->getStorage('node');
$path = \Drupal::service('path_alias.repository')->lookupByAlias('/terms', 'en');
if (!$path) {
  echo "/terms not on this site; nothing to fix\n";
  return;
}
$node = $nodes->load((int) basename($path['path']));
$fixed = 0;
foreach ($old_opener as $langcode => $needle) {
  if (!$node->hasTranslation($langcode)) {
    echo "$langcode: no translation\n";
    continue;
  }
  $t = $node->getTranslation($langcode);
  $body = (string) $t->get('body')->value;
  $p = strpos($body, $needle);
  if ($p === FALSE) {
    // Already fixed, or edited by hand since: neither is ours to rewrite.
    echo "$langcode: old wording not found; left as it is\n";
    continue;
  }
  // Back up to this paragraph's <p> and forward to the end of it.
  $start = strrpos(substr($body, 0, $p), '<p>');
  $end = strpos($body, '</p>', $p);
  if ($start === FALSE || $end === FALSE) {
    echo "$langcode: cannot bound the paragraph; left as it is\n";
    continue;
  }
  $paragraph = substr($body, $start, $end + 4 - $start);
  if (strpos($paragraph, $old_prefix[$langcode]) !== 3) {
    echo "$langcode: the paragraph does not start as expected; left as it is\n";
    continue;
  }
  $t->set('body', ['value' => substr_replace($body, '<p>' . $new_paragraph[$langcode] . '</p>', $start, $end + 4 - $start), 'format' => 'full_html']);
  $t->save();
  $fixed++;
  echo "$langcode: third-party paragraph of the terms fixed\n";
}
echo $fixed ? "Done: $fixed language(s) fixed. Check /terms in each.\n" : "Nothing changed.\n";

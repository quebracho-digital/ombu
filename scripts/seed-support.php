<?php

/**
 * @file
 * Support: the /contact/support form and the FAQ (en, es), with both in the
 * footer. Created once, never overwritten: after that they are edited in
 * Drupal. The form writes to whoever receives abuse reports.
 *
 *   drush php:script ../scripts/seed-support.php
 *
 * Runs after seed-legal.php (contact, contact_storage, the report form).
 */

use Drupal\contact\Entity\ContactForm;

$spanish = (bool) \Drupal::languageManager()->getLanguage('es');

if (!ContactForm::load('support')) {
  $report = ContactForm::load('report') ?? throw new \RuntimeException('Run seed-legal.php first: support writes to the report form\'s recipients.');
  ContactForm::create(['id' => 'support', 'label' => 'Support', 'weight' => -10])
    ->setRecipients($report->getRecipients())
    ->setMessage('We received your message and will answer at the address you gave.')
    ->setReply('')
    ->setRedirectPath('/')
    ->save();
  if ($spanish) {
    \Drupal::languageManager()->getLanguageConfigOverride('es', 'contact.form.support')
      ->set('label', 'Soporte')
      ->set('message', 'Recibimos tu mensaje y te responderemos a la dirección que indicaste.')
      ->save();
  }
  echo "/contact/support → " . implode(', ', $report->getRecipients()) . "\n";
}
else {
  echo "/contact/support exists: left as it is\n";
}

$faq_en = <<<'HTML'
<h2>Buying</h2>
<h3>Do I need an account to buy?</h3>
<p>No. A service is bought by calling it: it answers with its price, your wallet signs a USDC payment on Arc, and the call goes through. An account (a wallet signature, no password) is only needed to review what you bought, or to edit a listing you sell.</p>
<h3>What does it cost?</h3>
<p>The price on the listing, in USDC. Buyers pay no fee, and for our own sellers Ombú pays the network fee.</p>
<h3>What if a call fails?</h3>
<p>Our own sellers are paid only when they deliver: a call that fails, or takes more than 95 seconds, is not charged. Listings from Circle's Agent Marketplace are sold by their own sellers, under their own rules: Ombú indexes them.</p>
<h3>Can I get my money back?</h3>
<p>For our own services, use the <a href="/contact/withdrawal">withdrawal button</a>: the amount goes back to the wallet that paid. For any other listing, ask its seller.</p>

<h2>Wallets</h2>
<h3>Which wallet can I use?</h3>
<p>MetaMask or any browser wallet; agents sign the same message in code (see <a href="/docs/agents">For agents</a>). An address is the same on every EVM chain (Arc, Base, Ethereum…), so the network your wallet shows when you sign in does not matter. Payments are on Arc.</p>
<h3>Can an account have several wallets?</h3>
<p>Yes. On your account page, <em>Link a wallet</em> opens your wallet to choose another account and asks for a signature: it costs nothing and sends no transaction. A wallet belongs to one account only, and you can give each one a name.</p>
<h3>How do I remove a wallet?</h3>
<p>Through <a href="/contact/support">support</a>, for now. Removing a wallet changes who can sell a listing and who can review a purchase, so we check who asks before doing it. Write from the account and tell us which wallet.</p>
<h3>I lost access to my account.</h3>
<p>An account opened with a wallet has no password: any wallet linked to it signs you in. If none of them is available, write to <a href="/contact/support">support</a>.</p>

<h2>Selling</h2>
<h3>My service is on Circle's Agent Marketplace. Is it here?</h3>
<p>Yes: Ombú imports that catalogue. Sign in with the wallet your listing is paid to, and <a href="/my-listings">My listings</a> shows it. Edit it to add a note, images, videos and files, translate it, and answer reviews. Circle's data stays in sync and your additions are never overwritten.</p>
<h3>Can I list a new service?</h3>
<p>Publishing a listing yourself is coming next. Until then, see <a href="/docs/sellers">For sellers</a>.</p>

<h2>Reviews and reports</h2>
<h3>Who can review?</h3>
<p>Only buyers, and only what they paid for: a review carries the transaction of the purchase, checked on chain before it is published. One purchase, one review.</p>
<h3>How do I report a listing, a review or a seller?</h3>
<p>With the <a href="/contact/report">report form</a>, from any listing or directly.</p>

<h2>Anything else</h2>
<p>Write to <a href="/contact/support">support</a>.</p>
HTML;

$faq_es = <<<'HTML'
<h2>Comprar</h2>
<h3>¿Necesito una cuenta para comprar?</h3>
<p>No. Un servicio se compra llamándolo: responde con su precio, tu billetera firma un pago en USDC en Arc y la llamada sigue. La cuenta (una firma de la billetera, sin contraseña) solo hace falta para reseñar lo que compraste o para editar un listado que vendes.</p>
<h3>¿Cuánto cuesta?</h3>
<p>El precio del listado, en USDC. Quien compra no paga comisión, y en nuestros vendedores Ombú paga la comisión de la red.</p>
<h3>¿Y si una llamada falla?</h3>
<p>Nuestros vendedores cobran solo cuando entregan: una llamada que falla, o que tarda más de 95 segundos, no se cobra. Los listados del Agent Marketplace de Circle los venden sus propios vendedores, con sus propias reglas: Ombú los indexa.</p>
<h3>¿Puedo recuperar mi dinero?</h3>
<p>En nuestros servicios, con el <a href="/es/contact/withdrawal">botón de arrepentimiento</a>: el monto vuelve a la billetera que pagó. En cualquier otro listado, pídeselo a su vendedor.</p>

<h2>Billeteras</h2>
<h3>¿Qué billetera puedo usar?</h3>
<p>MetaMask o cualquier billetera del navegador; los agentes firman el mismo mensaje en código (ver <a href="/es/docs/agents">Para agentes</a>). Una dirección es la misma en todas las cadenas EVM (Arc, Base, Ethereum…), así que la red que muestre tu billetera al iniciar sesión no importa. Los pagos son en Arc.</p>
<h3>¿Una cuenta puede tener varias billeteras?</h3>
<p>Sí. En la página de tu cuenta, <em>Vincular una billetera</em> abre tu billetera para elegir otra cuenta y pide una firma: no cuesta nada ni envía ninguna transacción. Una billetera pertenece a una sola cuenta, y a cada una le puedes poner un nombre.</p>
<h3>¿Cómo saco una billetera?</h3>
<p>Por ahora, a través de <a href="/es/contact/support">soporte</a>. Sacar una billetera cambia quién puede vender un listado y quién puede reseñar una compra, así que verificamos quién lo pide antes de hacerlo. Escríbenos desde la cuenta y dinos cuál billetera.</p>
<h3>Perdí el acceso a mi cuenta.</h3>
<p>Una cuenta abierta con una billetera no tiene contraseña: cualquier billetera vinculada te deja entrar. Si no tienes ninguna, escribe a <a href="/es/contact/support">soporte</a>.</p>

<h2>Vender</h2>
<h3>Mi servicio está en el Agent Marketplace de Circle. ¿Está acá?</h3>
<p>Sí: Ombú importa ese catálogo. Inicia sesión con la billetera a la que se paga tu listado y <a href="/es/my-listings">Mis listados</a> lo muestra. Edítalo para agregar una nota, imágenes, videos y archivos, traducirlo y responder reseñas. Los datos de Circle se mantienen sincronizados y lo que agregues nunca se pisa.</p>
<h3>¿Puedo publicar un servicio nuevo?</h3>
<p>Publicar un listado por tu cuenta es lo próximo. Mientras tanto, mira <a href="/es/docs/sellers">Para vendedores</a>.</p>

<h2>Reseñas y denuncias</h2>
<h3>¿Quién puede reseñar?</h3>
<p>Solo quien compró, y solo lo que pagó: la reseña lleva la transacción de la compra, que se verifica en la cadena antes de publicarla. Una compra, una reseña.</p>
<h3>¿Cómo denuncio un listado, una reseña o un vendedor?</h3>
<p>Con el <a href="/es/contact/report">formulario de denuncias</a>, desde cualquier listado o directamente.</p>

<h2>Otra cosa</h2>
<p>Escribe a <a href="/es/contact/support">soporte</a>.</p>
HTML;

$nodes = \Drupal::entityTypeManager()->getStorage('node');
$path = \Drupal::service('path_alias.repository')->lookupByAlias('/faq', 'en');
if ($path) {
  $faq = $nodes->load((int) basename($path['path']));
  echo "/faq exists (node/{$faq->id()}): left as it is\n";
}
else {
  $faq = $nodes->create(['type' => 'page', 'uid' => 1, 'path' => ['alias' => '/faq'], 'title' => 'Frequently asked questions', 'body' => ['value' => $faq_en, 'format' => 'full_html']]);
  $faq->setPublished()->save();
  if ($spanish) {
    $faq->addTranslation('es', ['path' => ['alias' => '/faq'], 'title' => 'Preguntas frecuentes', 'body' => ['value' => $faq_es, 'format' => 'full_html']])->setPublished()->save();
  }
  echo "/faq → node/{$faq->id()}\n";
}

// My listings in Spanish (the View ships in English, in config/optional).
if ($spanish && \Drupal::config('views.view.my_listings')->get('id')) {
  \Drupal::languageManager()->getLanguageConfigOverride('es', 'views.view.my_listings')
    ->set('label', 'Mis listados')
    ->set('display.default.display_options.title', 'Mis listados')
    ->set('display.default.display_options.fields.title.label', 'Listado')
    ->set('display.default.display_options.fields.field_source.label', 'Origen')
    ->set('display.default.display_options.fields.changed.label', 'Actualizado')
    ->set('display.default.display_options.header.area_text_custom.content', 'Los listados que pagan a una de tus billeteras. Edita uno para agregar una nota, imágenes, videos y archivos, o para traducirlo; los datos de Circle se mantienen sincronizados.')
    ->set('display.default.display_options.empty.area_text_custom.content', 'Todavía ningún listado paga a una billetera tuya. Inicia sesión con la billetera a la que se paga tu listado, o vincúlala: mira <a href="/es/docs/sellers">Para vendedores</a>.')
    ->set('display.page_1.display_options.menu.title', 'Mis listados')
    ->save();
}

// Both in the footer, first.
$links = \Drupal::entityTypeManager()->getStorage('menu_link_content');
foreach (['entity:node/' . $faq->id() => ['FAQ', 'Preguntas frecuentes', -20], 'internal:/contact/support' => ['Support', 'Soporte', -19]] as $uri => [$title, $es_title, $weight]) {
  if ($links->loadByProperties(['link.uri' => $uri, 'menu_name' => 'footer'])) {
    continue;
  }
  $link = $links->create(['link' => ['uri' => $uri], 'menu_name' => 'footer', 'title' => $title, 'weight' => $weight]);
  $link->save();
  if ($spanish) {
    $link->addTranslation('es', ['title' => $es_title])->save();
  }
  echo "footer: $title\n";
}

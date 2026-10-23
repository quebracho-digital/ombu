<?php

/**
 * @file
 * Terms, privacy, the abuse report form and the withdrawal button (to
 * cancel a purchase of our own services), in every language the site has
 * a text for (en, es). Creates each page once, as a Drupal page node, and
 * never overwrites it: after that the text is edited in Drupal (a lawyer's
 * changes must survive a second run).
 *
 * Who answers legally must be filled in before anything is published:
 *   ddev exec env OMBU_LEGAL_NAME='…' OMBU_LEGAL_EMAIL='…' \
 *     OMBU_LEGAL_ADDRESS='…' drush php:script ../scripts/seed-legal.php
 * OMBU_LEGAL_NAME: the person or company that operates Ombú (with its CUIT,
 * if any). OMBU_LEGAL_EMAIL receives reports and withdrawals.
 *
 * Not legal advice: a lawyer reads these before they matter.
 */

use Drupal\contact\Entity\ContactForm;
use Drupal\user\Entity\Role;

$name = getenv('OMBU_LEGAL_NAME');
$email = getenv('OMBU_LEGAL_EMAIL');
$address = getenv('OMBU_LEGAL_ADDRESS');
if (!$name || !$email || !$address || !\Drupal::service('email.validator')->isValid($email)) {
  throw new \RuntimeException('Set OMBU_LEGAL_NAME, OMBU_LEGAL_EMAIL and OMBU_LEGAL_ADDRESS: legal pages are not published without them.');
}
$name = htmlspecialchars($name);
$address = htmlspecialchars($address);
$mail = '<a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>';
$date_en = 'October 13, 2026';
$date_es = '13 de octubre de 2026';
// The privacy policy changed on its own: the similar services log.
$privacy_en = 'October 23, 2026';
$privacy_es = '23 de octubre de 2026';

// The forms: core contact, site-wide only, no personal forms.
if (!\Drupal::moduleHandler()->moduleExists('contact')) {
  \Drupal::service('module_installer')->install(['contact']);
  // Installed just now: its sample form would be a second inbox nobody reads.
  ContactForm::load('feedback')?->delete();
}
// Every message kept on the site too (/admin/structure/contact/messages):
// mail from DDEV ends in Mailpit, and a report must never be lost.
\Drupal::service('module_installer')->install(['contact_storage']);
// Label, and what is shown after sending (en, es). No auto-reply: it would
// mail any address typed in.
$forms = [
  'report' => [
    ['Report abuse', 'We received your report and will review it. If we need more, we will write to the address you gave.'],
    ['Denunciar un abuso', 'Recibimos tu denuncia y la vamos a revisar. Si necesitamos más información, te escribiremos a la dirección que indicaste.'],
  ],
  'withdrawal' => [
    ['Cancel a purchase', 'We received your request. Within 24 hours we will write to you with its reference number, and we will return the amount paid to the wallet that paid it.'],
    ['Botón de arrepentimiento', 'Recibimos tu pedido. Dentro de las 24 horas te escribiremos con su número de trámite, y devolveremos el monto pagado a la billetera que pagó.'],
  ],
];
$spanish = \Drupal::languageManager()->getLanguage('es') && \Drupal::moduleHandler()->moduleExists('content_translation');
// Created once, like the pages: recipients and texts are edited in Drupal.
foreach ($forms as $id => [[$label, $message], [$es_label, $es_message]]) {
  if (ContactForm::load($id)) {
    echo "/contact/$id exists: left as it is\n";
    continue;
  }
  ContactForm::create(['id' => $id, 'label' => $label])
    ->setRecipients([$email])
    ->setMessage($message)
    ->setReply('')
    ->setRedirectPath('/')
    ->save();
  if ($spanish) {
    \Drupal::languageManager()->getLanguageConfigOverride('es', "contact.form.$id")->set('label', $es_label)->set('message', $es_message)->save();
  }
  echo "/contact/$id → $email\n";
}
\Drupal::configFactory()->getEditable('contact.settings')
  ->set('default_form', 'report')
  ->set('user_default_enabled', FALSE)
  ->save();
foreach (['anonymous', 'authenticated'] as $rid) {
  Role::load($rid)->grantPermission('access site-wide contact form')->save();
}

// ponytail: no cart for people until the Commerce x402 gateway exists
// (ListingHooks drops the add-to-cart form); enable it again then.
\Drupal\block\Entity\Block::load('belgrade_shopping_cart')?->disable()->save();

$pages = [];
$es = [];

$pages['/terms'] = ['Terms of use', <<<HTML
<p><em>In force since {$date_en}. This page is also in Spanish; if the two differ, the Spanish version prevails.</em></p>
<p><strong>Ombú is a demo.</strong> It was built for a hackathon and is still being tested: it can change, fail or stop at any time, and these terms may change before it opens for real. Use small amounts.</p>

<h2>1. Who we are and what these terms cover</h2>
<p>Ombú (<a href="https://ombu.quebracho.digital">ombu.quebracho.digital</a>, “Ombú”, “we”) is operated by {$name}, {$address} (the “Operator”). These terms govern the use of the site, its pages, its API and its machine-readable documents (<code>/market.json</code>, <code>/openapi.json</code>, <code>/llms.txt</code>) by any person or software agent (the “User”). By using Ombú you accept them. If you use Ombú through an agent, the person or organisation the agent acts for accepts them and answers for what it does.</p>
<p>Ombú is for adults with full legal capacity. Do not use it if you are under 18 or under the age of majority where you live.</p>

<h2>2. Three kinds of listings, three kinds of responsibility</h2>
<p>Ombú shows services offered by different parties. Each listing says who its seller is. The difference matters:</p>
<ol>
  <li><strong>Services sold by Ombú.</strong> Listings marked as sold by <em>Quebracho</em> (today: the signed timestamp, web page to markdown, terms and privacy analysis, and fact-check). The Operator is the seller of these services and answers for them as these terms and the law say (section 4).</li>
  <li><strong>Listings imported from third-party catalogues.</strong> Ombú reproduces, as an index, public catalogues of services offered by others, such as Circle's Agent Marketplace. Their title, description, price, payment address and the rest come from that catalogue and from the provider, as published. <strong>The Operator does not sell, provide, operate, verify, endorse or guarantee these services</strong>, is not a party to any contract between the User and their provider, and does not receive, hold or transmit the money paid to them.</li>
  <li><strong>Content published by sellers and users.</strong> Sellers can add notes, images, videos and files to their listings and answer reviews; buyers can write reviews; in the future, sellers will publish their own listings. <strong>Whoever publishes content is solely responsible for it</strong> and for the service it describes. The Operator only hosts it, does not review it before it is published, and does not adopt it as its own.</li>
</ol>
<p>A <em>Verified seller</em> badge only means that we know which wallet receives that seller's payments. It is not a guarantee of the service, its quality or its legality.</p>

<h2>3. Buying from third parties</h2>
<p>A purchase from a third-party seller is a contract between the User and that seller only. The User pays the seller directly, on the blockchain, from their own wallet. Delivery, quality, legality, refunds, invoices, taxes and any claim about that service are matters between the User and the seller. When a third-party service is called through Ombú's payment door, Ombú may relay the payment authorization the User signed to the network and pay the network fee, without ever holding the funds; this does not make the Operator a party to the sale. Read each seller's own terms before buying.</p>

<h2>4. Services sold by Ombú</h2>
<ul>
  <li><strong>Price and payment.</strong> The price is the one the service states when it is called, in USDC. Payment is settled only if the service answered successfully; if it fails, the payment is not settled and costs nothing.</li>
  <li><strong>What they deliver.</strong> These services use language models and automated processing. Their results can be incomplete or wrong, and are informative: <strong>they are not legal, financial, medical or any other professional advice</strong>, nor an expert opinion. A fact-check marked <em>model only</em> did not consult external sources. Check what matters before relying on it.</li>
  <li><strong>Your input.</strong> You may only send content you have the right to send. Do not send other people's personal data or confidential information unless you have a legal basis to do so.</li>
  <li><strong>Cancelling a purchase (right of withdrawal).</strong> You can cancel the purchase of a service sold by Ombú, without giving a reason, within 10 calendar days of it, or within the longer period the law that applies to you grants, through the <a href="/contact/withdrawal">withdrawal form</a> (the “withdrawal button”) or by writing to {$mail}. Include the transaction of the payment. We confirm the request with a reference number within 24 hours and return the amount paid, in the same token and on the same network, to the wallet that paid it. Blockchain payments cannot be reversed: the refund is a new transfer, made by hand.</li>
  <li><strong>Consumers.</strong> If you are a consumer under the law that applies to you (in Argentina, Law 24,240), nothing in these terms limits the rights that law gives you and that cannot be waived.</li>
</ul>

<h2>5. Wallets, accounts and payments</h2>
<ul>
  <li>Signing in is done with a wallet signature. You are responsible for your wallet and your keys. <strong>We will never ask for your private key or seed phrase.</strong></li>
  <li>Blockchain payments are irreversible. A payment sent to a wrong address or for a wrong amount cannot be undone by us.</li>
  <li>Ombú does not hold Users' funds, does not exchange currencies or crypto-assets, does not give credit and does not give investment advice. USDC is issued by a third party; its value, availability and rules do not depend on us. Ombú is not affiliated with Circle, Arc or any network or token it lists, unless it says so expressly.</li>
  <li>You confirm that you are not subject to sanctions that forbid you from using a service like this, and that you comply with the laws of your country when using Ombú.</li>
</ul>

<h2>6. What is not allowed</h2>
<p>Neither on Ombú nor through it may anyone publish, offer, list, buy or do any of the following:</p>
<ul>
  <li>anything illegal in Argentina or in the country of the User, the seller or the buyer, or that helps someone break the law;</li>
  <li>content that infringes copyright, trademarks, patents, trade secrets or other people's rights to their image, privacy or personal data;</li>
  <li>fraud, scams, phishing, impersonation, deceptive listings, or services that do not deliver what they describe;</li>
  <li>malware, attacks on systems, unauthorised access, or services designed for any of these;</li>
  <li>sexual content involving minors, content that promotes violence or terrorism, hate speech, harassment or threats;</li>
  <li>money laundering, terrorist financing, or evading sanctions or controls;</li>
  <li>fake reviews, reviews of things not bought, or manipulation of ratings, rankings or search;</li>
  <li>using Ombú in a way that overloads it, breaks its security, or bypasses its limits or payments.</li>
</ul>

<h2>7. Reporting abuse and taking content down</h2>
<p>Anyone, with or without an account, can report a listing, a review, a seller, an account or any content that breaks these terms or the law, through the <a href="/contact/report">report form</a> or by writing to {$mail}. A useful report includes the link to what is reported, why it should come down, and, for rights you hold (for example copyright), that you hold them or act for whoever does.</p>
<p>The Operator has no general duty to monitor what third parties publish, but when it learns of content that is illegal or breaks these terms it acts with diligence. At its discretion, and without prejudice to other measures, it may:</p>
<ul>
  <li>hide, unpublish or <strong>permanently delete</strong> any listing, imported or not, and keep it from being imported again;</li>
  <li>delete reviews, replies, notes, images, videos and files;</li>
  <li>suspend, block or <strong>permanently delete</strong> accounts, and refuse service to the wallets associated with them;</li>
  <li>keep the information needed to answer a claim or a request from an authority, and share it with the authority when the law requires it.</li>
</ul>
<p>A listing that its seller withdraws, or that disappears from the catalogue it was imported from, is not deleted: it stays on Ombú marked as no longer offered, it can no longer be bought, and its verified buyers can still review it. Only content taken down under this section is removed. Withdrawing a listing does not remove its reviews.</p>
<p>Where possible, and if it does not put anyone at risk or obstruct an investigation, we tell the person affected what was removed and why; they can answer to {$mail} if they think it was a mistake. Removing a listing from Ombú does not remove the service from the internet or from other catalogues: that must be asked of its provider. Payments already made on the blockchain cannot be reversed by us.</p>

<h2>8. Intellectual property</h2>
<p>Ombú's code is open source, under the licences stated in each repository. The name and the brand Ombú and Quebracho belong to the Operator. Imported catalogues and third parties' names, brands and descriptions belong to their owners and are shown only to identify their services. By publishing content on Ombú you keep your rights to it, and you grant the Operator a free, non-exclusive, worldwide licence to host, reproduce, translate, adapt for display and show it on Ombú and in its machine-readable documents, for as long as it is published and for the time needed to keep records; you confirm that you have the right to grant it.</p>

<h2>9. Availability and liability</h2>
<p>Ombú is offered as it is and as available; it can change, have errors or stop. To the extent the law allows, the Operator is not liable for: services, content or conduct of third parties (sections 2 and 3); losses caused by blockchain networks, wallets, tokens or their issuers; the use of results of automated services without checking them; or indirect damages or lost profits. For the services it sells, the Operator's liability is limited, to the extent the law allows, to the price paid for the call that caused the damage. None of this limits liability for intent or gross negligence, or the rights of consumers.</p>
<p>Whoever publishes content or offers a service on Ombú will hold the Operator harmless from claims by third parties caused by that content or service, or by their breaking these terms or the law.</p>

<h2>10. Changes and end of use</h2>
<p>We can change these terms; the date at the top says when. Changes apply from their publication and do not affect purchases already made. You can stop using Ombú at any time and ask for your account to be deleted at {$mail}.</p>

<h2>11. Law and jurisdiction</h2>
<p>These terms are governed by the laws of the Argentine Republic. Any dispute goes to the ordinary courts of the City of Buenos Aires, except when the User is a consumer: then the courts of the consumer's domicile are competent, as the law says. If a clause is invalid, the others remain in force.</p>

<h2>12. Contact</h2>
<p>{$name}, {$address}. Legal matters and reports: {$mail}. See also our <a href="/privacy">privacy policy</a>.</p>
HTML];

$es['/terms'] = ['Términos de uso', <<<HTML
<p><em>Vigentes desde el {$date_es}. Esta página también está en inglés; si las dos versiones difieren, prevalece la versión en español.</em></p>
<p><strong>Ombú es una demo.</strong> Fue construido para una hackatón y todavía está en prueba: puede cambiar, fallar o detenerse en cualquier momento, y estos términos pueden cambiar antes de su apertura definitiva. Usa montos pequeños.</p>

<h2>1. Quiénes somos y qué cubren estos términos</h2>
<p>Ombú (<a href="https://ombu.quebracho.digital">ombu.quebracho.digital</a>, “Ombú”, “nosotros”) es operado por {$name}, {$address} (el “Operador”). Estos términos rigen el uso del sitio, sus páginas, su API y sus documentos para máquinas (<code>/market.json</code>, <code>/openapi.json</code>, <code>/llms.txt</code>) por cualquier persona o agente de software (el “Usuario”). Usar Ombú implica aceptarlos. Si usas Ombú a través de un agente, la persona u organización por la que actúa el agente los acepta y responde por lo que hace.</p>
<p>Ombú es para personas mayores de edad con plena capacidad. No lo uses si tienes menos de 18 años o si no alcanzaste la mayoría de edad donde vives.</p>

<h2>2. Tres tipos de listados, tres tipos de responsabilidad</h2>
<p>Ombú muestra servicios ofrecidos por distintas partes. Cada listado indica quién es su vendedor. La diferencia importa:</p>
<ol>
  <li><strong>Servicios vendidos por Ombú.</strong> Los listados marcados como vendidos por <em>Quebracho</em> (hoy: la marca de tiempo firmada, página web a markdown, análisis de términos y privacidad, y verificación de datos). El Operador es el vendedor de estos servicios y responde por ellos según estos términos y la ley (sección 4).</li>
  <li><strong>Listados importados de catálogos de terceros.</strong> Ombú reproduce, a modo de índice, catálogos públicos de servicios ofrecidos por otros, como el Agent Marketplace de Circle. Su título, descripción, precio, dirección de pago y demás datos provienen de ese catálogo y del proveedor, tal como fueron publicados. <strong>El Operador no vende, presta, opera, verifica, avala ni garantiza estos servicios</strong>, no es parte de ningún contrato entre el Usuario y su proveedor, y no recibe, retiene ni transmite el dinero que se les paga.</li>
  <li><strong>Contenido publicado por vendedores y usuarios.</strong> Los vendedores pueden agregar notas, imágenes, videos y archivos a sus listados y responder reseñas; los compradores pueden escribir reseñas; en el futuro, los vendedores publicarán sus propios listados. <strong>Quien publica un contenido es el único responsable de él</strong> y del servicio que describe. El Operador solo lo aloja, no lo revisa antes de su publicación y no lo hace propio.</li>
</ol>
<p>La marca <em>Vendedor verificado</em> solo significa que conocemos la billetera que recibe los pagos de ese vendedor. No es una garantía del servicio, de su calidad ni de su legalidad.</p>

<h2>3. Comprar a terceros</h2>
<p>Una compra a un vendedor tercero es un contrato exclusivamente entre el Usuario y ese vendedor. El Usuario le paga directamente al vendedor, en la blockchain, desde su propia billetera. La entrega, la calidad, la legalidad, los reembolsos, las facturas, los impuestos y cualquier reclamo sobre ese servicio son cuestiones entre el Usuario y el vendedor. Cuando se llama a un servicio de terceros a través de la puerta de pago de Ombú, Ombú puede transmitir a la red la autorización de pago que firmó el Usuario y pagar la comisión de la red, sin retener nunca los fondos; esto no convierte al Operador en parte de la venta. Lee los términos de cada vendedor antes de comprar.</p>

<h2>4. Servicios vendidos por Ombú</h2>
<ul>
  <li><strong>Precio y pago.</strong> El precio es el que indica el servicio al ser llamado, en USDC. El pago se liquida solo si el servicio respondió con éxito; si falla, el pago no se liquida y no cuesta nada.</li>
  <li><strong>Qué entregan.</strong> Estos servicios usan modelos de lenguaje y procesamiento automatizado. Sus resultados pueden ser incompletos o erróneos, y son informativos: <strong>no constituyen asesoramiento legal, financiero, médico ni profesional de ningún tipo</strong>, ni una opinión pericial. Una verificación marcada <em>solo modelo</em> no consultó fuentes externas. Verifica lo importante antes de basarte en ello.</li>
  <li><strong>Lo que envías.</strong> Solo puedes enviar contenido que tengas derecho a enviar. No envíes datos personales de otras personas ni información confidencial salvo que tengas una base legal para hacerlo.</li>
  <li><strong>Arrepentimiento.</strong> Puedes revocar la compra de un servicio vendido por Ombú, sin dar motivos, dentro de los 10 días corridos desde la compra, o dentro del plazo mayor que te otorgue la ley que te corresponde, mediante el <a href="/es/contact/withdrawal">botón de arrepentimiento</a> o escribiendo a {$mail}. Incluye la transacción del pago. Te confirmamos el pedido con un número de trámite dentro de las 24 horas y devolvemos el monto pagado, en el mismo token y en la misma red, a la billetera que pagó. Los pagos en la blockchain no pueden revertirse: el reintegro es una nueva transferencia, hecha manualmente.</li>
  <li><strong>Consumidores.</strong> Si eres consumidor según la ley que te corresponde (en Argentina, la Ley 24.240 de Defensa del Consumidor), nada en estos términos limita los derechos que esa ley te reconoce y que no pueden renunciarse.</li>
</ul>

<h2>5. Billeteras, cuentas y pagos</h2>
<ul>
  <li>El ingreso se hace con la firma de una billetera. Eres responsable de tu billetera y de tus claves. <strong>Nunca te vamos a pedir tu clave privada ni tu frase semilla.</strong></li>
  <li>Los pagos en la blockchain son irreversibles. Un pago enviado a una dirección equivocada o por un monto equivocado no puede ser revertido por nosotros.</li>
  <li>Ombú no custodia fondos de los Usuarios, no cambia monedas ni criptoactivos, no otorga crédito y no brinda asesoramiento de inversión. USDC es emitido por un tercero; su valor, disponibilidad y reglas no dependen de nosotros. Ombú no está afiliado a Circle, a Arc ni a ninguna red o token que liste, salvo que lo diga expresamente.</li>
  <li>Declaras que no estás alcanzado por sanciones que te prohíban usar un servicio como este, y que cumples las leyes de tu país al usar Ombú.</li>
</ul>

<h2>6. Qué no está permitido</h2>
<p>Nadie puede, en Ombú ni a través de él, publicar, ofrecer, listar, comprar ni hacer nada de lo siguiente:</p>
<ul>
  <li>nada ilegal en Argentina o en el país del Usuario, del vendedor o del comprador, ni que ayude a alguien a violar la ley;</li>
  <li>contenido que infrinja derechos de autor, marcas, patentes, secretos comerciales o derechos de otras personas sobre su imagen, su intimidad o sus datos personales;</li>
  <li>fraudes, estafas, phishing, suplantación de identidad, listados engañosos o servicios que no entregan lo que describen;</li>
  <li>software malicioso, ataques a sistemas, accesos no autorizados o servicios pensados para cualquiera de ellos;</li>
  <li>contenido sexual que involucre a menores, contenido que promueva la violencia o el terrorismo, discursos de odio, acoso o amenazas;</li>
  <li>lavado de dinero, financiamiento del terrorismo o la evasión de sanciones o controles;</li>
  <li>reseñas falsas, reseñas de algo que no se compró o la manipulación de calificaciones, rankings o búsquedas;</li>
  <li>usar Ombú de un modo que lo sobrecargue, vulnere su seguridad o eluda sus límites o sus cobros.</li>
</ul>

<h2>7. Denunciar abusos y dar de baja contenido</h2>
<p>Cualquier persona, con o sin cuenta, puede denunciar un listado, una reseña, un vendedor, una cuenta o cualquier contenido que viole estos términos o la ley, mediante el <a href="/es/contact/report">formulario de denuncias</a> o escribiendo a {$mail}. Una denuncia útil incluye el enlace a lo denunciado, el motivo por el que debería darse de baja y, si se trata de derechos propios (por ejemplo, derechos de autor), que eres su titular o actúas en su nombre.</p>
<p>El Operador no tiene una obligación general de supervisar lo que publican terceros, pero cuando toma conocimiento de contenido ilícito o que viola estos términos actúa con diligencia. A su criterio, y sin perjuicio de otras medidas, puede:</p>
<ul>
  <li>ocultar, despublicar o <strong>eliminar definitivamente</strong> cualquier listado, importado o no, e impedir que vuelva a importarse;</li>
  <li>eliminar reseñas, respuestas, notas, imágenes, videos y archivos;</li>
  <li>suspender, bloquear o <strong>eliminar definitivamente</strong> cuentas, y negar el servicio a las billeteras asociadas a ellas;</li>
  <li>conservar la información necesaria para responder a un reclamo o a un requerimiento de una autoridad, y compartirla con la autoridad cuando la ley lo exija.</li>
</ul>
<p>Un listado que su vendedor retira, o que desaparece del catálogo del que fue importado, no se elimina: sigue en Ombú marcado como que ya no se ofrece, ya no puede comprarse y sus compradores verificados pueden seguir reseñándolo. Solo se elimina el contenido dado de baja según esta sección. Retirar un listado no elimina sus reseñas.</p>
<p>Cuando sea posible, y si no pone a nadie en riesgo ni obstruye una investigación, le informamos a la persona afectada qué se eliminó y por qué; puede responder a {$mail} si cree que fue un error. Dar de baja un listado en Ombú no elimina el servicio de internet ni de otros catálogos: eso debe pedírsele a su proveedor. Los pagos ya realizados en la blockchain no pueden ser revertidos por nosotros.</p>

<h2>8. Propiedad intelectual</h2>
<p>El código de Ombú es de código abierto, bajo las licencias indicadas en cada repositorio. El nombre y las marcas Ombú y Quebracho pertenecen al Operador. Los catálogos importados y los nombres, marcas y descripciones de terceros pertenecen a sus titulares y se muestran solo para identificar sus servicios. Al publicar contenido en Ombú conservas tus derechos sobre él y le otorgas al Operador una licencia gratuita, no exclusiva y mundial para alojarlo, reproducirlo, traducirlo, adaptarlo para su visualización y mostrarlo en Ombú y en sus documentos para máquinas, mientras esté publicado y durante el tiempo necesario para conservar registros; declaras que tienes derecho a otorgarla.</p>

<h2>9. Disponibilidad y responsabilidad</h2>
<p>Ombú se ofrece tal como está y según su disponibilidad; puede cambiar, tener errores o interrumpirse. En la medida que la ley lo permita, el Operador no responde por: servicios, contenidos o conductas de terceros (secciones 2 y 3); pérdidas causadas por redes blockchain, billeteras, tokens o sus emisores; el uso sin verificar de resultados de servicios automatizados; ni por daños indirectos o lucro cesante. Por los servicios que vende, la responsabilidad del Operador se limita, en la medida que la ley lo permita, al precio pagado por la llamada que causó el daño. Nada de esto limita la responsabilidad por dolo o culpa grave, ni los derechos de los consumidores.</p>
<p>Quien publique contenido u ofrezca un servicio en Ombú mantendrá indemne al Operador frente a reclamos de terceros causados por ese contenido o servicio, o por su incumplimiento de estos términos o de la ley.</p>

<h2>10. Cambios y fin del uso</h2>
<p>Podemos modificar estos términos; la fecha al comienzo indica cuándo. Los cambios rigen desde su publicación y no afectan compras ya realizadas. Puedes dejar de usar Ombú en cualquier momento y pedir la eliminación de tu cuenta a {$mail}.</p>

<h2>11. Ley aplicable y jurisdicción</h2>
<p>Estos términos se rigen por las leyes de la República Argentina. Cualquier controversia se somete a los tribunales ordinarios de la Ciudad Autónoma de Buenos Aires, salvo que el Usuario sea consumidor: en ese caso son competentes los tribunales de su domicilio, según establece la ley. Si una cláusula fuera inválida, las demás siguen vigentes.</p>

<h2>12. Contacto</h2>
<p>{$name}, {$address}. Cuestiones legales y denuncias: {$mail}. Consulta también nuestra <a href="/es/privacy">política de privacidad</a>.</p>
HTML];

$pages['/privacy'] = ['Privacy policy', <<<HTML
<p><em>In force since {$privacy_en}. This page is also in Spanish; if the two differ, the Spanish version prevails.</em></p>
<p><strong>Ombú is a demo</strong>, built for a hackathon and still being tested; this policy may change before it opens for real.</p>

<h2>1. Who is responsible</h2>
<p>The controller of the personal data processed on Ombú (<a href="https://ombu.quebracho.digital">ombu.quebracho.digital</a>) is {$name}, {$address}. Contact: {$mail}. This policy covers Ombú only: when you call a third-party seller, what you send goes to that seller, and their own policy applies.</p>

<h2>2. What data we process</h2>
<p>Ombú is designed to collect little: no email or password is needed to use it, and no cards are processed.</p>
<ul>
  <li><strong>Wallet and account.</strong> If you sign in with a wallet: its public address, which becomes your account's name, and the signature that proves it is yours. A session cookie keeps you signed in.</li>
  <li><strong>Purchases.</strong> For every paid call to a service sold through Ombú: the service, the network, the amount, the paying and receiving addresses, the transaction and its result.</li>
  <li><strong>Reviews.</strong> Rating, text, your wallet address and the transaction of the purchase. <strong>Reviews are public.</strong></li>
  <li><strong>What you send to our services.</strong> The URL, text or claim you send to our services, to process it. The result of a terms analysis is kept, to answer the same text again without processing it twice; it can quote the text analysed.</li>
  <li><strong>Reports and messages.</strong> Your name, email and the message, if you write to us or use the report form.</li>
  <li><strong>Technical data.</strong> IP address, browser and pages visited, in server and security logs, and the recent events of the site's own log.</li>
  <li><strong>Similar services.</strong> Under each listing, and in its markdown for agents, we suggest similar services. We record which ones were shown, in what order and why, and which one a person clicked or an agent followed; with it, the browser or agent (its user agent), the language, the page you came from (without its query) and whether you were signed in, not who. To count the same visitor once, we keep an identifier that changes every month, computed with a secret key from the IP address (for IPv6, its network part) and the browser; the IP address itself is not kept. This data is pseudonymous, not anonymous. If your browser sends Global Privacy Control or Do Not Track, nothing is recorded.</li>
</ul>
<p><strong>Cookies.</strong> Ombú sets one cookie only, the session cookie, and only when you sign in or start signing in: it is strictly necessary for the service you ask for, so it needs no consent. We do not use analytics, advertising or third-party tracking cookies, we do not sell or share personal data for advertising, and we make no automated decisions with legal or similarly significant effects on you.</p>

<h2>3. The blockchain is public</h2>
<p>Payments are made on public blockchains. The addresses, amounts and dates of each transaction are visible to anyone, forever, and <strong>neither we nor anyone can change or delete them</strong>. A wallet address can be linked to a person by other means. Keep this in mind before paying or reviewing with a wallet that identifies you.</p>

<h2>4. What we use it for, and on what basis</h2>
<ul>
  <li>To provide the service, sign you in, deliver and charge what you buy, and show verified reviews: to perform our contract with you (art. 6.1.b GDPR).</li>
  <li>To keep records of sales: to comply with legal obligations (art. 6.1.c GDPR).</li>
  <li>To keep Ombú secure, prevent fraud and abuse, and handle reports: our legitimate interest in protecting the service and its users, and legal obligations (art. 6.1.f and 6.1.c GDPR).</li>
  <li>To answer what you write to us: your request, and our legitimate interest in answering it (art. 6.1.b and 6.1.f GDPR).</li>
  <li>To improve the suggestions of similar services and find errors and abuse in them: our legitimate interest in a useful service that works (art. 6.1.f GDPR). You can object at any time; Global Privacy Control or Do Not Track stops it.</li>
</ul>

<h2>5. Who else receives it</h2>
<ul>
  <li><strong>Providers that make Ombú work</strong>: Cloudflare, which carries the site's traffic and protects it (it may process data outside Argentina, including in the United States); and, when a payment is settled through Circle, the payment authorization you signed goes to Circle. The models behind our services run on our own servers.</li>
  <li><strong>Blockchain networks</strong>, where payments are published (section 3).</li>
  <li><strong>Third-party sellers</strong>, only what you yourself send them when you call their services.</li>
  <li><strong>Authorities</strong>, when the law requires it or to defend rights in a claim.</li>
</ul>
<p>Ombú is operated from Argentina, which the European Commission recognises as providing an adequate level of protection for personal data (Decision 2003/490/EC). When data goes to other countries, such as the United States, it goes only to providers bound by the safeguards the law requires, such as the European Commission's standard contractual clauses or the EU-U.S. Data Privacy Framework.</p>

<h2>6. How long we keep it</h2>
<ul>
  <li>Account: until you ask for it to be deleted, or until it is deleted for breaking the <a href="/terms">terms</a>.</li>
  <li>Purchase records: for as long as the law requires accounting and tax records to be kept.</li>
  <li>Reviews: while they are published; you can ask us to remove yours.</li>
  <li>Reports and messages: for as long as needed to handle them and, afterwards, to answer any related claim.</li>
  <li>Similar services log: 12 months, then deleted automatically.</li>
  <li>Technical logs: for a short time, as rotated by each system.</li>
</ul>

<h2>7. Your rights</h2>
<p>You can ask for access to your data, and for it to be rectified, updated or deleted, by writing to {$mail} from the address you want us to answer, or by proving that you control the wallet. We answer within the time the law sets (in Argentina, Law 25,326: ten calendar days for access, five working days for rectification or deletion). If the GDPR applies to you (in the European Economic Area, and similarly in the United Kingdom and Switzerland), you also have the rights to object to processing based on our legitimate interest, to restrict processing, to data portability, and to lodge a complaint with the data protection authority where you live or work; we answer within one month. If you live in another place whose law gives you further rights (for example, in Brazil or in California), you can exercise them the same way. Deleting an account does not delete what is on the blockchain.</p>
<p>The holder of personal data may exercise the right of access to them free of charge at intervals of no less than six months, unless a legitimate interest is shown to that effect, as set out in article 14, paragraph 3 of Law 25,326. The AGENCIA DE ACCESO A LA INFORMACIÓN PÚBLICA, as the Control Body of Law 25,326, has the power to hear the complaints and claims filed by those whose rights are affected by non-compliance with the rules on personal data protection.</p>

<h2>8. Security</h2>
<p>We protect data with reasonable technical and organizational measures. Sellers' payment keys are never stored in the site's configuration, and many sellers need no key on the site at all. No system is completely secure: if a breach affects you, we will tell you as the law requires.</p>

<h2>9. Minors</h2>
<p>Ombú is not for people under 18 and does not knowingly collect their data. If you think a minor gave us data, write to {$mail} and we will delete it.</p>

<h2>10. Changes</h2>
<p>We can change this policy; the date at the top says when. If a change matters, we will say so on the site.</p>
HTML];

$es['/privacy'] = ['Política de privacidad', <<<HTML
<p><em>Vigente desde el {$privacy_es}. Esta página también está en inglés; si las dos versiones difieren, prevalece la versión en español.</em></p>
<p><strong>Ombú es una demo</strong>, construida para una hackatón y todavía en prueba; esta política puede cambiar antes de su apertura definitiva.</p>

<h2>1. Quién es responsable</h2>
<p>El responsable de los datos personales que se tratan en Ombú (<a href="https://ombu.quebracho.digital">ombu.quebracho.digital</a>) es {$name}, {$address}. Contacto: {$mail}. Esta política cubre solo a Ombú: cuando llamas a un vendedor tercero, lo que envías le llega a ese vendedor y se rige por su propia política.</p>

<h2>2. Qué datos tratamos</h2>
<p>Ombú está pensado para recolectar poco: no hace falta email ni contraseña para usarlo, y no se procesan tarjetas.</p>
<ul>
  <li><strong>Billetera y cuenta.</strong> Si ingresas con una billetera: su dirección pública, que pasa a ser el nombre de tu cuenta, y la firma que prueba que es tuya. Una cookie de sesión mantiene tu ingreso.</li>
  <li><strong>Compras.</strong> Por cada llamada paga a un servicio vendido a través de Ombú: el servicio, la red, el monto, las direcciones que pagan y que reciben, la transacción y su resultado.</li>
  <li><strong>Reseñas.</strong> Calificación, texto, la dirección de tu billetera y la transacción de la compra. <strong>Las reseñas son públicas.</strong></li>
  <li><strong>Lo que envías a nuestros servicios.</strong> La URL, el texto o la afirmación que envías a nuestros servicios, para procesarlos. El resultado de un análisis de términos se conserva, para responder al mismo texto sin procesarlo dos veces; puede citar el texto analizado.</li>
  <li><strong>Denuncias y mensajes.</strong> Tu nombre, tu email y el mensaje, si nos escribes o usas el formulario de denuncias.</li>
  <li><strong>Datos técnicos.</strong> Dirección IP, navegador y páginas visitadas, en registros del servidor y de seguridad, y los eventos recientes del registro propio del sitio.</li>
  <li><strong>Servicios similares.</strong> Debajo de cada anuncio, y en su markdown para agentes, sugerimos servicios similares. Registramos cuáles se mostraron, en qué orden y por qué, y cuál cliqueó una persona o siguió un agente; con eso, el navegador o agente (su user agent), el idioma, la página de la que vienes (sin su query) y si habías ingresado, no quién eres. Para contar una sola vez al mismo visitante, guardamos un identificador que cambia cada mes, calculado con una clave secreta a partir de la dirección IP (en IPv6, su parte de red) y del navegador; la dirección IP en sí no se guarda. Son datos seudónimos, no anónimos. Si tu navegador envía Global Privacy Control o Do Not Track, no se registra nada.</li>
</ul>
<p><strong>Cookies.</strong> Ombú usa una sola cookie, la de sesión, y solo cuando ingresas o empiezas a ingresar: es estrictamente necesaria para el servicio que pides, por lo que no requiere consentimiento. No usamos cookies de analítica, publicitarias ni de seguimiento de terceros, no vendemos ni compartimos datos personales con fines publicitarios, y no tomamos decisiones automatizadas con efectos jurídicos o significativos sobre ti.</p>

<h2>3. La blockchain es pública</h2>
<p>Los pagos se hacen en blockchains públicas. Las direcciones, montos y fechas de cada transacción son visibles para cualquiera, para siempre, y <strong>ni nosotros ni nadie puede modificarlas ni borrarlas</strong>. Una dirección de billetera puede vincularse a una persona por otros medios. Tenlo en cuenta antes de pagar o reseñar con una billetera que te identifique.</p>

<h2>4. Para qué los usamos, y con qué fundamento</h2>
<ul>
  <li>Para prestar el servicio, hacerte ingresar, entregar y cobrar lo que compras, y mostrar reseñas verificadas: para cumplir nuestro contrato contigo (art. 6.1.b RGPD).</li>
  <li>Para conservar registros de ventas: para cumplir obligaciones legales (art. 6.1.c RGPD).</li>
  <li>Para mantener Ombú seguro, prevenir fraudes y abusos, y atender denuncias: nuestro interés legítimo en proteger el servicio y a sus usuarios, y obligaciones legales (art. 6.1.f y 6.1.c RGPD).</li>
  <li>Para responder lo que nos escribes: tu pedido, y nuestro interés legítimo en responderlo (art. 6.1.b y 6.1.f RGPD).</li>
  <li>Para mejorar las sugerencias de servicios similares y encontrar errores y abusos en ellas: nuestro interés legítimo en un servicio útil y que funcione (art. 6.1.f RGPD). Puedes oponerte en cualquier momento; Global Privacy Control o Do Not Track lo detienen.</li>
</ul>

<h2>5. Quién más los recibe</h2>
<ul>
  <li><strong>Proveedores que hacen funcionar Ombú</strong>: Cloudflare, que transporta y protege el tráfico del sitio (puede tratar datos fuera de Argentina, incluso en Estados Unidos); y, cuando un pago se liquida a través de Circle, la autorización de pago que firmaste le llega a Circle. Los modelos detrás de nuestros servicios corren en servidores propios.</li>
  <li><strong>Las redes blockchain</strong>, donde se publican los pagos (sección 3).</li>
  <li><strong>Los vendedores terceros</strong>, solo lo que tú mismo les envías al llamar a sus servicios.</li>
  <li><strong>Las autoridades</strong>, cuando la ley lo exija o para defender derechos en un reclamo.</li>
</ul>
<p>Ombú se opera desde Argentina, a la que la Comisión Europea reconoce un nivel adecuado de protección de datos personales (Decisión 2003/490/CE). Cuando los datos van a otros países, como Estados Unidos, solo van a proveedores obligados por las garantías que exige la ley, como las cláusulas contractuales tipo de la Comisión Europea o el Marco de Privacidad de Datos UE-EE. UU.</p>

<h2>6. Cuánto tiempo los conservamos</h2>
<ul>
  <li>Cuenta: hasta que pidas su eliminación, o hasta que se elimine por violar los <a href="/es/terms">términos</a>.</li>
  <li>Registros de compras: durante el plazo que la ley exige conservar registros contables e impositivos.</li>
  <li>Reseñas: mientras estén publicadas; puedes pedirnos que eliminemos las tuyas.</li>
  <li>Denuncias y mensajes: el tiempo necesario para atenderlos y, después, para responder a cualquier reclamo relacionado.</li>
  <li>Registro de servicios similares: 12 meses, y después se borra solo.</li>
  <li>Registros técnicos: por poco tiempo, según la rotación de cada sistema.</li>
</ul>

<h2>7. Tus derechos</h2>
<p>Puedes pedir el acceso a tus datos, y su rectificación, actualización o supresión, escribiendo a {$mail} desde la dirección a la que quieres que te respondamos, o acreditando que controlas la billetera. Respondemos en los plazos de la ley (en Argentina, Ley 25.326: diez días corridos para el acceso, cinco días hábiles para la rectificación o supresión). Si te aplica el RGPD (en el Espacio Económico Europeo, y de forma similar en el Reino Unido y Suiza), también tienes derecho a oponerte al tratamiento basado en nuestro interés legítimo, a la limitación del tratamiento, a la portabilidad de los datos y a presentar una reclamación ante la autoridad de protección de datos del lugar donde vives o trabajas; respondemos dentro de un mes. Si vives en otro lugar cuya ley te da más derechos (por ejemplo, en Brasil o en California), puedes ejercerlos de la misma manera. Eliminar una cuenta no borra lo que está en la blockchain.</p>
<p>El titular de los datos personales tiene la facultad de ejercer el derecho de acceso a los mismos en forma gratuita a intervalos no inferiores a seis meses, salvo que se acredite un interés legítimo al efecto conforme lo establecido en el artículo 14, inciso 3 de la Ley Nº 25.326. La AGENCIA DE ACCESO A LA INFORMACIÓN PÚBLICA, en su carácter de Órgano de Control de la Ley Nº 25.326, tiene la atribución de atender las denuncias y reclamos que interpongan quienes resulten afectados en sus derechos por incumplimiento de las normas vigentes en materia de protección de datos personales.</p>

<h2>8. Seguridad</h2>
<p>Protegemos los datos con medidas técnicas y organizativas razonables. Las claves de pago de los vendedores nunca se guardan en la configuración del sitio, y muchos vendedores no necesitan ninguna clave en el sitio. Ningún sistema es completamente seguro: si una brecha te afecta, te lo informaremos según exija la ley.</p>

<h2>9. Menores de edad</h2>
<p>Ombú no está dirigido a menores de 18 años y no recolecta sus datos a sabiendas. Si crees que un menor nos dio datos, escribe a {$mail} y los eliminaremos.</p>

<h2>10. Cambios</h2>
<p>Podemos modificar esta política; la fecha al comienzo indica cuándo. Si un cambio es relevante, lo avisaremos en el sitio.</p>
HTML];

// The footer menu: the two pages and the report form, in every language.
$nodes = \Drupal::entityTypeManager()->getStorage('node');
$links = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$footer = [];
// ponytail: needs the page type from seed-pages.php; the recipe ships it later.
foreach ($pages as $alias => [$title, $body]) {
  $path = \Drupal::service('path_alias.repository')->lookupByAlias($alias, 'en');
  if ($path) {
    $node = $nodes->load((int) basename($path['path']));
    echo "$alias exists (node/{$node->id()}): left as it is\n";
    $footer['entity:node/' . $node->id()] = [$title, $es[$alias][0]];
    continue;
  }
  $node = $nodes->create(['type' => 'page', 'uid' => 1, 'path' => ['alias' => $alias]]);
  $node->set('title', $title)->set('body', ['value' => $body, 'format' => 'full_html'])->setPublished()->save();
  if ($spanish) {
    [$es_title, $es_body] = $es[$alias];
    $t = $node->hasTranslation('es') ? $node->getTranslation('es') : $node->addTranslation('es', ['path' => ['alias' => $alias]]);
    $t->set('title', $es_title)->set('body', ['value' => $es_body, 'format' => 'full_html'])->setPublished()->save();
  }
  $footer['entity:node/' . $node->id()] = [$title, $es[$alias][0]];
  echo "$alias → node/{$node->id()}\n";
}
$footer['internal:/contact/report'] = ['Report abuse', 'Denunciar un abuso'];
$footer['internal:/contact/withdrawal'] = ['Cancel a purchase', 'Botón de arrepentimiento'];
$weight = 0;
foreach ($footer as $uri => [$title, $es_title]) {
  $found = $links->loadByProperties(['link.uri' => $uri, 'menu_name' => 'footer']);
  $link = $found ? reset($found) : $links->create(['link' => ['uri' => $uri], 'menu_name' => 'footer']);
  $link->set('title', $title)->set('weight', $weight++)->save();
  if ($spanish) {
    ($link->hasTranslation('es') ? $link->getTranslation('es') : $link->addTranslation('es'))->set('title', $es_title)->save();
  }
}

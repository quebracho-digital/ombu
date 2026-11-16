<?php

/**
 * @file
 * The "What's new" of the deploy of the stop switch: the security update of
 * the payment library (0.5.1) and of x402_pay (1.0.0-beta5), the stop
 * switch and its blog posts, the wallets we refuse, the wallet a listing
 * declared and its history, reviews judged by the day of the purchase, and
 * the search by wallet. Every claim is in the commits of the branch chore/x402-0.5.1.
 *
 * Seeded with the deploy, published: every deploy goes with its post. The
 * pt-br needs a native review.
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'categories' => ['whats-new'],
  'image' => 'ombu-tree.jpg',
  'alt' => [
    'en' => 'An ombú tree growing by an old city wall',
    'es' => 'Un ombú que crece junto a una muralla antigua',
    'pt-br' => 'Um ombú que cresce junto a uma muralha antiga',
  ],
  'date' => '2026-11-16 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú: a stop switch, a fix for malformed payments and wallets we refuse", <<<HTML
<p class="text-body-secondary small">16 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="An ombú tree growing by an old city wall."></figure>

<p class="lead">This deploy is about what happens when something goes wrong: a malformed payment, a key we no longer trust, a wallet we do not want to sell to.</p>

<h2>A malformed payment no longer makes a paid URL fail</h2>
<p>An audit of our payment library found that a payment whose nonce or payer ended in a line break made a paid URL answer an error 500 instead of refusing the payment. Anyone could cause it, with no funds and no valid signature. Nothing was charged and nothing was delivered: it was noise and errors in the log. The library (<code>quebracho/x402</code> 0.5.1) and the module (<code>x402_pay</code> 1.0.0-beta5) now take a field whole or not at all, and such a payment is answered <code>402 invalid_payload</code>. Ombú had the same weak pattern in the check of a seller's wallet when it imports a catalogue; it uses the library's check now.</p>

<h2>A stop switch</h2>
<p>One command pauses payments and then puts the whole site in read-only mode. While it lasts:</p>
<ul>
<li>the market can still be read and searched, by people and by agents, over the web and over MCP;</li>
<li>nothing can be bought, sold, reviewed or changed: every write answers <code>503</code> with <code>Retry-After</code>;</li>
<li>a paid URL answers <code>503 payments_paused</code> instead of <code>402</code>, and the paid MCP tool answers the same reason: no price is quoted and no payment is looked at;</li>
<li>no page, JSON, markdown or MCP result shows a wallet, so nobody is told where to pay.</li>
</ul>
<p>Each stop and each resume is published here, in the blog, under <em>Service status</em>, with the reason, in the three languages. If you run an agent that buys on Ombú: treat a <code>503</code> with <code>Retry-After</code> as "come back later", not as a failed purchase.</p>

<h2>Wallets we refuse, and the wallet a listing declared</h2>
<p>Ombú now keeps its own deny list of wallets: one on it buys nothing here, and the sale says <code>address_denied</code> before anything is verified or charged. It comes after the sanctions list that was already asked for every payer.</p>
<p>A listing imported from a catalogue keeps the wallet it came in with. If the catalogue or the seller's own answer later names another wallet, the listing is retired instead of updated: a changed address is what an attack in the middle looks like, so a person looks at it first.</p>

<p>When a seller does change wallet, staff can accept the new one after the other checks pass again, and the listing stays the same: its address, its reviews.</p>

<h2>Reviews: the wallet and the price of the day you bought</h2>
<p>A review needs a purchase. Now a listing keeps what it offered before each change, dated, so a purchase is judged by the day it was made: it must have paid the wallet the listing had that day, at least the price of that day. If you bought before a seller changed wallet, you can still review. One purchase backs one review on the whole site, never a second one on another listing.</p>

<h2>Search by wallet</h2>
<p>The market can be filtered by the wallet a service is paid to: <code>/market?wallet[]=0x…</code> for people, <code>/market.json?wallet=0x…</code> and the MCP search for agents. It is also a facet, the wallets with most services, and every service links to the others paid to the same wallet.</p>

<h2>What is not done</h2>
<p>The stop switch does not reach pages that a browser or our CDN cached before the stop, and cannot recall a payment already signed. The importers of the Bazaar and PayAI catalogues are still not running in production.</p>
HTML, 'A stop switch that pauses payments and makes the site read-only, a fix for malformed payments, and wallets we refuse.'],
  'es' => ['Novedades de Ombú: un stop switch, un arreglo para pagos mal formados y billeteras que rechazamos', <<<HTML
<p class="text-body-secondary small">16 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Un ombú que crece junto a una muralla antigua."></figure>

<p class="lead">Este deploy trata de lo que pasa cuando algo sale mal: un pago mal formado, una clave en la que ya no confiamos, una billetera a la que no queremos venderle.</p>

<h2>Un pago mal formado ya no hace fallar una URL paga</h2>
<p>Una auditoría de nuestra librería de pagos encontró que un pago cuyo nonce o pagador terminaba en un salto de línea hacía que una URL paga respondiera un error 500 en vez de rechazar el pago. Cualquiera podía provocarlo, sin fondos y sin una firma válida. No se cobraba ni se entregaba nada: era ruido y errores en el log. La librería (<code>quebracho/x402</code> 0.5.1) y el módulo (<code>x402_pay</code> 1.0.0-beta5) ahora toman cada campo entero o no lo toman, y un pago así recibe <code>402 invalid_payload</code>. Ombú tenía el mismo patrón débil al revisar la billetera de un vendedor cuando importa un catálogo; ahora usa el chequeo de la librería.</p>

<h2>Un stop switch</h2>
<p>Un comando pausa los pagos y después deja todo el sitio en modo de solo lectura. Mientras dura:</p>
<ul>
<li>el mercado se puede seguir leyendo y buscando, personas y agentes, por la web y por MCP;</li>
<li>no se puede comprar, vender, opinar ni cambiar nada: toda escritura responde <code>503</code> con <code>Retry-After</code>;</li>
<li>una URL paga responde <code>503 payments_paused</code> en lugar de <code>402</code>, y la herramienta paga del MCP responde el mismo motivo: no se cotiza ningún precio ni se mira ningún pago;</li>
<li>ninguna página, JSON, markdown ni resultado de MCP muestra una billetera, así nadie recibe una dirección a la que pagar.</li>
</ul>
<p>Cada parada y cada reanudación se publica acá, en el blog, bajo <em>Estado del servicio</em>, con el motivo, en los tres idiomas. Si tenés un agente que compra en Ombú: tomá un <code>503</code> con <code>Retry-After</code> como "volvé más tarde", no como una compra fallida.</p>

<h2>Billeteras que rechazamos, y la billetera que declaró un servicio</h2>
<p>Ombú ahora lleva su propia lista de billeteras bloqueadas: la que está ahí no compra nada acá, y la venta responde <code>address_denied</code> antes de verificar o cobrar nada. Va después de la lista de sanciones que ya se consultaba para cada pagador.</p>
<p>Un servicio importado de un catálogo conserva la billetera con la que entró. Si después el catálogo o la respuesta del propio vendedor nombran otra, el servicio se retira en vez de actualizarse: una dirección cambiada es justo lo que se ve en un ataque de intermediario, así que primero lo mira una persona.</p>

<p>Cuando un vendedor sí cambia de billetera, el staff puede aceptar la nueva después de repetir los demás chequeos, y el servicio sigue siendo el mismo: su dirección, sus reseñas.</p>

<h2>Reseñas: la billetera y el precio del día en que compraste</h2>
<p>Una reseña necesita una compra. Ahora cada servicio conserva lo que ofrecía antes de cada cambio, con fecha, así que una compra se juzga por el día en que se hizo: tiene que haber pagado a la billetera que el servicio tenía ese día, y al menos el precio de ese día. Si compraste antes de que un vendedor cambiara de billetera, igual podés opinar. Una compra respalda una sola reseña en todo el sitio, nunca una segunda en otro servicio.</p>

<h2>Buscar por billetera</h2>
<p>El mercado se puede filtrar por la billetera en la que cobra un servicio: <code>/market?wallet[]=0x…</code> para personas, <code>/market.json?wallet=0x…</code> y la búsqueda por MCP para agentes. También es una faceta, las billeteras con más servicios, y cada servicio enlaza a los demás que cobran en la misma billetera.</p>

<h2>Lo que no está hecho</h2>
<p>El stop switch no alcanza a las páginas que un navegador o nuestra CDN guardaron antes de la parada, y no puede deshacer un pago ya firmado. Los importadores de los catálogos de Bazaar y PayAI siguen sin correr en producción.</p>
HTML, 'Un stop switch que pausa los pagos y deja el sitio en solo lectura, un arreglo para pagos mal formados y billeteras que rechazamos.'],
  'pt-br' => ['Novidades do Ombú: um stop switch, uma correção para pagamentos malformados e carteiras que recusamos', <<<HTML
<p class="text-body-secondary small">16 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Um ombú que cresce junto a uma muralha antiga."></figure>

<p class="lead">Este deploy trata do que acontece quando algo dá errado: um pagamento malformado, uma chave em que não confiamos mais, uma carteira para a qual não queremos vender.</p>

<h2>Um pagamento malformado não faz mais uma URL paga falhar</h2>
<p>Uma auditoria da nossa biblioteca de pagamentos descobriu que um pagamento cujo nonce ou pagador terminava em uma quebra de linha fazia uma URL paga responder um erro 500 em vez de recusar o pagamento. Qualquer pessoa podia provocar isso, sem fundos e sem uma assinatura válida. Nada era cobrado nem entregue: era ruído e erros no log. A biblioteca (<code>quebracho/x402</code> 0.5.1) e o módulo (<code>x402_pay</code> 1.0.0-beta5) agora aceitam cada campo inteiro ou não aceitam, e um pagamento assim recebe <code>402 invalid_payload</code>. O Ombú tinha o mesmo padrão fraco ao conferir a carteira de um vendedor quando importa um catálogo; agora usa a verificação da biblioteca.</p>

<h2>Um stop switch</h2>
<p>Um comando pausa os pagamentos e depois deixa o site inteiro em modo somente leitura. Enquanto durar:</p>
<ul>
<li>o mercado continua podendo ser lido e pesquisado, por pessoas e por agentes, pela web e por MCP;</li>
<li>não é possível comprar, vender, avaliar nem alterar nada: toda escrita responde <code>503</code> com <code>Retry-After</code>;</li>
<li>uma URL paga responde <code>503 payments_paused</code> em vez de <code>402</code>, e a ferramenta paga do MCP responde o mesmo motivo: nenhum preço é cotado e nenhum pagamento é examinado;</li>
<li>nenhuma página, JSON, markdown ou resultado de MCP mostra uma carteira, então ninguém recebe um endereço para pagar.</li>
</ul>
<p>Cada parada e cada retomada é publicada aqui, no blog, em <em>Status do serviço</em>, com o motivo, nos três idiomas. Se você tem um agente que compra no Ombú: trate um <code>503</code> com <code>Retry-After</code> como "volte mais tarde", não como uma compra que falhou.</p>

<h2>Carteiras que recusamos, e a carteira que um serviço declarou</h2>
<p>O Ombú agora mantém a sua própria lista de carteiras bloqueadas: a que está nela não compra nada aqui, e a venda responde <code>address_denied</code> antes de verificar ou cobrar qualquer coisa. Ela vem depois da lista de sanções que já era consultada para cada pagador.</p>
<p>Um serviço importado de um catálogo mantém a carteira com que entrou. Se depois o catálogo ou a resposta do próprio vendedor indicarem outra, o serviço é retirado em vez de atualizado: um endereço trocado é exatamente o que se vê em um ataque de intermediário, então primeiro uma pessoa examina.</p>

<p>Quando um vendedor de fato troca de carteira, a equipe pode aceitar a nova depois de repetir as outras verificações, e o serviço continua o mesmo: o seu endereço, as suas avaliações.</p>

<h2>Avaliações: a carteira e o preço do dia em que você comprou</h2>
<p>Uma avaliação precisa de uma compra. Agora cada serviço guarda o que oferecia antes de cada mudança, com data, então uma compra é julgada pelo dia em que foi feita: precisa ter pago a carteira que o serviço tinha naquele dia, e pelo menos o preço daquele dia. Se você comprou antes de um vendedor trocar de carteira, ainda pode avaliar. Uma compra sustenta uma única avaliação em todo o site, nunca uma segunda em outro serviço.</p>

<h2>Pesquisar por carteira</h2>
<p>O mercado pode ser filtrado pela carteira em que um serviço recebe: <code>/market?wallet[]=0x…</code> para pessoas, <code>/market.json?wallet=0x…</code> e a pesquisa por MCP para agentes. Também é uma faceta, as carteiras com mais serviços, e cada serviço aponta para os outros que recebem na mesma carteira.</p>

<h2>O que não está feito</h2>
<p>O stop switch não alcança as páginas que um navegador ou a nossa CDN guardaram antes da parada, e não pode desfazer um pagamento já assinado. Os importadores dos catálogos Bazaar e PayAI continuam sem rodar em produção.</p>
HTML, 'Um stop switch que pausa os pagamentos e deixa o site somente leitura, uma correção para pagamentos malformados e carteiras que recusamos.'],
];

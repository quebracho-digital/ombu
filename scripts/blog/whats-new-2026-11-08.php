<?php

/**
 * @file
 * Fourth post, the first "What's new": what the 2026-11-08 deploy brings.
 * Every item is a commit between origin/main and the deployed tip; nothing
 * that is not deployed is promised (the catalogue importer, the paid
 * verifier and OFAC in quebracho/x402 are not in it).
 *
 * DRAFT: saved unpublished. Before it goes live: Leo's review, a native
 * review of pt-br, and a check that every item is in what was deployed
 * (Meilisearch's paragraph depends on whether SER5 gets its server).
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'draft' => TRUE,
  'categories' => ['whats-new'],
  'image' => 'market-hall.jpg',
  'alt' => [
    'en' => 'Inside the municipal market of La Quiaca, Argentina',
    'es' => 'El interior del mercado municipal de La Quiaca, Argentina',
    'pt-br' => 'O interior do mercado municipal de La Quiaca, Argentina',
  ],
  'date' => '2026-11-08 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú: a blog for a home, featured services, and our own search module", <<<HTML
<p class="text-body-secondary small">8 November 2026 · Quebracho</p>

<p class="lead">Every time we deploy, we will write down what changed, here, under <em>What's new</em>. This is the first one.</p>

<h2>The home page is now the blog</h2>
<p>Ombú opens on its blog: cards with a photo, a date and a short summary, in English, Spanish and Portuguese, each language showing only its own posts. The market is one click away, in the main menu. Agents get the same list as markdown at <code>/blog.md</code>.</p>
<p>Posts now have categories. <em>What's new</em> marks the post of each deploy, like this one.</p>

<h2>Featured services</h2>
<p>A listing can be marked as featured, and the featured ones get their own block on the home page.</p>

<h2>Similar services, from our own search module</h2>
<p>The list of similar services under each listing can come from Meilisearch, through a module we now maintain ourselves: <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, a fork of the contrib module with the vectors Ombú already computes and Meilisearch's "similar documents". It is open source, under the GPL. If the search server does not answer, the page does not break: the list comes from the database, as before.</p>

<h2>Credits in the footer</h2>
<p>The footer now says what Ombú runs on: Drupal and Drupal Commerce, and who builds it.</p>

<h2>Measuring before importing</h2>
<p>We are looking at more catalogues of x402 services than Circle's. Before a single listing comes in, we measure: how many answer with a valid payment request, how many charge what their catalogue says, and how many wallets are frozen by their token. Nothing from those catalogues is listed yet; when it is, it will be in a post like this one.</p>
HTML, "The first What's new: the blog is the home page, services can be featured, similar services can come from our own open-source Meilisearch module, and the footer credits Drupal and Drupal Commerce."],
  'es' => ['Novedades en Ombú: el blog como portada, servicios destacados y nuestro propio módulo de búsqueda', <<<HTML
<p class="text-body-secondary small">8 de noviembre de 2026 · Quebracho</p>

<p class="lead">Cada vez que despleguemos, vamos a contar acá lo que cambió, bajo <em>Novedades</em>. Esta es la primera.</p>

<h2>La portada ahora es el blog</h2>
<p>Ombú abre en su blog: tarjetas con foto, fecha y un resumen corto, en inglés, español y portugués, y cada idioma muestra solo sus propios posteos. El mercado queda a un clic, en el menú principal. Los agentes reciben la misma lista en markdown en <code>/blog.md</code>.</p>
<p>Los posteos ahora tienen categorías. <em>Novedades</em> marca el posteo de cada despliegue, como este.</p>

<h2>Servicios destacados</h2>
<p>Un listado se puede marcar como destacado, y los destacados tienen su propio bloque en la portada.</p>

<h2>Servicios similares, desde nuestro propio módulo de búsqueda</h2>
<p>La lista de servicios similares de cada listado puede venir de Meilisearch, con un módulo que ahora mantenemos nosotros: <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, un fork del módulo contrib con los vectores que Ombú ya calcula y los "documentos similares" de Meilisearch. Es código abierto, bajo la GPL. Si el servidor de búsqueda no responde, la página no se rompe: la lista sale de la base de datos, como antes.</p>

<h2>Créditos en el pie</h2>
<p>El pie de página ahora dice sobre qué funciona Ombú, Drupal y Drupal Commerce, y quién lo hace.</p>

<h2>Medir antes de importar</h2>
<p>Estamos mirando más catálogos de servicios x402 además del de Circle. Antes de que entre un solo listado, medimos: cuántos responden con un pedido de pago válido, cuántos cobran lo que dice su catálogo y cuántas wallets están congeladas por su token. Todavía no hay listados de esos catálogos; cuando los haya, lo vamos a contar en un posteo como este.</p>
HTML, 'Las primeras Novedades: el blog es la portada, los servicios se pueden destacar, los similares pueden venir de nuestro propio módulo de Meilisearch, de código abierto, y el pie da crédito a Drupal y Drupal Commerce.'],
  'pt-br' => ['Novidades no Ombú: o blog como página inicial, serviços em destaque e nosso próprio módulo de busca', <<<HTML
<p class="text-body-secondary small">8 de novembro de 2026 · Quebracho</p>

<p class="lead">A cada implantação, vamos contar aqui o que mudou, em <em>Novidades</em>. Esta é a primeira.</p>

<h2>A página inicial agora é o blog</h2>
<p>O Ombú abre no seu blog: cartões com foto, data e um resumo curto, em inglês, espanhol e português, e cada idioma mostra só as suas próprias publicações. O mercado fica a um clique, no menu principal. Os agentes recebem a mesma lista em markdown em <code>/blog.md</code>.</p>
<p>As publicações agora têm categorias. <em>Novidades</em> marca a publicação de cada implantação, como esta.</p>

<h2>Serviços em destaque</h2>
<p>Um anúncio pode ser marcado como destaque, e os destaques ganham um bloco próprio na página inicial.</p>

<h2>Serviços parecidos, do nosso próprio módulo de busca</h2>
<p>A lista de serviços parecidos de cada anúncio pode vir do Meilisearch, por um módulo que agora nós mesmos mantemos: <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, um fork do módulo contrib com os vetores que o Ombú já calcula e os "documentos parecidos" do Meilisearch. É código aberto, sob a GPL. Se o servidor de busca não responder, a página não quebra: a lista vem do banco de dados, como antes.</p>

<h2>Créditos no rodapé</h2>
<p>O rodapé agora diz sobre o que o Ombú funciona, Drupal e Drupal Commerce, e quem o faz.</p>

<h2>Medir antes de importar</h2>
<p>Estamos olhando mais catálogos de serviços x402 além do da Circle. Antes de entrar um único anúncio, medimos: quantos respondem com um pedido de pagamento válido, quantos cobram o que diz o seu catálogo e quantas carteiras estão congeladas pelo seu token. Ainda não há anúncios desses catálogos; quando houver, vamos contar numa publicação como esta.</p>
HTML, 'As primeiras Novidades: o blog é a página inicial, serviços podem ficar em destaque, os parecidos podem vir do nosso próprio módulo de Meilisearch, de código aberto, e o rodapé dá crédito ao Drupal e ao Drupal Commerce.'],
];

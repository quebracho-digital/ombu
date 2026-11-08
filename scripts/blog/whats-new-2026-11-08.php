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
<p>Under each listing, Ombú shows other services that could stand in for it. The list comes from two rankings that are then fused, and this is what each one means.</p>

<h3>How two services come to be similar</h3>
<ul>
<li><strong>By shared terms.</strong> Two listings that share a category, a tag or a kind are closer. A term counts for more the rarer it is: its weight is the logarithm of the number of listings divided by the number that carry it, so "x402", which almost every listing has, adds nothing. The kind (our own internal category) counts double once it is filled in.</li>
<li><strong>By meaning.</strong> Ombú turns the text of each listing into a vector with an embedding model (Qwen3-Embedding-8B today) and compares two vectors by their cosine, from 0 to 1. Two services that say the same thing in different words end up close.</li>
<li><strong>Fusing the two.</strong> We do not add the scores, because the two scales mean different things. We use reciprocal rank fusion: each ranking gives a service points by its place, and the points shrink slowly (a first place adds 1/61 of the ranking's weight, a tenth place 1/70). A service that is high in both rankings comes first, and no single ranking decides. The weight of each ranking and of each field can be changed from the admin.</li>
<li><strong>Alternatives, not neighbours.</strong> One provider has more than half of the catalogue, and its services resemble each other. So a list shows at most two services of the same provider, never a retired or unpublished one, and up to twelve in all.</li>
</ul>
<p>Each suggestion keeps the reason it is there, so the page can say whether it came from shared terms, from close meaning or from both. We have not yet measured whether people find these lists useful; the post about MCP says what we have and have not measured.</p>

<h3>Why we forked a Drupal module</h3>
<p>Meilisearch can answer "which documents are like this one" by itself. The Drupal module for Meilisearch, though, assumes that Meilisearch computes the vectors, and Ombú already computes its own: it needs them for the fusion and for the fallback. So we made three changes to the module: let Drupal hand its own vectors to Meilisearch, search with a vector the caller brings, and offer "More like this" through Meilisearch's similar documents.</p>
<p>We proposed those changes to the original module on drupal.org, as three issues, and we are giving its maintainer time to answer. Meanwhile we run a fork, <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, so nothing waits for anyone. It keeps the original history and the GPL licence, and its README says it is a fork. We may still contribute the work back.</p>
<p>If the search server does not answer, the page does not break: the list comes from the database, as before.</p>

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
<p>Debajo de cada listado, Ombú muestra otros servicios que podrían reemplazarlo. La lista sale de dos rankings que después se fusionan, y esto es lo que significa cada uno.</p>

<h3>Cómo dos servicios llegan a ser similares</h3>
<ul>
<li><strong>Por términos compartidos.</strong> Dos listados que comparten una categoría, una etiqueta o un tipo están más cerca. Un término cuenta más cuanto más raro es: su peso es el logaritmo de la cantidad de listados dividida por la cantidad que lo tienen, así que "x402", que casi todos tienen, no suma nada. El tipo (nuestra categoría interna) cuenta doble cuando está completo.</li>
<li><strong>Por significado.</strong> Ombú convierte el texto de cada listado en un vector con un modelo de embeddings (hoy Qwen3-Embedding-8B) y compara dos vectores por su coseno, de 0 a 1. Dos servicios que dicen lo mismo con otras palabras quedan cerca.</li>
<li><strong>Fusionar los dos.</strong> No sumamos los puntajes, porque las dos escalas significan cosas distintas. Usamos la fusión de rangos recíprocos: cada ranking le da puntos a un servicio según su lugar, y los puntos bajan despacio (el primer lugar suma 1/61 del peso del ranking, el décimo 1/70). Un servicio alto en los dos rankings va primero, y ningún ranking decide solo. El peso de cada ranking y de cada campo se cambia desde la administración.</li>
<li><strong>Alternativas, no vecinos.</strong> Un proveedor tiene más de la mitad del catálogo, y sus servicios se parecen entre sí. Por eso una lista muestra como máximo dos servicios del mismo proveedor, nunca uno retirado o sin publicar, y hasta doce en total.</li>
</ul>
<p>Cada sugerencia guarda la razón por la que está, así que la página puede decir si vino de términos compartidos, de significado cercano o de ambos. Todavía no medimos si estas listas le sirven a la gente; el posteo sobre MCP cuenta qué medimos y qué no.</p>

<h3>Por qué hicimos un fork de un módulo de Drupal</h3>
<p>Meilisearch puede responder solo "qué documentos se parecen a este". Pero el módulo de Drupal para Meilisearch supone que Meilisearch calcula los vectores, y Ombú ya calcula los suyos: los necesita para la fusión y para el respaldo. Por eso le hicimos tres cambios al módulo: que Drupal le pase sus propios vectores a Meilisearch, buscar con un vector que trae quien llama, y ofrecer "More like this" con los documentos similares de Meilisearch.</p>
<p>Propusimos esos cambios al módulo original en drupal.org, como tres issues, y le estamos dando tiempo a su mantenedor para que responda. Mientras tanto usamos un fork, <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, para que nada dependa de nadie. Conserva el historial original y la licencia GPL, y su README dice que es un fork. Todavía podemos devolver el trabajo al proyecto original.</p>
<p>Si el servidor de búsqueda no responde, la página no se rompe: la lista sale de la base de datos, como antes.</p>

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
<p>Abaixo de cada anúncio, o Ombú mostra outros serviços que poderiam substituí-lo. A lista vem de dois rankings que depois são fundidos, e é isto o que cada um significa.</p>

<h3>Como dois serviços passam a ser parecidos</h3>
<ul>
<li><strong>Por termos em comum.</strong> Dois anúncios que compartilham uma categoria, uma etiqueta ou um tipo ficam mais próximos. Um termo vale mais quanto mais raro é: seu peso é o logaritmo do número de anúncios dividido pelo número dos que o têm, então "x402", que quase todos têm, não soma nada. O tipo (nossa categoria interna) vale o dobro quando está preenchido.</li>
<li><strong>Por significado.</strong> O Ombú transforma o texto de cada anúncio em um vetor com um modelo de embeddings (hoje o Qwen3-Embedding-8B) e compara dois vetores pelo cosseno, de 0 a 1. Dois serviços que dizem a mesma coisa com outras palavras ficam próximos.</li>
<li><strong>Fundir os dois.</strong> Não somamos as pontuações, porque as duas escalas significam coisas diferentes. Usamos a fusão de ranks recíprocos: cada ranking dá pontos a um serviço pela sua posição, e os pontos diminuem devagar (o primeiro lugar soma 1/61 do peso do ranking, o décimo 1/70). Um serviço alto nos dois rankings vem primeiro, e nenhum ranking decide sozinho. O peso de cada ranking e de cada campo pode ser mudado na administração.</li>
<li><strong>Alternativas, não vizinhos.</strong> Um provedor tem mais da metade do catálogo, e seus serviços se parecem entre si. Por isso uma lista mostra no máximo dois serviços do mesmo provedor, nunca um aposentado ou não publicado, e até doze no total.</li>
</ul>
<p>Cada sugestão guarda o motivo pelo qual está ali, então a página pode dizer se veio de termos em comum, de significado próximo ou dos dois. Ainda não medimos se essas listas são úteis para as pessoas; a publicação sobre MCP conta o que medimos e o que não.</p>

<h3>Por que fizemos um fork de um módulo do Drupal</h3>
<p>O Meilisearch consegue responder sozinho "quais documentos se parecem com este". Mas o módulo do Drupal para o Meilisearch supõe que o Meilisearch calcula os vetores, e o Ombú já calcula os seus: ele precisa deles para a fusão e para o plano B. Por isso fizemos três mudanças no módulo: deixar o Drupal passar seus próprios vetores ao Meilisearch, buscar com um vetor que quem chama traz, e oferecer "More like this" pelos documentos parecidos do Meilisearch.</p>
<p>Propusemos essas mudanças ao módulo original no drupal.org, como três issues, e estamos dando tempo ao seu mantenedor para responder. Enquanto isso usamos um fork, <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, para que nada dependa de ninguém. Ele mantém o histórico original e a licença GPL, e seu README diz que é um fork. Ainda podemos devolver o trabalho ao projeto original.</p>
<p>Se o servidor de busca não responder, a página não quebra: a lista vem do banco de dados, como antes.</p>

<h2>Créditos no rodapé</h2>
<p>O rodapé agora diz sobre o que o Ombú funciona, Drupal e Drupal Commerce, e quem o faz.</p>

<h2>Medir antes de importar</h2>
<p>Estamos olhando mais catálogos de serviços x402 além do da Circle. Antes de entrar um único anúncio, medimos: quantos respondem com um pedido de pagamento válido, quantos cobram o que diz o seu catálogo e quantas carteiras estão congeladas pelo seu token. Ainda não há anúncios desses catálogos; quando houver, vamos contar numa publicação como esta.</p>
HTML, 'As primeiras Novidades: o blog é a página inicial, serviços podem ficar em destaque, os parecidos podem vir do nosso próprio módulo de Meilisearch, de código aberto, e o rodapé dá crédito ao Drupal e ao Drupal Commerce.'],
];

<?php

/**
 * @file
 * Third post: what the MCP server gives an agent (search, read, and a paid
 * call), and how "similar services" are ranked. Every claim is in the code:
 * the three tools (Plugin/mcp_server/Tool), the payment in band
 * (Mcp/McpPayments), and the two rankings fused with RRF (Similar).
 *
 * Draft: the three languages are written, but pt-br needs a native review
 * before it goes live. The date is the day of the deploy.
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'categories' => ['how-it-works'],
  'image' => 'ombu-tree.jpg',
  'alt' => [
    'en' => 'An ombú tree growing from the old walls of Rhodes Castle',
    'es' => 'Un ombú creciendo sobre los muros antiguos del castillo de Rodas',
    'pt-br' => 'Uma árvore ombú crescendo sobre os muros antigos do castelo de Rodes',
  ],
  'date' => '2026-11-08 10:00:00 America/Argentina/Buenos_Aires',
  'en' => ['Agents can find, judge and buy over MCP, and a service can show its look-alikes', <<<HTML
<p class="text-body-secondary small">8 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="An ombú tree growing from the old walls of Rhodes Castle."><figcaption class="figure-caption">An ombú in the walls of Rhodes Castle. Ours grows a service at a time.</figcaption></figure>

<p class="lead">An agent that uses Ombú does not need a web page. It can connect to our MCP server, search the services, read one, and buy a call paid in USDC on the same rails the rest of the market uses. This post explains the three tools, how a payment rides inside the MCP call, and how a service can show the others that look like it.</p>

<h2>Find and read: two free tools</h2>
<p><code>ombu_search_services</code> searches the market with the same query as the JSON catalogue and the Markdown market, so an agent and a person get the same answer. <code>ombu_get_service</code> returns one service with its price, its networks, its provider and its reviews. Both are free and read only.</p>

<h2>Buy one call, in the protocol</h2>
<p><code>ombu_call_service</code> calls one of the services Ombú sells itself, such as the ping, the page to Markdown, the terms analysis, the fact-check and the name check. Without payment the tool answers with an error that carries the x402 <code>PaymentRequired</code>. The agent pays what the service charges and calls again with the payment in <code>_meta["x402/payment"]</code>, which is where MCP lets a client send extra data. A call that fails is not charged.</p>

<p>So the agent never needs a second channel to pay: the price, the payment and the receipt travel in the same conversation with the server.</p>

<h2>Similar services, and why each one is there</h2>
<p>Under each listing, Ombú shows other services that could stand in for it. The list comes from two rankings that are then fused, and this is what each one means.</p>

<h3>How two services come to be similar</h3>
<ul>
<li><strong>By shared terms.</strong> Two listings that share a category, a tag or a kind are closer. A term counts for more the rarer it is: its weight is the logarithm of the number of listings divided by the number that carry it, so "x402", which almost every listing has, adds nothing. The kind (our own internal category) counts double once it is filled in.</li>
<li><strong>By meaning.</strong> Ombú turns the text of each listing into a vector with an embedding model (Qwen3-Embedding-8B today) and compares two vectors by their cosine, from 0 to 1. Two services that say the same thing in different words end up close.</li>
<li><strong>Fusing the two.</strong> We do not add the scores, because the two scales mean different things. We use reciprocal rank fusion: each ranking gives a service points by its place, and the points shrink slowly (a first place adds 1/61 of the ranking's weight, a tenth place 1/70). A service that is high in both rankings comes first, and no single ranking decides. The weight of each ranking and of each field can be changed from the admin.</li>
<li><strong>Alternatives, not neighbours.</strong> One provider has more than half of the catalogue, and its services resemble each other. So a list shows at most two services of the same provider, never a retired or unpublished one, and up to twelve in all.</li>
</ul>
<p>Each suggestion keeps the reason it is there, so the page can say whether it came from shared terms, from close meaning or from both. We have not yet measured whether people find these lists useful; the last section says what we have and have not measured.</p>

<h3>Why we forked a Drupal module</h3>
<p>Meilisearch can answer "which documents are like this one" by itself. The Drupal module for Meilisearch, though, assumes that Meilisearch computes the vectors, and Ombú already computes its own: it needs them for the fusion and for the fallback. So we made three changes to the module: let Drupal hand its own vectors to Meilisearch, search with a vector the caller brings, and offer "More like this" through Meilisearch's similar documents.</p>
<p>We proposed those changes to the original module on drupal.org, as three issues, and we are giving its maintainer time to answer. Meanwhile we run a fork, <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, so nothing waits for anyone. It keeps the original history and the GPL licence, and its README says it is a fork. We may still contribute the work back.</p>
<p>If the search server does not answer, the page does not break: the list comes from the database, as before.</p>

<h2>What we have not measured yet</h2>
<p>We do not publish a quality score for the similar list yet. The weights of the two rankings are settings, and we will show numbers when we have a labelled set of services that people judged as similar or not. Until then, the claim is only what the list does, not how well it does it.</p>

<p>The MCP server is at <code>/mcp</code>. The source of every claim above is in the repository, and the tools are described in the MCP's own listing, so a client can read them before it calls anything.</p>
HTML, 'Agents can use Ombú through MCP: search and read services for free, buy a call paid in USDC inside the MCP call, and see similar services ranked by two fused lists, with the reason for each.'],
  'es' => ['Agentes que encuentran, juzgan y compran por MCP, y un servicio muestra a sus parecidos', <<<HTML
<p class="text-body-secondary small">8 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Un ombú creciendo sobre los muros antiguos del castillo de Rodas."><figcaption class="figure-caption">Un ombú en los muros del castillo de Rodas. El nuestro crece un servicio por vez.</figcaption></figure>

<p class="lead">Un agente que usa Ombú no necesita una página web. Puede conectarse a nuestro servidor MCP, buscar servicios, leer uno y comprar una llamada pagada en USDC sobre los mismos rieles que usa el resto del mercado. Este post explica las tres herramientas, cómo viaja el pago dentro de la llamada MCP y cómo un servicio muestra a los otros que se le parecen.</p>

<h2>Encontrar y leer: dos herramientas gratis</h2>
<p><code>ombu_search_services</code> busca en el mercado con la misma consulta que el catálogo JSON y el mercado en Markdown, así que un agente y una persona reciben la misma respuesta. <code>ombu_get_service</code> devuelve un servicio con su precio, sus redes, su proveedor y sus reseñas. Ambas son gratis y de solo lectura.</p>

<h2>Comprar una llamada, dentro del protocolo</h2>
<p><code>ombu_call_service</code> llama a uno de los servicios que Ombú vende él mismo, como el ping, la página a Markdown, el análisis de términos, la verificación de datos y la verificación de nombres. Sin pago, la herramienta responde con un error que lleva el <code>PaymentRequired</code> de x402. El agente paga lo que cobra el servicio y vuelve a llamar con el pago en <code>_meta["x402/payment"]</code>, que es el lugar donde MCP permite enviar datos extra. Una llamada que falla no se cobra.</p>

<p>Así el agente no necesita un segundo canal para pagar: el precio, el pago y el recibo viajan en la misma conversación con el servidor.</p>

<h2>Servicios similares, y por qué está cada uno</h2>
<p>Debajo de cada listado, Ombú muestra otros servicios que podrían reemplazarlo. La lista sale de dos rankings que después se fusionan, y esto es lo que significa cada uno.</p>

<h3>Cómo dos servicios llegan a ser similares</h3>
<ul>
<li><strong>Por términos compartidos.</strong> Dos listados que comparten una categoría, una etiqueta o un tipo están más cerca. Un término cuenta más cuanto más raro es: su peso es el logaritmo de la cantidad de listados dividida por la cantidad que lo tienen, así que "x402", que casi todos tienen, no suma nada. El tipo (nuestra categoría interna) cuenta doble cuando está completo.</li>
<li><strong>Por significado.</strong> Ombú convierte el texto de cada listado en un vector con un modelo de embeddings (hoy Qwen3-Embedding-8B) y compara dos vectores por su coseno, de 0 a 1. Dos servicios que dicen lo mismo con otras palabras quedan cerca.</li>
<li><strong>Fusionar los dos.</strong> No sumamos los puntajes, porque las dos escalas significan cosas distintas. Usamos la fusión de rangos recíprocos: cada ranking le da puntos a un servicio según su lugar, y los puntos bajan despacio (el primer lugar suma 1/61 del peso del ranking, el décimo 1/70). Un servicio alto en los dos rankings va primero, y ningún ranking decide solo. El peso de cada ranking y de cada campo se cambia desde la administración.</li>
<li><strong>Alternativas, no vecinos.</strong> Un proveedor tiene más de la mitad del catálogo, y sus servicios se parecen entre sí. Por eso una lista muestra como máximo dos servicios del mismo proveedor, nunca uno retirado o sin publicar, y hasta doce en total.</li>
</ul>
<p>Cada sugerencia guarda la razón por la que está, así que la página puede decir si vino de términos compartidos, de significado cercano o de ambos. Todavía no medimos si estas listas le sirven a la gente; la última sección cuenta qué medimos y qué no.</p>

<h3>Por qué hicimos un fork de un módulo de Drupal</h3>
<p>Meilisearch puede responder solo "qué documentos se parecen a este". Pero el módulo de Drupal para Meilisearch supone que Meilisearch calcula los vectores, y Ombú ya calcula los suyos: los necesita para la fusión y para el respaldo. Por eso le hicimos tres cambios al módulo: que Drupal le pase sus propios vectores a Meilisearch, buscar con un vector que trae quien llama, y ofrecer "More like this" con los documentos similares de Meilisearch.</p>
<p>Propusimos esos cambios al módulo original en drupal.org, como tres issues, y le estamos dando tiempo a su mantenedor para que responda. Mientras tanto usamos un fork, <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, para que nada dependa de nadie. Conserva el historial original y la licencia GPL, y su README dice que es un fork. Todavía podemos devolver el trabajo al proyecto original.</p>
<p>Si el servidor de búsqueda no responde, la página no se rompe: la lista sale de la base de datos, como antes.</p>

<h2>Lo que todavía no medimos</h2>
<p>Todavía no publicamos un puntaje de calidad para la lista de similares. Los pesos de los dos rankings son configuración, y mostraremos números cuando tengamos un conjunto etiquetado de servicios que la gente juzgó parecidos o no. Hasta entonces, lo que afirmamos es lo que hace la lista, no qué tan bien lo hace.</p>

<p>El servidor MCP está en <code>/mcp</code>. El origen de cada afirmación de arriba está en el repositorio, y las herramientas están descritas en el listado MCP, así que un cliente puede leerlas antes de llamar a nada.</p>
HTML, 'Los agentes usan Ombú por MCP: buscan y leen servicios gratis, compran una llamada pagada en USDC dentro de la llamada MCP, y ven servicios similares ordenados por dos listas fusionadas, con el motivo de cada uno.'],
  'pt-br' => ['Agentes que encontram, julgam e compram por MCP, e um serviço mostra os parecidos', <<<HTML
<p class="text-body-secondary small">8 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Uma árvore ombú crescendo sobre os muros antigos do castelo de Rodes."><figcaption class="figure-caption">Um ombú nos muros do castelo de Rodes. O nosso cresce um serviço por vez.</figcaption></figure>

<p class="lead">Um agente que usa o Ombú não precisa de uma página web. Ele pode se conectar ao nosso servidor MCP, buscar serviços, ler um e comprar uma chamada paga em USDC pelos mesmos trilhos que o resto do mercado usa. Este post explica as três ferramentas, como o pagamento viaja dentro da chamada MCP e como um serviço mostra os outros que se parecem com ele.</p>

<h2>Encontrar e ler: duas ferramentas grátis</h2>
<p><code>ombu_search_services</code> busca no mercado com a mesma consulta do catálogo JSON e do mercado em Markdown, então um agente e uma pessoa recebem a mesma resposta. <code>ombu_get_service</code> devolve um serviço com preço, redes, fornecedor e avaliações. As duas são grátis e só de leitura.</p>

<h2>Comprar uma chamada, dentro do protocolo</h2>
<p><code>ombu_call_service</code> chama um dos serviços que o próprio Ombú vende, como o ping, a página para Markdown, a análise de termos, a checagem de fatos e a checagem de nomes. Sem pagamento, a ferramenta responde com um erro que traz o <code>PaymentRequired</code> do x402. O agente paga o que o serviço cobra e chama de novo com o pagamento em <code>_meta["x402/payment"]</code>, que é o lugar onde o MCP permite enviar dados extras. Uma chamada que falha não é cobrada.</p>

<h2>Serviços parecidos, e por que cada um está lá</h2>
<p>Abaixo de cada anúncio, o Ombú mostra outros serviços que poderiam substituí-lo. A lista vem de dois rankings que depois são fundidos, e é isto o que cada um significa.</p>

<h3>Como dois serviços passam a ser parecidos</h3>
<ul>
<li><strong>Por termos em comum.</strong> Dois anúncios que compartilham uma categoria, uma etiqueta ou um tipo ficam mais próximos. Um termo vale mais quanto mais raro é: seu peso é o logaritmo do número de anúncios dividido pelo número dos que o têm, então "x402", que quase todos têm, não soma nada. O tipo (nossa categoria interna) vale o dobro quando está preenchido.</li>
<li><strong>Por significado.</strong> O Ombú transforma o texto de cada anúncio em um vetor com um modelo de embeddings (hoje o Qwen3-Embedding-8B) e compara dois vetores pelo cosseno, de 0 a 1. Dois serviços que dizem a mesma coisa com outras palavras ficam próximos.</li>
<li><strong>Fundir os dois.</strong> Não somamos as pontuações, porque as duas escalas significam coisas diferentes. Usamos a fusão de ranks recíprocos: cada ranking dá pontos a um serviço pela sua posição, e os pontos diminuem devagar (o primeiro lugar soma 1/61 do peso do ranking, o décimo 1/70). Um serviço alto nos dois rankings vem primeiro, e nenhum ranking decide sozinho. O peso de cada ranking e de cada campo pode ser mudado na administração.</li>
<li><strong>Alternativas, não vizinhos.</strong> Um provedor tem mais da metade do catálogo, e seus serviços se parecem entre si. Por isso uma lista mostra no máximo dois serviços do mesmo provedor, nunca um aposentado ou não publicado, e até doze no total.</li>
</ul>
<p>Cada sugestão guarda o motivo pelo qual está ali, então a página pode dizer se veio de termos em comum, de significado próximo ou dos dois. Ainda não medimos se essas listas são úteis para as pessoas; a última seção conta o que medimos e o que não.</p>

<h3>Por que fizemos um fork de um módulo do Drupal</h3>
<p>O Meilisearch consegue responder sozinho "quais documentos se parecem com este". Mas o módulo do Drupal para o Meilisearch supõe que o Meilisearch calcula os vetores, e o Ombú já calcula os seus: ele precisa deles para a fusão e para o plano B. Por isso fizemos três mudanças no módulo: deixar o Drupal passar seus próprios vetores ao Meilisearch, buscar com um vetor que quem chama traz, e oferecer "More like this" pelos documentos parecidos do Meilisearch.</p>
<p>Propusemos essas mudanças ao módulo original no drupal.org, como três issues, e estamos dando tempo ao seu mantenedor para responder. Enquanto isso usamos um fork, <a href="https://github.com/quebracho-digital/quebracho_meilisearch">quebracho_meilisearch</a>, para que nada dependa de ninguém. Ele mantém o histórico original e a licença GPL, e seu README diz que é um fork. Ainda podemos devolver o trabalho ao projeto original.</p>
<p>Se o servidor de busca não responder, a página não quebra: a lista vem do banco de dados, como antes.</p>

<h2>O que ainda não medimos</h2>
<p>Ainda não publicamos uma nota de qualidade para a lista de parecidos. Os pesos dos dois rankings são configuração, e vamos mostrar números quando tivermos um conjunto de serviços rotulados como parecidos ou não pelas pessoas. Até lá, afirmamos o que a lista faz, não o quão bem ela faz.</p>

<p>O servidor MCP fica em <code>/mcp</code>. A origem de cada afirmação acima está no repositório, e as ferramentas estão descritas na listagem do MCP, então um cliente pode lê-las antes de chamar qualquer coisa.</p>
HTML, 'Agentes usam o Ombú por MCP: buscam e leem serviços grátis, compram uma chamada paga em USDC dentro da chamada MCP e veem serviços parecidos ordenados por duas listas fundidas, com o motivo de cada um.'],
];

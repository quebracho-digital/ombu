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
<p>A listing can show other services that look like it. Ombú builds that list from two rankings. One ranks by the terms of the listing: its title, its text and its tags. The other ranks by the meaning of the text, using the vectors of each listing. The two rankings are fused with reciprocal rank fusion: a service that is high in both lists comes first, and a service that is high in only one still appears when it is the best match of that kind. The fusion keeps the reason for each suggestion, so the page can say which ranking put it there.</p>

<p>If the search server is down, the page does not break: a fallback answers from the database until the search server answers again.</p>

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
<p>Un listado puede mostrar otros servicios que se le parecen. Ombú arma esa lista con dos rankings. Uno ordena por los términos del listado: su título, su texto y sus etiquetas. El otro ordena por el significado del texto, con los vectores de cada listado. Los dos rankings se fusionan con fusión por rango recíproco: un servicio que está arriba en ambos viene primero, y uno que está arriba sólo en uno sigue apareciendo cuando es el mejor de ese tipo. La fusión guarda el motivo de cada sugerencia, así la página puede decir qué ranking la puso ahí.</p>

<p>Si el servidor de búsqueda está caído, la página no se rompe: un respaldo responde desde la base de datos hasta que el servidor de búsqueda vuelva.</p>

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

<h2>Serviços parecidos, e por que cada um está ali</h2>
<p>Um anúncio pode mostrar outros serviços parecidos com ele. O Ombú monta essa lista com dois rankings. Um ordena pelos termos do anúncio: título, texto e etiquetas. O outro ordena pelo significado do texto, com os vetores de cada anúncio. Os dois rankings se fundem por fusão de rank recíproco: um serviço que está bem colocado nos dois vem primeiro, e um que está bem colocado só em um continua aparecendo quando é o melhor daquele tipo. A fusão guarda o motivo de cada sugestão, então a página diz qual ranking a colocou ali.</p>

<p>Se o servidor de busca cair, a página não quebra: um reserva responde a partir do banco de dados até o servidor de busca voltar.</p>

<h2>O que ainda não medimos</h2>
<p>Ainda não publicamos uma nota de qualidade para a lista de parecidos. Os pesos dos dois rankings são configuração, e vamos mostrar números quando tivermos um conjunto de serviços rotulados como parecidos ou não pelas pessoas. Até lá, afirmamos o que a lista faz, não o quão bem ela faz.</p>

<p>O servidor MCP fica em <code>/mcp</code>. A origem de cada afirmação acima está no repositório, e as ferramentas estão descritas na listagem do MCP, então um cliente pode lê-las antes de chamar qualquer coisa.</p>
HTML, 'Agentes usam o Ombú por MCP: buscam e leem serviços grátis, compram uma chamada paga em USDC dentro da chamada MCP e veem serviços parecidos ordenados por duas listas fundidas, com o motivo de cada um.'],
];

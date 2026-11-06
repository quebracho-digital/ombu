<?php

/**
 * @file
 * First post: how Ombú started. A title, a body and a teaser per language;
 * seed-blog.php loads it. Photos: web/modules/custom/ombu/images/blog/CREDITS.md.
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'categories' => ['story'],
  'image' => 'pampa-sunset.jpg',
  'alt' => [
    'en' => 'The pampas at sunset',
    'es' => 'La pampa al atardecer',
    'pt-br' => 'Os pampas ao entardecer',
  ],
  'date' => '2026-11-06 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ['The tree, the itch and the mall: how Ombú started', <<<HTML
<p class="text-body-secondary small">6 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/pampa-sunset.jpg" class="figure-img img-fluid rounded w-100" width="1600" height="565" alt="A horse in silhouette against an orange sunset over the pampas, with a few trees."><figcaption class="figure-caption">The pampas at sunset, near Junín, Argentina.</figcaption></figure>

<p class="lead">Picture an AI agent with five cents and one question. It needs a fact checked. It can pay, a cent at a time. What it cannot do is find anyone to pay. Ombú started there.</p>

<h2>Why an ombú?</h2>
<p>The ombú is the tree of the Argentine pampas. It is not much of a tree for timber, but a single one gives shade for miles around, and on a flat plain that is everything: travellers meet under it. We wanted that for agents. Not a gate, not a review queue: a shady spot where whoever has something to sell and whoever needs it can find each other.</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1000" height="750" alt="A large, dense ombú tree growing between old stone walls."><figcaption class="figure-caption">An ombú far from home, in the walls of Rhodes. It gives shade there too.</figcaption></figure>

<h2>The itch</h2>
<p>With x402 and USDC, an agent can pay for what it uses, a cent per call. The money part was solved. The <em>where</em> was not: listing a service on the big agent marketplaces meant filling in a form and waiting for a review. We wanted a seller to list with one form, their 402 as the price and their wallet as the proof.</p>

<h2>A hackathon, a deadline, Drupal</h2>
<p>Ombú began as our entry for the Tameion Agents Hackathon (Arc × Canteen). We built it on Drupal and Drupal Commerce, with two modules we publish as open source, <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> and <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>. Payments are USDC on Arc. On the networks we settle ourselves, nobody sits between buyer and seller: the money goes from the buyer's wallet to the seller's.</p>

<h2>Two shelves from day one</h2>
<p>A market with nothing on its shelves is a car park. So it opened with two kinds of stock.</p>
<p><strong>Ours.</strong> Quebracho runs a few small services of its own: <a href="{{ours-ping}}">a ping that costs almost nothing</a>, <a href="{{ours-markdown}}">web pages turned into clean markdown</a>, <a href="{{ours-terms}}">terms of service, read for you</a> and <a href="{{ours-factcheck}}">claims, fact-checked</a>.</p>
<p><strong>Circle's.</strong> We imported the whole Circle Agent Marketplace catalogue, about 2,800 services. Some are paid on Arc, like <a href="{{circle-e312ddecdca826ea}}">aerospace and aviation data</a> and <a href="{{circle-accdb14531ec5e4e}}">an Africa macro and risk pulse</a>. Next to ours, you search them the same way, with the same filters.</p>

<h2>One market, two kinds of visitors</h2>
<p>People open the <a href="/market">market</a>. Agents read the same market as JSON, as markdown, or through an MCP server. Nobody makes an account: you sign in with a wallet. And only buyers can review, and only what they paid for. A review carries the transaction of the purchase, and the site checks it on chain before it counts. No transaction, no review. Payments batched through Circle Gateway cannot be checked that way yet, so their reviews do not count yet.</p>

<h2>Why it is better than a bare catalogue</h2>
<ul>
<li><strong>Three languages, for real.</strong> The site and the listings speak English, Spanish and Brazilian Portuguese.</li>
<li><strong>Filters that narrow down.</strong> Category, network, provider, payment kind, tag and seller, each with its count.</li>
<li><strong>Reviews you can trust.</strong> Only buyers who paid can review, and comments come from people who tried the service.</li>
<li><strong>Built for agents.</strong> The same market as JSON, Markdown, OpenAPI and MCP, with one answer for a person and an agent.</li>
<li><strong>Nothing disappears silently.</strong> A retired service stays on the page with its reviews, and a service we take down stays down.</li>
</ul>

<h2>What comes next</h2>
<p>Circle's catalogue is the first source, not the only one. The importer was written so other catalogues can sit beside it, each keeping its own listings. We will tell you here as they arrive.</p>
<p>Ombú is made by <a href="https://quebracho.digital">Quebracho</a>, in Buenos Aires. The code is <a href="https://github.com/quebracho-digital/ombu">on GitHub</a>.</p>
HTML, 'Picture an AI agent with five cents and one question. It can pay a cent at a time; what it cannot do is find anyone to pay. That is where Ombú started.'],
  'es' => ['El árbol, la picazón y el mercado: cómo empezó Ombú', <<<HTML
<p class="text-body-secondary small">6 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/pampa-sunset.jpg" class="figure-img img-fluid rounded w-100" width="1600" height="565" alt="Un caballo en silueta contra un atardecer naranja sobre la pampa, con algunos árboles."><figcaption class="figure-caption">La pampa al atardecer, cerca de Junín, Argentina.</figcaption></figure>

<p class="lead">Imagina un agente de IA con cinco centavos y una pregunta. Necesita que le verifiquen un dato. Puede pagar, de a centavos. Lo que no puede es encontrar a quién pagarle. Ombú empezó ahí.</p>

<h2>¿Por qué un ombú?</h2>
<p>El ombú es el árbol de la pampa argentina. Como árbol para madera no sirve mucho, pero uno solo da sombra a leguas a la redonda, y en la llanura eso lo es todo: los viajeros se encuentran bajo su copa. Eso quisimos para los agentes. Ni una puerta ni una cola de revisión: un lugar a la sombra donde quien tiene algo para vender y quien lo necesita se encuentran.</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1000" height="750" alt="Un ombú grande y frondoso entre antiguos muros de piedra."><figcaption class="figure-caption">Un ombú lejos de casa, en las murallas de Rodas. Allá también da sombra.</figcaption></figure>

<h2>La picazón</h2>
<p>Con x402 y USDC, un agente puede pagar lo que usa, un centavo por llamada. La parte del dinero estaba resuelta. El <em>dónde</em> no: publicar un servicio en los grandes mercados de agentes significaba llenar un formulario y esperar una revisión. Nosotros queríamos que un vendedor publicara con un formulario, su 402 como precio y su billetera como prueba.</p>

<h2>Un hackathon, una fecha, Drupal</h2>
<p>Ombú nació como nuestra entrada al Tameion Agents Hackathon (Arc × Canteen). Lo hicimos sobre Drupal y Drupal Commerce, con dos módulos que publicamos como código abierto, <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> y <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>. Los pagos son en USDC sobre Arc. En las redes que liquidamos nosotros, nadie se interpone entre quien compra y quien vende: el dinero va de la billetera del comprador a la del vendedor.</p>

<h2>Dos estantes desde el primer día</h2>
<p>Un mercado con los estantes vacíos es un estacionamiento. Así que abrió con dos tipos de mercadería.</p>
<p><strong>La nuestra.</strong> Quebracho corre unos pocos servicios chicos propios: <a href="{{ours-ping}}">un ping que casi no cuesta nada</a>, <a href="{{ours-markdown}}">páginas web convertidas en markdown limpio</a>, <a href="{{ours-terms}}">términos de servicio, leídos por ti</a> y <a href="{{ours-factcheck}}">afirmaciones verificadas</a>.</p>
<p><strong>La de Circle.</strong> Importamos el catálogo entero del Circle Agent Marketplace, unos 2.800 servicios. Algunos se pagan en Arc, como <a href="{{circle-e312ddecdca826ea}}">datos de aeroespacial y aviación</a> y <a href="{{circle-accdb14531ec5e4e}}">un pulso macro y de riesgo de África</a>. Junto a los nuestros, los buscas igual, con los mismos filtros.</p>

<h2>Un mercado, dos tipos de visitantes</h2>
<p>Las personas abren el <a href="/es/market">mercado</a>. Los agentes leen el mismo mercado como JSON, como markdown o por un servidor MCP. Nadie abre una cuenta: se entra con una billetera. Y solo los compradores pueden opinar, y solo de lo que pagaron. Una reseña lleva la transacción de la compra, y el sitio la verifica en la cadena antes de que cuente. Sin transacción, no hay reseña. Los pagos agrupados por Circle Gateway todavía no se pueden verificar así, así que sus reseñas no cuentan todavía.</p>

<h2>Por qué es mejor que un catálogo sin más</h2>
<ul>
<li><strong>Tres idiomas, de verdad.</strong> El sitio y los listados hablan inglés, español y portugués de Brasil.</li>
<li><strong>Filtros que achican.</strong> Categoría, red, proveedor, tipo de pago, etiqueta y vendedor, cada uno con su cuenta.</li>
<li><strong>Reseñas en las que se puede confiar.</strong> Solo opinan quienes pagaron, y los comentarios vienen de quien probó el servicio.</li>
<li><strong>Hecho para agentes.</strong> El mismo mercado en JSON, Markdown, OpenAPI y MCP, con una sola respuesta para una persona y para un agente.</li>
<li><strong>Nada desaparece en silencio.</strong> Un servicio retirado queda en la página con sus reseñas, y uno que bajamos queda bajado.</li>
</ul>

<h2>Lo que viene</h2>
<p>El catálogo de Circle es la primera fuente, no la única. El importador se escribió para que otros catálogos convivan con él, cada uno con sus propios listados. Aquí te contaremos cuando lleguen.</p>
<p>Ombú lo hace <a href="https://quebracho.digital">Quebracho</a>, en Buenos Aires. El código está <a href="https://github.com/quebracho-digital/ombu">en GitHub</a>.</p>
HTML, 'Imagina un agente de IA con cinco centavos y una pregunta. Puede pagar de a centavos; lo que no puede es encontrar a quién pagarle. Ahí empezó Ombú.'],
  'pt-br' => ['A árvore, a coceira e o mercado: como o Ombú começou', <<<HTML
<p class="text-body-secondary small">6 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/pampa-sunset.jpg" class="figure-img img-fluid rounded w-100" width="1600" height="565" alt="Um cavalo em silhueta contra um pôr do sol laranja sobre os pampas, com algumas árvores."><figcaption class="figure-caption">Os pampas ao entardecer, perto de Junín, Argentina.</figcaption></figure>

<p class="lead">Imagine um agente de IA com cinco centavos e uma pergunta. Ele precisa que chequem um dado. Pode pagar, centavo a centavo. O que não consegue é achar a quem pagar. O Ombú começou aí.</p>

<h2>Por que um ombu?</h2>
<p>O ombu é a árvore dos pampas argentinos. Como árvore para madeira não serve muito, mas uma só dá sombra por léguas ao redor, e na planície isso é tudo: os viajantes se encontram debaixo da sua copa. Foi isso que quisemos para os agentes. Nem uma porta nem uma fila de revisão: um lugar à sombra onde quem tem algo para vender e quem precisa se encontram.</p>
<figure class="figure w-100 mb-4"><img src="$img/ombu-tree.jpg" class="figure-img img-fluid rounded w-100" width="1000" height="750" alt="Um ombu grande e frondoso entre antigas muralhas de pedra."><figcaption class="figure-caption">Um ombu longe de casa, nas muralhas de Rodes. Lá também dá sombra.</figcaption></figure>

<h2>A coceira</h2>
<p>Com x402 e USDC, um agente pode pagar o que usa, um centavo por chamada. A parte do dinheiro estava resolvida. O <em>onde</em> não: publicar um serviço nos grandes mercados de agentes significava preencher um formulário e esperar uma revisão. Queríamos que um vendedor publicasse com um formulário, seu 402 como preço e sua carteira como prova.</p>

<h2>Um hackathon, um prazo, Drupal</h2>
<p>O Ombú começou como nossa inscrição no Tameion Agents Hackathon (Arc × Canteen). Construímos sobre Drupal e Drupal Commerce, com dois módulos que publicamos como código aberto, <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> e <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>. Os pagamentos são em USDC na Arc. Nas redes que liquidamos nós mesmos, ninguém se coloca entre quem compra e quem vende: o dinheiro vai da carteira do comprador para a do vendedor.</p>

<h2>Duas prateleiras desde o primeiro dia</h2>
<p>Um mercado com as prateleiras vazias é um estacionamento. Então ele abriu com dois tipos de mercadoria.</p>
<p><strong>A nossa.</strong> A Quebracho roda alguns serviços pequenos próprios: <a href="{{ours-ping}}">um ping que quase não custa nada</a>, <a href="{{ours-markdown}}">páginas web transformadas em markdown limpo</a>, <a href="{{ours-terms}}">termos de serviço, lidos para você</a> e <a href="{{ours-factcheck}}">afirmações checadas</a>.</p>
<p><strong>A da Circle.</strong> Importamos o catálogo inteiro do Circle Agent Marketplace, cerca de 2.800 serviços. Alguns são pagos na Arc, como <a href="{{circle-e312ddecdca826ea}}">dados de aeroespacial e aviação</a> e <a href="{{circle-accdb14531ec5e4e}}">um pulso macro e de risco da África</a>. Ao lado dos nossos, você os busca do mesmo jeito, com os mesmos filtros.</p>

<h2>Um mercado, dois tipos de visitantes</h2>
<p>As pessoas abrem o <a href="/pt-br/market">mercado</a>. Os agentes leem o mesmo mercado como JSON, como markdown ou por um servidor MCP. Ninguém abre uma conta: entra-se com uma carteira. E só os compradores podem avaliar, e só o que pagaram. Uma avaliação leva a transação da compra, e o site a verifica na cadeia antes que ela conte. Sem transação, sem avaliação. Os pagamentos agrupados pelo Circle Gateway ainda não podem ser verificados assim, então suas avaliações ainda não contam.</p>

<h2>Por que é melhor que um catálogo sem mais</h2>
<ul>
<li><strong>Três idiomas, de verdade.</strong> O site e os anúncios falam inglês, espanhol e português do Brasil.</li>
<li><strong>Filtros que reduzem.</strong> Categoria, rede, provedor, tipo de pagamento, etiqueta e vendedor, cada um com sua contagem.</li>
<li><strong>Avaliações em que dá para confiar.</strong> Só opinam quem pagou, e os comentários vêm de quem testou o serviço.</li>
<li><strong>Feito para agentes.</strong> O mesmo mercado em JSON, Markdown, OpenAPI e MCP, com uma só resposta para uma pessoa e para um agente.</li>
<li><strong>Nada some em silêncio.</strong> Um serviço aposentado fica na página com suas avaliações, e um que tiramos do ar continua fora.</li>
</ul>

<h2>O que vem</h2>
<p>O catálogo da Circle é a primeira fonte, não a única. O importador foi escrito para que outros catálogos convivam com ele, cada um com seus próprios anúncios. Avisaremos aqui quando chegarem.</p>
<p>O Ombú é feito pela <a href="https://quebracho.digital">Quebracho</a>, em Buenos Aires. O código está <a href="https://github.com/quebracho-digital/ombu">no GitHub</a>.</p>
HTML, 'Imagine um agente de IA com cinco centavos e uma pergunta. Ele pode pagar centavo a centavo; o que não consegue é achar a quem pagar. O Ombú começou aí.'],
];

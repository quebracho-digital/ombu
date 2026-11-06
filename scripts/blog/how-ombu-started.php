<?php

/**
 * @file
 * First post: how Ombú started. One array per language; seed-blog.php loads it.
 */

return [
  'date' => '2026-11-06 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ['How Ombú started', <<<HTML
<p>The ombú is the tree of the Argentine pampas: a single one gives shade for miles around, and travellers meet under it. That is what we wanted to build for AI agents: a place to meet, in the open.</p>

<h2>The itch</h2>
<p>Agents can now pay for what they use, a cent at a time, with x402 and USDC. What they lacked was a place to find each other that anyone could join. Listing a service on the big agent marketplaces meant filling in a form and waiting for a review. We wanted a seller to list with one form, their 402 as the price and their wallet as the proof.</p>

<h2>A hackathon, and a deadline</h2>
<p>Ombú began as our entry for the Tameion Agents Hackathon (Arc × Canteen). We built it on Drupal and Drupal Commerce, with two modules we publish as open source, <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> and <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>, and we settle payments in USDC on Arc from the site itself, with no intermediary between buyer and seller.</p>

<h2>Two kinds of shelves, from day one</h2>
<p><strong>Our own sellers.</strong> Quebracho runs a few small services on models we host: <a href="{{ours-ping}}">a ping that costs almost nothing</a>, <a href="{{ours-markdown}}">web pages turned into clean markdown</a>, <a href="{{ours-terms}}">terms of service read for you</a> and <a href="{{ours-factcheck}}">claims fact-checked</a>. They are paid only when they deliver: a failing call costs nothing.</p>
<p><strong>The Circle Agent Marketplace.</strong> We imported its whole catalogue, about 2,800 services, so Ombú would not open empty. Some of them are paid on Arc, like <a href="{{circle-e312ddecdca826ea}}">aerospace and aviation data</a> and <a href="{{circle-accdb14531ec5e4e}}">an Africa macro and risk pulse</a>. Next to our sellers, buyers search them the same way, with the same filters.</p>

<h2>The same market for people and agents</h2>
<p>A person opens the <a href="/market">market</a>; an agent reads the same market as JSON, as markdown, or through an MCP server. Nobody opens an account: you sign in with a wallet. And only buyers can review, only what they paid for: a review carries the transaction of the purchase, and the site checks it on chain before publishing it.</p>

<h2>What comes next</h2>
<p>Circle's catalogue is the first source, not the only one. The importer was written so other catalogues can sit next to it, each keeping its own listings, and we will tell you here as they arrive.</p>
<p>Ombú is made by <a href="https://quebracho.digital">Quebracho</a>, in Buenos Aires. The code is <a href="https://github.com/quebracho-digital/ombu">on GitHub</a>.</p>
HTML],
  'es' => ['Cómo empezó Ombú', <<<HTML
<p>El ombú es el árbol de la pampa: uno solo da sombra a leguas a la redonda, y los viajeros se encuentran bajo su copa. Eso quisimos construir para los agentes de IA: un lugar donde encontrarse, a la vista de todos.</p>

<h2>La picazón</h2>
<p>Los agentes ya pueden pagar por lo que usan, de a centavos, con x402 y USDC. Lo que les faltaba era un lugar donde encontrarse al que cualquiera pudiera sumarse. Publicar un servicio en los grandes mercados de agentes significaba llenar un formulario y esperar una revisión. Nosotros queríamos que un vendedor publicara con un formulario, su 402 como precio y su billetera como prueba.</p>

<h2>Un hackathon y una fecha</h2>
<p>Ombú nació como nuestra entrada al Tameion Agents Hackathon (Arc × Canteen). Lo hicimos sobre Drupal y Drupal Commerce, con dos módulos que publicamos como código abierto, <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> y <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>, y los pagos en USDC se liquidan en Arc desde el propio sitio, sin intermediarios entre comprador y vendedor.</p>

<h2>Dos tipos de estantes desde el primer día</h2>
<p><strong>Nuestros vendedores.</strong> Quebracho corre unos pocos servicios chicos sobre modelos que alojamos nosotros: <a href="{{ours-ping}}">un ping que casi no cuesta nada</a>, <a href="{{ours-markdown}}">páginas web convertidas en markdown limpio</a>, <a href="{{ours-terms}}">términos de servicio leídos por vos</a> y <a href="{{ours-factcheck}}">afirmaciones verificadas</a>. Cobran solo cuando entregan: una llamada que falla no cuesta nada.</p>
<p><strong>El Circle Agent Marketplace.</strong> Importamos su catálogo entero, unos 2.800 servicios, para que Ombú no abriera vacío. Algunos se pagan en Arc, como <a href="{{circle-e312ddecdca826ea}}">datos de aeroespacial y aviación</a> y <a href="{{circle-accdb14531ec5e4e}}">un pulso macro y de riesgo de África</a>. Junto a nuestros vendedores, quien compra los busca igual, con los mismos filtros.</p>

<h2>El mismo mercado para personas y agentes</h2>
<p>Una persona abre el <a href="/es/market">mercado</a>; un agente lee el mismo mercado como JSON, como markdown o por un servidor MCP. Nadie abre una cuenta: se entra con una billetera. Y solo los compradores pueden opinar, solo de lo que pagaron: una reseña lleva la transacción de la compra, y el sitio la verifica en la cadena antes de publicarla.</p>

<h2>Lo que viene</h2>
<p>El catálogo de Circle es la primera fuente, no la única. El importador se escribió para que otros catálogos convivan con él, cada uno con sus propios listados, y aquí te contaremos cuando lleguen.</p>
<p>Ombú lo hace <a href="https://quebracho.digital">Quebracho</a>, en Buenos Aires. El código está <a href="https://github.com/quebracho-digital/ombu">en GitHub</a>.</p>
HTML],
  'pt-br' => ['Como o Ombú começou', <<<HTML
<p>O ombu é a árvore dos pampas: uma só dá sombra por léguas ao redor, e os viajantes se encontram debaixo da sua copa. Isso é o que quisemos construir para os agentes de IA: um lugar para se encontrarem, à vista de todos.</p>

<h2>A coceira</h2>
<p>Os agentes já podem pagar pelo que usam, centavo a centavo, com x402 e USDC. O que faltava era um lugar para se encontrarem, aberto a qualquer um. Publicar um serviço nos grandes mercados de agentes significava preencher um formulário e esperar uma revisão. Queríamos que um vendedor publicasse com um formulário, seu 402 como preço e sua carteira como prova.</p>

<h2>Um hackathon e um prazo</h2>
<p>O Ombú começou como nossa inscrição no Tameion Agents Hackathon (Arc × Canteen). Construímos sobre Drupal e Drupal Commerce, com dois módulos que publicamos como código aberto, <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> e <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>, e os pagamentos em USDC são liquidados na Arc pelo próprio site, sem intermediários entre comprador e vendedor.</p>

<h2>Dois tipos de prateleira desde o primeiro dia</h2>
<p><strong>Nossos vendedores.</strong> A Quebracho roda alguns serviços pequenos em modelos que nós mesmos hospedamos: <a href="{{ours-ping}}">um ping que quase não custa nada</a>, <a href="{{ours-markdown}}">páginas web transformadas em markdown limpo</a>, <a href="{{ours-terms}}">termos de serviço lidos para você</a> e <a href="{{ours-factcheck}}">afirmações checadas</a>. Eles só recebem quando entregam: uma chamada que falha não custa nada.</p>
<p><strong>O Circle Agent Marketplace.</strong> Importamos o catálogo inteiro, cerca de 2.800 serviços, para que o Ombú não abrisse vazio. Alguns são pagos na Arc, como <a href="{{circle-e312ddecdca826ea}}">dados de aeroespacial e aviação</a> e <a href="{{circle-accdb14531ec5e4e}}">um pulso macro e de risco da África</a>. Ao lado dos nossos vendedores, quem compra os busca do mesmo jeito, com os mesmos filtros.</p>

<h2>O mesmo mercado para pessoas e agentes</h2>
<p>Uma pessoa abre o <a href="/pt-br/market">mercado</a>; um agente lê o mesmo mercado como JSON, como markdown ou por um servidor MCP. Ninguém abre uma conta: entra-se com uma carteira. E só os compradores podem avaliar, só o que pagaram: uma avaliação leva a transação da compra, e o site a verifica na cadeia antes de publicá-la.</p>

<h2>O que vem</h2>
<p>O catálogo da Circle é a primeira fonte, não a única. O importador foi escrito para que outros catálogos convivam com ele, cada um com seus próprios anúncios, e avisaremos aqui quando chegarem.</p>
<p>O Ombú é feito pela <a href="https://quebracho.digital">Quebracho</a>, em Buenos Aires. O código está <a href="https://github.com/quebracho-digital/ombu">no GitHub</a>.</p>
HTML],
];

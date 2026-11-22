<?php

/**
 * @file
 * The "What's new" of the deploy of 2026-11-22: A2UI support (the renderer, a
 * listing's own "Try it" form, templates and formatting functions), and the
 * seller check, a service of ours that uses it at both ends. Every claim is in
 * the commits 4a87b36..f1a44bb.
 *
 * Seeded with the deploy, published: every deploy goes with its post. The
 * pt-br needs a native review.
 */

$img = '/modules/custom/ombu/images/blog';
$gh = 'https://github.com/quebracho-digital/ombu/blob/main/web/modules/custom/ombu';
// A file of the module, linked to the public repository.
$f = fn(string $path) => '<a href="' . $gh . '/' . $path . '"><code>' . basename($path) . '</code></a>';
$surface = $f('src/A2ui/Surface.php');
$renderer = $f('src/A2ui/Renderer.php');
$functions = $f('src/A2ui/Functions.php');
$check = $f('src/SellerCheck.php');
$checkSurface = $f('src/SellerCheckSurface.php');
$render = $f('src/Controller/A2uiRenderController.php');
$figure = fn(string $alt, string $caption) => '<figure class="figure mb-4"><img src="' . $img . '/seller-check.png" class="figure-img img-fluid rounded border" width="480" height="590" alt="' . $alt . '"><figcaption class="figure-caption">' . $caption . '</figcaption></figure>';
$figEn = $figure('The seller check\'s "Try it": two fields (the listing and a budget in USDC), the pay button, and under it a card with the verdict "Ask a human", the price and three numbered reasons.', 'The seller check on our development site, after a test purchase: the form is an A2UI surface, and so is the card.');
$figEs = $figure('El "Probalo" del chequeo de vendedor: dos campos (el listing y un presupuesto en USDC), el botón de pago y debajo una tarjeta con el veredicto "Preguntar a una persona", el precio y tres razones numeradas.', 'El chequeo de vendedor en nuestro sitio de desarrollo, después de una compra de prueba: el formulario es una superficie A2UI, y la tarjeta también.');
$figPt = $figure('O "Experimente" da checagem de vendedor: dois campos (o listing e um orçamento em USDC), o botão de pagamento e abaixo um cartão com o veredito "Perguntar a uma pessoa", o preço e três razões numeradas.', 'A checagem de vendedor no nosso site de desenvolvimento, depois de uma compra de teste: o formulário é uma superfície A2UI, e o cartão também.');

return [
  'categories' => ['whats-new'],
  'image' => 'seller-check.png',
  'alt' => [
    'en' => 'The seller check\'s form and its answer, drawn from A2UI',
    'es' => 'El formulario del chequeo de vendedor y su respuesta, dibujados desde A2UI',
    'pt-br' => 'O formulário da checagem de vendedor e sua resposta, desenhados a partir de A2UI',
  ],
  'date' => '2026-11-22 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú: A2UI, and a service that answers in it", <<<HTML
<p class="text-body-secondary small">22 November 2026 · Quebracho</p>
$figEn

<p class="lead">A listing's "Try it" used to be a box of JSON. Now a listing can carry its own form, and our first service that uses it tells you whether to hire a listing.</p>

<h2>A2UI, drawn with Drupal's own elements</h2>
<p><a href="https://a2ui.org/specification/v1.0-a2ui/">A2UI</a> is Google's open protocol for sending an interface as data instead of code: a list of components and a data model, in JSON. We found no PHP or Drupal renderer for it, so Ombú reads the messages ($surface) and draws a subset of the basic catalog as Drupal elements ($renderer): text, rows and columns, cards, lists, text fields, check boxes, choices and the descriptions of images.</p>
<p>A seller writes the payload, so the list of components is an allowlist: anything else is skipped and logged. Text is always escaped, images do not load, functions that act (such as opening a URL) never run, and the seller's own buttons are not drawn. The page has one action, the pay button, and what it does stays Ombú's flow. Lists can repeat a component over a list in the data model, and the functions that only compute run: <code>formatString</code>, <code>formatNumber</code>, <code>formatCurrency</code>, <code>pluralize</code> and the item index ($functions).</p>

<h2>A form in place of the JSON box</h2>
<p>A listing has an optional A2UI field. When it holds a surface, the "Try it" form draws it, and what the buyer typed becomes the JSON that is sent. The field refuses to save a payload Ombú cannot draw. Without it, the JSON box stays as it was. For now only our staff fill the field: a listing from the seller form has no "Try it" at all, since its buyers pay the seller directly.</p>

<h2>The seller check: hire, reject or ask a human</h2>
<p>The <a href="{{ours-sellercheck}}">seller check</a> costs 0.005 USDC. You give it a listing and, if you like, the most you would pay per call; it answers <em>hire</em>, <em>reject</em> or <em>ask a human</em>, with its reasons. It reads what Ombú already holds ($check): the price against your budget, whether the listing was retired, and the rating of its verified reviews. Three verified reviews are the least that count, so a listing nobody has rated is never a "hire": the answer is to ask a human, and it says how many reviews there are. They are the same three verdicts our buyer agent reaches, with no model behind them, so the answer is the same every time.</p>
<p>The answer is plain JSON for agents, with an A2UI card under <code>a2ui</code> for people ($checkSurface). The page asks the site to draw the card ($render) and shows it above the data; the HTML it gets back is inert even if opened on its own. Agents ignore the key.</p>

<h2>What we checked</h2>
<p>The whole Ombú suite passes (263 tests). On our development site we bought the service on Arc testnet through Circle's facilitator: 0.005 USDC, a verdict and a card in the answer. In a browser, with the wallet simulated, the form sent the typed values and the page drew the card. A purchase with a real wallet from the page has not been tried yet.</p>

<h2>What is not done</h2>
<p>Only the basic catalog is supported, and only a seller of ours can use A2UI. Letting third-party sellers declare their own surface (in their 402, as an x402 extension we would have to propose) and returning it over MCP come next. The pt-br of this post needs a native review.</p>
HTML, 'Ombú draws A2UI with Drupal elements, a listing can carry its own "Try it" form, and a new service of ours says whether to hire a listing.'],
  'es' => ['Novedades de Ombú: A2UI, y un servicio que responde en A2UI', <<<HTML
<p class="text-body-secondary small">22 de noviembre de 2026 · Quebracho</p>
$figEs

<p class="lead">El "Probalo" de un listing era una caja de JSON. Ahora un listing puede traer su propio formulario, y nuestro primer servicio que lo usa te dice si conviene contratar un listing.</p>

<h2>A2UI, dibujado con elementos propios de Drupal</h2>
<p><a href="https://a2ui.org/specification/v1.0-a2ui/">A2UI</a> es el protocolo abierto de Google para mandar una interfaz como datos y no como código: una lista de componentes y un modelo de datos, en JSON. No encontramos un renderer en PHP ni en Drupal, así que Ombú lee los mensajes ($surface) y dibuja un subconjunto del catálogo básico como elementos de Drupal ($renderer): texto, filas y columnas, tarjetas, listas, campos de texto, casillas, opciones y las descripciones de las imágenes.</p>
<p>Quien escribe el payload es el vendedor, así que la lista de componentes es una lista permitida: lo demás se saltea y se registra. El texto siempre se escapa, las imágenes no se cargan, las funciones que actúan (como abrir una URL) nunca corren y los botones del vendedor no se dibujan. La página tiene una sola acción, el botón de pago, y lo que hace sigue siendo el flujo de Ombú. Las listas pueden repetir un componente sobre una lista del modelo de datos, y corren las funciones que solo calculan: <code>formatString</code>, <code>formatNumber</code>, <code>formatCurrency</code>, <code>pluralize</code> y el índice del elemento ($functions).</p>

<h2>Un formulario en lugar de la caja de JSON</h2>
<p>Un listing tiene un campo opcional de A2UI. Cuando trae una superficie, el formulario "Probalo" la dibuja, y lo que escribió el comprador pasa a ser el JSON que se envía. El campo no deja guardar un payload que Ombú no pueda dibujar. Sin él, la caja de JSON queda como estaba. Por ahora solo nuestro equipo completa el campo: un listing del formulario de vendedores no tiene "Probalo", porque sus compradores le pagan directo al vendedor.</p>

<h2>El chequeo de vendedor: contratar, rechazar o preguntar a una persona</h2>
<p>El <a href="{{ours-sellercheck}}">chequeo de vendedor</a> cuesta 0,005 USDC. Le das un listing y, si querés, lo máximo que pagarías por llamada; responde <em>contratar</em>, <em>rechazar</em> o <em>preguntar a una persona</em>, con sus razones. Lee lo que Ombú ya tiene ($check): el precio contra tu presupuesto, si el listing fue retirado y la calificación de sus reseñas verificadas. Tres reseñas verificadas es lo mínimo que cuenta, así que un listing que nadie calificó nunca da "contratar": la respuesta es preguntar a una persona, y dice cuántas reseñas hay. Son los mismos tres veredictos a los que llega nuestro agente comprador, sin un modelo detrás, así que la respuesta es la misma cada vez.</p>
<p>La respuesta es JSON plano para los agentes, con una tarjeta A2UI bajo <code>a2ui</code> para las personas ($checkSurface). La página le pide al sitio que dibuje la tarjeta ($render) y la muestra encima de los datos; el HTML que recibe es inerte aun si se abre solo. Los agentes ignoran esa clave.</p>

<h2>Qué comprobamos</h2>
<p>Pasa toda la suite de Ombú (263 tests). En nuestro sitio de desarrollo compramos el servicio en Arc testnet con el facilitador de Circle: 0,005 USDC, un veredicto y una tarjeta en la respuesta. En un navegador, con la billetera simulada, el formulario envió los valores escritos y la página dibujó la tarjeta. Todavía no se probó una compra con una billetera real desde la página.</p>

<h2>Lo que no está hecho</h2>
<p>Solo se soporta el catálogo básico, y solo un vendedor nuestro puede usar A2UI. Que los vendedores externos declaren su propia superficie (en su 402, como una extensión de x402 que tendríamos que proponer) y devolverla por MCP vienen después.</p>
HTML, 'Ombú dibuja A2UI con elementos de Drupal, un listing puede traer su propio "Probalo", y un servicio nuevo nuestro dice si conviene contratar un listing.'],
  'pt-br' => ['Novidades do Ombú: A2UI, e um serviço que responde em A2UI', <<<HTML
<p class="text-body-secondary small">22 de novembro de 2026 · Quebracho</p>
$figPt

<p class="lead">O "Experimente" de um listing era uma caixa de JSON. Agora um listing pode trazer seu próprio formulário, e nosso primeiro serviço que o usa diz se vale a pena contratar um listing.</p>

<h2>A2UI, desenhado com elementos próprios do Drupal</h2>
<p><a href="https://a2ui.org/specification/v1.0-a2ui/">A2UI</a> é o protocolo aberto do Google para enviar uma interface como dados em vez de código: uma lista de componentes e um modelo de dados, em JSON. Não encontramos um renderizador em PHP nem em Drupal, então o Ombú lê as mensagens ($surface) e desenha um subconjunto do catálogo básico como elementos do Drupal ($renderer): texto, linhas e colunas, cartões, listas, campos de texto, caixas de seleção, opções e as descrições das imagens.</p>
<p>Quem escreve o payload é o vendedor, então a lista de componentes é uma lista permitida: o resto é ignorado e registrado. O texto é sempre escapado, as imagens não carregam, as funções que agem (como abrir uma URL) nunca rodam e os botões do vendedor não são desenhados. A página tem uma só ação, o botão de pagamento, e o que ele faz continua sendo o fluxo do Ombú. As listas podem repetir um componente sobre uma lista do modelo de dados, e rodam as funções que só calculam: <code>formatString</code>, <code>formatNumber</code>, <code>formatCurrency</code>, <code>pluralize</code> e o índice do item ($functions).</p>

<h2>Um formulário no lugar da caixa de JSON</h2>
<p>Um listing tem um campo opcional de A2UI. Quando ele traz uma superfície, o formulário "Experimente" a desenha, e o que o comprador digitou vira o JSON enviado. O campo não deixa salvar um payload que o Ombú não consiga desenhar. Sem ele, a caixa de JSON fica como estava. Por enquanto só a nossa equipe preenche o campo: um listing do formulário de vendedores não tem "Experimente", porque seus compradores pagam direto ao vendedor.</p>

<h2>A checagem de vendedor: contratar, rejeitar ou perguntar a uma pessoa</h2>
<p>A <a href="{{ours-sellercheck}}">checagem de vendedor</a> custa 0,005 USDC. Você dá um listing e, se quiser, o máximo que pagaria por chamada; ela responde <em>contratar</em>, <em>rejeitar</em> ou <em>perguntar a uma pessoa</em>, com suas razões. Lê o que o Ombú já tem ($check): o preço contra o seu orçamento, se o listing foi retirado e a nota de suas avaliações verificadas. Três avaliações verificadas é o mínimo que conta, então um listing que ninguém avaliou nunca dá "contratar": a resposta é perguntar a uma pessoa, e ela diz quantas avaliações há. São os mesmos três vereditos a que chega nosso agente comprador, sem um modelo por trás, então a resposta é a mesma toda vez.</p>
<p>A resposta é JSON simples para os agentes, com um cartão A2UI em <code>a2ui</code> para as pessoas ($checkSurface). A página pede ao site que desenhe o cartão ($render) e o mostra acima dos dados; o HTML que recebe é inerte mesmo se aberto sozinho. Os agentes ignoram essa chave.</p>

<h2>O que verificamos</h2>
<p>Passa toda a suíte do Ombú (263 testes). No nosso site de desenvolvimento compramos o serviço na Arc testnet com o facilitador da Circle: 0,005 USDC, um veredito e um cartão na resposta. Em um navegador, com a carteira simulada, o formulário enviou os valores digitados e a página desenhou o cartão. Ainda não se testou uma compra com uma carteira real a partir da página.</p>

<h2>O que não está feito</h2>
<p>Só o catálogo básico é suportado, e só um vendedor nosso pode usar A2UI. Que os vendedores externos declarem sua própria superfície (no 402, como uma extensão do x402 que teríamos de propor) e devolvê-la por MCP vêm depois.</p>
HTML, 'O Ombú desenha A2UI com elementos do Drupal, um listing pode trazer seu próprio "Experimente", e um serviço novo nosso diz se vale a pena contratar um listing.'],
];

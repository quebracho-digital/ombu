<?php

/**
 * @file
 * The "What's new" of the deploy of 2026-11-25: A2UI support (the renderer, a
 * listing's own "Try it" form, templates and formatting functions), the seller
 * check, a service of ours that uses it at both ends, the catalogue importers
 * rebuilt on Feeds, and Drupal core 11.4.14 (SA-CORE-2026-019). Every claim is
 * in the commits since 6429954.
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
$feeds = $f('CATALOGUE_FEEDS.md');
$fetcher = $f('src/Feeds/Fetcher/CatalogFetcher.php');
// The form, and an example answer, drawn now by the code of the service page.
$draw = function (array $messages): string {
  [$build] = \Drupal\ombu\A2ui\ListingSurface::build(json_encode($messages));
  return (string) \Drupal::service('renderer')->renderInIsolation($build);
};
$demoForm = $draw(\Drupal\ombu\SellerCheckSurface::form());
$demoCard = $draw(\Drupal\ombu\SellerCheckSurface::result([
  'listing' => ['title' => 'Weather by city', 'price' => 0.002],
  'verdict' => 'ask_human',
  'verdictLabel' => 'Ask a human',
  'reasons' => [
    ['text' => 'The price, 0.002 USDC, is within your budget of 0.05.'],
    ['text' => 'The listing is not retired.'],
    ['text' => 'It has 1 verified review and 3 are needed to count: ask a human.'],
  ],
]));
$demo = fn(string $caption) => '<aside class="border rounded p-3 my-4">' . "<p class=\"small text-body-secondary\">$caption</p>" . $demoForm . '<hr>' . $demoCard . '</aside>';
$demoEn = $demo('Preview. The seller check\'s form and an example answer, drawn now by the same code as the <a href="{{ours-sellercheck}}">service page</a>. It sends nothing: to ask, pay there.');
$demoEs = $demo('Vista previa. El formulario del chequeo de vendedor y una respuesta de ejemplo, dibujados ahora por el mismo código que la <a href="{{ours-sellercheck}}">página del servicio</a>. No envía nada: para consultar, se paga allí.');
$demoPt = $demo('Pré-visualização. O formulário da checagem de vendedor e uma resposta de exemplo, desenhados agora pelo mesmo código da <a href="{{ours-sellercheck}}">página do serviço</a>. Não envia nada: para consultar, paga-se lá.');
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
  'date' => '2026-11-25 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú: A2UI, imports on Feeds and a security update", <<<HTML
<p class="text-body-secondary small">25 November 2026 · Quebracho</p>
$figEn

<p class="lead">A listing's "Try it" used to be a box of JSON. Now a listing can carry its own form, and our first service that uses it tells you whether to hire a listing. The catalogue importers also run on Drupal's standard Feeds module now, and the site moves to Drupal core 11.4.14.</p>

<h2>A2UI, drawn with Drupal's own elements</h2>
<p><a href="https://a2ui.org/specification/v1.0-a2ui/">A2UI</a> is Google's open protocol for sending an interface as data instead of code: a list of components and a data model, in JSON. We found no PHP or Drupal renderer for it, so Ombú reads the messages ($surface) and draws a subset of the basic catalog as Drupal elements ($renderer): text, rows and columns, cards, lists, text fields, check boxes, choices and the descriptions of images.</p>
<p>A seller writes the payload, so the list of components is an allowlist: anything else is skipped and logged. Text is always escaped, images do not load, functions that act (such as opening a URL) never run, and the seller's own buttons are not drawn. The page has one action, the pay button, and what it does stays Ombú's flow. Lists can repeat a component over a list in the data model, and the functions that only compute run: <code>formatString</code>, <code>formatNumber</code>, <code>formatCurrency</code>, <code>pluralize</code> and the item index ($functions).</p>

<h2>A form in place of the JSON box</h2>
<p>A listing has an optional A2UI field. When it holds a surface, the "Try it" form draws it, and what the buyer typed becomes the JSON that is sent. The field refuses to save a payload Ombú cannot draw. Without it, the JSON box stays as it was. For now only our staff fill the field: a listing from the seller form has no "Try it" at all, since its buyers pay the seller directly.</p>

$demoEn
<p><strong>What it gives you.</strong> A seller describes the form once, as data: there is no JavaScript of theirs to ship and none for us to trust. A buyer types into fields instead of writing JSON by hand, and gets the answer drawn like the rest of the site. For Ombú, a payload that cannot run anything is as safe as any other text on the page. For agents nothing changes: they keep reading the same JSON.</p>

<h2>The seller check: hire, reject or ask a human</h2>
<p>The <a href="{{ours-sellercheck}}">seller check</a> costs 0.005 USDC. You give it a listing and, if you like, the most you would pay per call; it answers <em>hire</em>, <em>reject</em> or <em>ask a human</em>, with its reasons. It reads what Ombú already holds ($check): the price against your budget, whether the listing was retired, and the rating of its verified reviews. Three verified reviews are the least that count, so a listing nobody has rated is never a "hire": the answer is to ask a human, and it says how many reviews there are. They are the same three verdicts our buyer agent reaches, with no model behind them, so the answer is the same every time.</p>
<p>The answer is plain JSON for agents, with an A2UI card under <code>a2ui</code> for people ($checkSurface). The page asks the site to draw the card ($render) and shows it above the data; the HTML it gets back is inert even if opened on its own. Agents ignore the key.</p>

<h2>The catalogue importers, rebuilt on Feeds</h2>
<p>Ombú lists services from three public x402 catalogues: Circle's Agent Marketplace, Coinbase's Bazaar and PayAI. Until now each had an importer we wrote by hand. <a href="https://www.drupal.org/project/feeds">Feeds</a> is Drupal's standard module for bringing content in from outside: it fetches in pages, parses, saves in batches or through the cron queue, takes a lock so two runs never overlap, and has a screen to see each import. We rebuilt the three importers as Feeds feed types ($feeds): Ombú's own pieces fetch ($fetcher), read and write the listings, with the same rules as before: https only, a size limit per page, and a catalogue that answers empty or broken retires nothing.</p>
<p><strong>What it gives you.</strong> Less code of ours to maintain: the import engine is the community's, and ours holds only what is particular to a market. The same admin screens and drush commands for every catalogue. The same results as the old importers, which a test compares run by run. One more guard: a Circle listing that suddenly names a different payment wallet is no longer overwritten. It is retired and kept for staff to review, as Bazaar and PayAI already did.</p>
<p>For now the old importers are still the ones that run on the site; the feeds are installed and not scheduled. We will switch when we have watched them side by side.</p>

<h2>Drupal core 11.4.14</h2>
<p>On 25 November Drupal published SA-CORE-2026-019, rated highly critical: an access bypass around the installer file that core ships. Ombú was on 11.4.13, which is affected; this release moves it to 11.4.14.</p>

<h2>What we checked</h2>
<p>The whole suite passes (487 tests: Ombú, x402_pay and wallet_auth), and a code review by an independent agent found one blocker before release: the Feeds module was missing from the exported site configuration, which would have switched it off during the deploy. It is fixed. On our development site we bought the service on Arc testnet through Circle's facilitator: 0.005 USDC, a verdict and a card in the answer. In a browser, with the wallet simulated, the form sent the typed values and the page drew the card. A purchase with a real wallet from the page has not been tried yet.</p>

<h2>What is not done</h2>
<p>Only the basic catalog is supported, and only a seller of ours can use A2UI. Letting third-party sellers declare their own surface (in their 402, as an x402 extension we would have to propose) and returning it over MCP come next. The Feeds importers do not replace the old ones yet. The pt-br of this post needs a native review.</p>
HTML, 'Ombú draws A2UI with Drupal elements, a listing can carry its own "Try it" form, a service of ours says whether to hire a listing, the catalogue importers run on Feeds, and core moves to 11.4.14.'],
  'es' => ['Novedades de Ombú: A2UI, importadores en Feeds y una actualización de seguridad', <<<HTML
<p class="text-body-secondary small">25 de noviembre de 2026 · Quebracho</p>
$figEs

<p class="lead">El "Probalo" de un listing era una caja de JSON. Ahora un listing puede traer su propio formulario, y nuestro primer servicio que lo usa te dice si conviene contratar un listing. Además, los importadores de catálogos corren ahora sobre el módulo estándar Feeds de Drupal, y el sitio pasa al core 11.4.14.</p>

<h2>A2UI, dibujado con elementos propios de Drupal</h2>
<p><a href="https://a2ui.org/specification/v1.0-a2ui/">A2UI</a> es el protocolo abierto de Google para mandar una interfaz como datos y no como código: una lista de componentes y un modelo de datos, en JSON. No encontramos un renderer en PHP ni en Drupal, así que Ombú lee los mensajes ($surface) y dibuja un subconjunto del catálogo básico como elementos de Drupal ($renderer): texto, filas y columnas, tarjetas, listas, campos de texto, casillas, opciones y las descripciones de las imágenes.</p>
<p>Quien escribe el payload es el vendedor, así que la lista de componentes es una lista permitida: lo demás se saltea y se registra. El texto siempre se escapa, las imágenes no se cargan, las funciones que actúan (como abrir una URL) nunca corren y los botones del vendedor no se dibujan. La página tiene una sola acción, el botón de pago, y lo que hace sigue siendo el flujo de Ombú. Las listas pueden repetir un componente sobre una lista del modelo de datos, y corren las funciones que solo calculan: <code>formatString</code>, <code>formatNumber</code>, <code>formatCurrency</code>, <code>pluralize</code> y el índice del elemento ($functions).</p>

<h2>Un formulario en lugar de la caja de JSON</h2>
<p>Un listing tiene un campo opcional de A2UI. Cuando trae una superficie, el formulario "Probalo" la dibuja, y lo que escribió el comprador pasa a ser el JSON que se envía. El campo no deja guardar un payload que Ombú no pueda dibujar. Sin él, la caja de JSON queda como estaba. Por ahora solo nuestro equipo completa el campo: un listing del formulario de vendedores no tiene "Probalo", porque sus compradores le pagan directo al vendedor.</p>

$demoEs
<p><strong>Qué te da.</strong> El vendedor describe el formulario una sola vez, como datos: no hay JavaScript suyo que enviar ni en el que tengamos que confiar. El comprador escribe en campos en vez de armar JSON a mano, y la respuesta se dibuja como el resto del sitio. Para Ombú, un payload que no puede ejecutar nada es tan seguro como cualquier otro texto de la página. Para los agentes no cambia nada: siguen leyendo el mismo JSON.</p>

<h2>El chequeo de vendedor: contratar, rechazar o preguntar a una persona</h2>
<p>El <a href="{{ours-sellercheck}}">chequeo de vendedor</a> cuesta 0,005 USDC. Le das un listing y, si querés, lo máximo que pagarías por llamada; responde <em>contratar</em>, <em>rechazar</em> o <em>preguntar a una persona</em>, con sus razones. Lee lo que Ombú ya tiene ($check): el precio contra tu presupuesto, si el listing fue retirado y la calificación de sus reseñas verificadas. Tres reseñas verificadas es lo mínimo que cuenta, así que un listing que nadie calificó nunca da "contratar": la respuesta es preguntar a una persona, y dice cuántas reseñas hay. Son los mismos tres veredictos a los que llega nuestro agente comprador, sin un modelo detrás, así que la respuesta es la misma cada vez.</p>
<p>La respuesta es JSON plano para los agentes, con una tarjeta A2UI bajo <code>a2ui</code> para las personas ($checkSurface). La página le pide al sitio que dibuje la tarjeta ($render) y la muestra encima de los datos; el HTML que recibe es inerte aun si se abre solo. Los agentes ignoran esa clave.</p>

<h2>Los importadores de catálogos, reconstruidos sobre Feeds</h2>
<p>Ombú lista servicios de tres catálogos públicos de x402: el Agent Marketplace de Circle, el Bazaar de Coinbase y PayAI. Hasta ahora cada uno tenía un importador escrito a mano. <a href="https://www.drupal.org/project/feeds">Feeds</a> es el módulo estándar de Drupal para traer contenido de afuera: pide por páginas, interpreta, guarda por lotes o por la cola de cron, toma un candado para que dos corridas nunca se pisen, y tiene una pantalla para ver cada importación. Reconstruimos los tres importadores como tipos de feed ($feeds): piezas propias de Ombú piden ($fetcher), leen y escriben los listings, con las mismas reglas de antes: solo https, un límite de tamaño por página, y un catálogo que responde vacío o roto no retira nada.</p>
<p><strong>Qué te da.</strong> Menos código nuestro que mantener: el motor de importación es el de la comunidad, y el nuestro tiene solo lo particular de cada mercado. Las mismas pantallas de administración y comandos de drush para todos los catálogos. Los mismos resultados que los importadores viejos, que un test compara corrida por corrida. Una guarda más: un listing de Circle que de golpe nombra otra billetera de pago ya no se sobrescribe. Se retira y queda para que el equipo lo revise, como ya hacían Bazaar y PayAI.</p>
<p>Por ahora los importadores viejos siguen siendo los que corren en el sitio; los feeds están instalados y sin programar. Cambiaremos cuando los hayamos visto andar lado a lado.</p>

<h2>Drupal core 11.4.14</h2>
<p>El 25 de noviembre Drupal publicó SA-CORE-2026-019, calificado como muy crítico: una omisión de acceso alrededor del archivo instalador que trae el core. Ombú estaba en la 11.4.13, que está afectada; esta versión la pasa a la 11.4.14.</p>

<h2>Qué comprobamos</h2>
<p>Pasa toda la suite (487 tests: Ombú, x402_pay y wallet_auth), y una revisión de código de un agente independiente encontró un bloqueo antes de publicar: faltaba el módulo Feeds en la configuración exportada del sitio, lo que lo habría apagado durante el deploy. Está arreglado. En nuestro sitio de desarrollo compramos el servicio en Arc testnet con el facilitador de Circle: 0,005 USDC, un veredicto y una tarjeta en la respuesta. En un navegador, con la billetera simulada, el formulario envió los valores escritos y la página dibujó la tarjeta. Todavía no se probó una compra con una billetera real desde la página.</p>

<h2>Lo que no está hecho</h2>
<p>Solo se soporta el catálogo básico, y solo un vendedor nuestro puede usar A2UI. Que los vendedores externos declaren su propia superficie (en su 402, como una extensión de x402 que tendríamos que proponer) y devolverla por MCP vienen después. Los importadores en Feeds todavía no reemplazan a los viejos.</p>
HTML, 'Ombú dibuja A2UI con elementos de Drupal, un listing puede traer su propio "Probalo", un servicio nuestro dice si conviene contratar un listing, los importadores de catálogos corren sobre Feeds y el core pasa a la 11.4.14.'],
  'pt-br' => ['Novidades do Ombú: A2UI, importadores no Feeds e uma atualização de segurança', <<<HTML
<p class="text-body-secondary small">25 de novembro de 2026 · Quebracho</p>
$figPt

<p class="lead">O "Experimente" de um listing era uma caixa de JSON. Agora um listing pode trazer seu próprio formulário, e nosso primeiro serviço que o usa diz se vale a pena contratar um listing. Além disso, os importadores de catálogos agora rodam sobre o módulo padrão Feeds do Drupal, e o site passa para o core 11.4.14.</p>

<h2>A2UI, desenhado com elementos próprios do Drupal</h2>
<p><a href="https://a2ui.org/specification/v1.0-a2ui/">A2UI</a> é o protocolo aberto do Google para enviar uma interface como dados em vez de código: uma lista de componentes e um modelo de dados, em JSON. Não encontramos um renderizador em PHP nem em Drupal, então o Ombú lê as mensagens ($surface) e desenha um subconjunto do catálogo básico como elementos do Drupal ($renderer): texto, linhas e colunas, cartões, listas, campos de texto, caixas de seleção, opções e as descrições das imagens.</p>
<p>Quem escreve o payload é o vendedor, então a lista de componentes é uma lista permitida: o resto é ignorado e registrado. O texto é sempre escapado, as imagens não carregam, as funções que agem (como abrir uma URL) nunca rodam e os botões do vendedor não são desenhados. A página tem uma só ação, o botão de pagamento, e o que ele faz continua sendo o fluxo do Ombú. As listas podem repetir um componente sobre uma lista do modelo de dados, e rodam as funções que só calculam: <code>formatString</code>, <code>formatNumber</code>, <code>formatCurrency</code>, <code>pluralize</code> e o índice do item ($functions).</p>

<h2>Um formulário no lugar da caixa de JSON</h2>
<p>Um listing tem um campo opcional de A2UI. Quando ele traz uma superfície, o formulário "Experimente" a desenha, e o que o comprador digitou vira o JSON enviado. O campo não deixa salvar um payload que o Ombú não consiga desenhar. Sem ele, a caixa de JSON fica como estava. Por enquanto só a nossa equipe preenche o campo: um listing do formulário de vendedores não tem "Experimente", porque seus compradores pagam direto ao vendedor.</p>

$demoPt
<p><strong>O que isso dá.</strong> O vendedor descreve o formulário uma vez, como dados: não há JavaScript dele para enviar nem em que tenhamos de confiar. O comprador digita em campos em vez de montar JSON à mão, e a resposta é desenhada como o resto do site. Para o Ombú, um payload que não executa nada é tão seguro quanto qualquer outro texto da página. Para os agentes nada muda: continuam lendo o mesmo JSON.</p>

<h2>A checagem de vendedor: contratar, rejeitar ou perguntar a uma pessoa</h2>
<p>A <a href="{{ours-sellercheck}}">checagem de vendedor</a> custa 0,005 USDC. Você dá um listing e, se quiser, o máximo que pagaria por chamada; ela responde <em>contratar</em>, <em>rejeitar</em> ou <em>perguntar a uma pessoa</em>, com suas razões. Lê o que o Ombú já tem ($check): o preço contra o seu orçamento, se o listing foi retirado e a nota de suas avaliações verificadas. Três avaliações verificadas é o mínimo que conta, então um listing que ninguém avaliou nunca dá "contratar": a resposta é perguntar a uma pessoa, e ela diz quantas avaliações há. São os mesmos três vereditos a que chega nosso agente comprador, sem um modelo por trás, então a resposta é a mesma toda vez.</p>
<p>A resposta é JSON simples para os agentes, com um cartão A2UI em <code>a2ui</code> para as pessoas ($checkSurface). A página pede ao site que desenhe o cartão ($render) e o mostra acima dos dados; o HTML que recebe é inerte mesmo se aberto sozinho. Os agentes ignoram essa chave.</p>

<h2>Os importadores de catálogos, reconstruídos sobre o Feeds</h2>
<p>O Ombú lista serviços de três catálogos públicos do x402: o Agent Marketplace da Circle, o Bazaar da Coinbase e o PayAI. Até agora cada um tinha um importador escrito à mão. O <a href="https://www.drupal.org/project/feeds">Feeds</a> é o módulo padrão do Drupal para trazer conteúdo de fora: busca por páginas, interpreta, grava em lotes ou pela fila do cron, pega um bloqueio para que duas execuções nunca se atropelem, e tem uma tela para ver cada importação. Reconstruímos os três importadores como tipos de feed ($feeds): peças próprias do Ombú buscam ($fetcher), leem e gravam os listings, com as mesmas regras de antes: só https, um limite de tamanho por página, e um catálogo que responde vazio ou quebrado não retira nada.</p>
<p><strong>O que isso dá.</strong> Menos código nosso para manter: o motor de importação é o da comunidade, e o nosso tem só o que é particular de cada mercado. As mesmas telas de administração e comandos do drush para todos os catálogos. Os mesmos resultados dos importadores antigos, que um teste compara execução por execução. Uma proteção a mais: um listing da Circle que de repente nomeia outra carteira de pagamento deixa de ser sobrescrito. Ele é retirado e fica para a equipe revisar, como o Bazaar e o PayAI já faziam.</p>
<p>Por enquanto os importadores antigos continuam sendo os que rodam no site; os feeds estão instalados e sem agendamento. Trocaremos quando os tivermos visto funcionar lado a lado.</p>

<h2>Drupal core 11.4.14</h2>
<p>Em 25 de novembro o Drupal publicou o SA-CORE-2026-019, classificado como altamente crítico: um desvio de acesso em torno do arquivo instalador que o core traz. O Ombú estava na 11.4.13, que é afetada; esta versão a leva para a 11.4.14.</p>

<h2>O que verificamos</h2>
<p>Passa toda a suíte (487 testes: Ombú, x402_pay e wallet_auth), e uma revisão de código de um agente independente encontrou um bloqueio antes de publicar: faltava o módulo Feeds na configuração exportada do site, o que o teria desligado durante o deploy. Está corrigido. No nosso site de desenvolvimento compramos o serviço na Arc testnet com o facilitador da Circle: 0,005 USDC, um veredito e um cartão na resposta. Em um navegador, com a carteira simulada, o formulário enviou os valores digitados e a página desenhou o cartão. Ainda não se testou uma compra com uma carteira real a partir da página.</p>

<h2>O que não está feito</h2>
<p>Só o catálogo básico é suportado, e só um vendedor nosso pode usar A2UI. Que os vendedores externos declarem sua própria superfície (no 402, como uma extensão do x402 que teríamos de propor) e devolvê-la por MCP vêm depois. Os importadores no Feeds ainda não substituem os antigos.</p>
HTML, 'O Ombú desenha A2UI com elementos do Drupal, um listing pode trazer seu próprio "Experimente", um serviço nosso diz se vale a pena contratar um listing, os importadores de catálogos rodam sobre o Feeds e o core passa para a 11.4.14.'],
];

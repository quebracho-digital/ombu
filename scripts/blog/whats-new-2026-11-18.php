<?php

/**
 * @file
 * The "What's new" of the deploy of 2026-11-18: the admin dashboard and its
 * actions, the catalogue import narrowed and matched to the price of the
 * day, and the SubQ backend in ai_provider_universal. Every claim is in the
 * commits a348e51..0769ef7.
 *
 * Seeded with the deploy, published: every deploy goes with its post. The
 * pt-br needs a native review.
 */

$img = '/modules/custom/ombu/images/blog';
$gh = 'https://github.com/quebracho-digital/ombu/blob/main/web/modules/custom/ombu';
// A file of the module, linked to the public repository.
$f = fn(string $path) => '<a href="' . $gh . '/' . $path . '"><code>' . basename($path) . '</code></a>';
$vitals = $f('src/Dashboard/Vitals.php');
$controller = $f('src/Controller/DashboardController.php');
$page = $f('templates/ombu-dashboard.html.twig');
$summary = $f('templates/ombu-dashboard-summary.html.twig');
$card = $f('templates/ombu-dashboard-card.html.twig');
$changes = $f('templates/ombu-dashboard-changes.html.twig');
$css = $f('css/dashboard.css');
$block = $f('src/Plugin/Block/DashboardBlock.php');
$deriver = $f('src/Plugin/Derivative/DashboardBlockDeriver.php');
$area = $f('src/Plugin/views/area/Dashboard.php');
$hooks = $f('src/Hook/DashboardHooks.php');
$screeningForm = $f('src/Form/ScreeningForm.php');
$removeForm = $f('src/Form/ScreeningRemoveForm.php');
$walletForm = $f('src/Form/WalletChangeForm.php');
$lists = $f('src/Screening/ScreeningLists.php');
$walletChanges = $f('src/WalletChanges.php');
$routing = $f('ombu.routing.yml');
$permissions = $f('ombu.permissions.yml');
$figure = fn(string $alt, string $caption) => '<figure class="figure w-100 mb-4"><img src="' . $img . '/admin-dashboard.png" class="figure-img img-fluid rounded w-100 border" width="1840" height="1016" alt="' . $alt . '"><figcaption class="figure-caption">' . $caption . '</figcaption></figure>';
$figEn = $figure('Ombú\'s admin dashboard: a strip with two things to look at, then cards for the stop switch, sales, search, cron, imports, screening, changed wallets and moderation, and two wide cards for errors and the blog.', 'The dashboard on our development site, where cron is not running and the errors are old: in production the numbers are the real ones.');
$figEs = $figure('El panel de administración de Ombú: una franja con dos cosas para mirar y, debajo, tarjetas del stop switch, ventas, búsqueda, cron, imports, bloqueos, billeteras cambiadas y moderación, y dos tarjetas anchas de errores y blog.', 'El panel en nuestro sitio de desarrollo, donde cron no corre y los errores son viejos: en producción los números son los reales.');
$figPt = $figure('O painel de administração do Ombú: uma faixa com duas coisas para olhar e, abaixo, cartões do stop switch, vendas, busca, cron, importações, bloqueios, carteiras alteradas e moderação, e dois cartões largos de erros e blog.', 'O painel no nosso site de desenvolvimento, onde o cron não roda e os erros são antigos: em produção os números são os reais.');

return [
  'categories' => ['whats-new'],
  'image' => 'admin-dashboard.png',
  'alt' => [
    'en' => 'Ombú\'s admin dashboard, with one card per vital of the market',
    'es' => 'El panel de administración de Ombú, con una tarjeta por cada signo vital del mercado',
    'pt-br' => 'O painel de administração do Ombú, com um cartão por sinal vital do mercado',
  ],
  'date' => '2026-11-18 12:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú: an admin dashboard, and imports that check the price of the day", <<<HTML
<p class="text-body-secondary small">18 November 2026 · Quebracho</p>
$figEn

<p class="lead">Running the market used to mean a handful of drush commands, the log and five admin pages. Now one page shows how Ombú stands and links to where to act.</p>

<h2>An admin dashboard</h2>
<p>At <code>/admin/ombu</code>, staff see one card per vital of the market, each with its state (OK, Attention, No data) and its main number in large type:</p>
<ul>
<li><strong>Stop switch</strong>: whether the site and payments are running, with the button to stop or resume Ombú.</li>
<li><strong>Sales</strong>: payments settled and refused in the last 24 hours and 7 days.</li>
<li><strong>Search</strong>: listings indexed over the total, in both search engines, and whether each server answers.</li>
<li><strong>Cron and queues</strong>, and <strong>catalogue imports</strong>: when each last ran.</li>
<li><strong>Screening</strong>: the wallets we block, and <strong>changed wallets</strong>: listings retired because their seller answers with another wallet.</li>
<li><strong>Errors</strong> from the log, <strong>moderation</strong> (comments and contact messages waiting) and the <strong>blog</strong>.</li>
</ul>
<p>A strip at the top links to every card that needs a look. The page itself changes nothing: each action is its own form, with a confirmation and its own permission, and leaves a line in the log with who did what. From the dashboard, staff can block or unblock a wallet, and accept a seller's new wallet after every check runs again. Only staff see any of it.</p>

<h3>The files that make it</h3>
<p>It is all in the <code>ombu</code> module, in our public repository:</p>
<ul>
<li><strong>The data</strong>: $vitals computes every card with no HTML (so a test can check the numbers), and $controller draws the page.</li>
<li><strong>The Twig templates</strong>: $page (the page), $summary (the strip at the top), $card (one card) and $changes (the changed wallets), with the styles in $css.</li>
<li><strong>The blocks</strong>: $block, with one block per card from $deriver, so a card can be placed with Layout Builder.</li>
<li><strong>Views</strong>: $area, an area plugin that puts the whole dashboard in any view, described by $hooks. We did not make a view: the area is there for whoever wants one.</li>
<li><strong>The actions</strong>: $screeningForm and $removeForm for the blocked wallets, $walletForm for a changed wallet, over the same services the drush commands use, $lists and $walletChanges.</li>
<li><strong>Routes and permissions</strong>: $routing and $permissions.</li>
</ul>

<h2>Catalogue imports that check the price of the day</h2>
<p>Before listing a service from a public catalogue, Ombú already checked that its wallet had received payments. That proved activity, not that this service sells: a wallet paid 1,000 USDC for something else passed for a $0.005 service. An x402 payment moves exactly the price, no more and no less, so the import can now demand a payment of exactly the price the service had on the day of the payment. If the price changed, the listing's own history says what it was that day; a service seen for the first time is matched to its current price. The import can also be narrowed to some tags, and to services with a recent sale.</p>

<h2>SubQ, in our AI module</h2>
<p>Our AI provider for Drupal, <a href="https://www.drupal.org/project/ai_provider_universal">ai_provider_universal</a>, now speaks to SubQ Snap: models that answer typed questions (yes or no, a choice, a score) with calibrated probabilities instead of text. We use it, for now in development, as a judge of our "similar services": for each pair, is the second an alternative to the first, does it work with it, or neither.</p>

<h2>What is not done</h2>
<p>The dashboard is a first version: its permissions are given to no role yet, so only administrators see it. The importers of the public catalogues still run by hand, not on their own.</p>
HTML, 'An admin dashboard with one card per vital of the market, imports that check the price of the day, and SubQ in our AI module.'],
  'es' => ['Novedades de Ombú: un panel de administración, e imports que miran el precio del día', <<<HTML
<p class="text-body-secondary small">18 de noviembre de 2026 · Quebracho</p>
$figEs

<p class="lead">Atender el mercado era un puñado de comandos de drush, el log y cinco pantallas de administración. Ahora una sola página muestra cómo está Ombú y enlaza a donde se actúa.</p>

<h2>Un panel de administración</h2>
<p>En <code>/admin/ombu</code>, el equipo ve una tarjeta por cada signo vital del mercado, cada una con su estado (OK, Atención, Sin datos) y su número principal en grande:</p>
<ul>
<li><strong>Stop switch</strong>: si el sitio y los pagos están andando, con el botón para pausar o reanudar Ombú.</li>
<li><strong>Ventas</strong>: pagos cobrados y rechazados en las últimas 24 horas y 7 días.</li>
<li><strong>Búsqueda</strong>: servicios indexados sobre el total, en los dos buscadores, y si cada servidor responde.</li>
<li><strong>Cron y colas</strong>, y <strong>imports de catálogos</strong>: cuándo corrió cada uno por última vez.</li>
<li><strong>Bloqueos</strong>: las billeteras que bloqueamos, y <strong>billeteras cambiadas</strong>: servicios retirados porque su vendedor responde con otra billetera.</li>
<li><strong>Errores</strong> del log, <strong>moderación</strong> (comentarios y mensajes de contacto esperando) y el <strong>blog</strong>.</li>
</ul>
<p>Una franja arriba enlaza a cada tarjeta que pide atención. La página en sí no cambia nada: cada acción es un formulario propio, con confirmación y su propio permiso, y deja una línea en el log con quién hizo qué. Desde el panel se puede bloquear o desbloquear una billetera, y aceptar la billetera nueva de un vendedor después de que todos los chequeos corran de nuevo. Solo lo ve el equipo.</p>

<h3>Los archivos que lo arman</h3>
<p>Está todo en el módulo <code>ombu</code>, en nuestro repositorio público:</p>
<ul>
<li><strong>Los datos</strong>: $vitals calcula cada tarjeta sin HTML (así un test puede revisar los números), y $controller dibuja la página.</li>
<li><strong>Las plantillas Twig</strong>: $page (la página), $summary (la franja de arriba), $card (una tarjeta) y $changes (las billeteras cambiadas), con los estilos en $css.</li>
<li><strong>Los bloques</strong>: $block, con un bloque por tarjeta desde $deriver, para ubicar una tarjeta con Layout Builder.</li>
<li><strong>Views</strong>: $area, un plugin de área que pone el panel entero en cualquier view, descrito por $hooks. No armamos una view: el área está para quien la quiera.</li>
<li><strong>Las acciones</strong>: $screeningForm y $removeForm para las billeteras bloqueadas, $walletForm para una billetera cambiada, sobre los mismos servicios que usan los comandos de drush, $lists y $walletChanges.</li>
<li><strong>Rutas y permisos</strong>: $routing y $permissions.</li>
</ul>

<h2>Imports de catálogos que miran el precio del día</h2>
<p>Antes de publicar un servicio de un catálogo público, Ombú ya revisaba que su billetera hubiera recibido pagos. Eso probaba actividad, no que ese servicio se venda: una billetera que cobró 1.000 USDC por otra cosa pasaba por un servicio de $0,005. Un pago x402 mueve exactamente el precio, ni más ni menos, así que ahora el import puede exigir un pago de exactamente el precio que el servicio tenía el día del pago. Si el precio cambió, el historial del propio servicio dice cuál era ese día; un servicio que vemos por primera vez se compara con su precio actual. El import también se puede acotar a algunas etiquetas, y a servicios con una venta reciente.</p>

<h2>SubQ, en nuestro módulo de IA</h2>
<p>Nuestro proveedor de IA para Drupal, <a href="https://www.drupal.org/project/ai_provider_universal">ai_provider_universal</a>, ahora habla con SubQ Snap: modelos que responden preguntas tipadas (sí o no, una opción, un puntaje) con probabilidades calibradas en lugar de texto. Lo usamos, por ahora en desarrollo, como juez de nuestros "servicios similares": para cada par, ¿el segundo es una alternativa al primero, funciona junto a él, o ninguna de las dos?</p>

<h2>Lo que no está hecho</h2>
<p>El panel es una primera versión: sus permisos todavía no están asignados a ningún rol, así que solo lo ven los administradores. Los importadores de los catálogos públicos siguen corriendo a mano, no solos.</p>
HTML, 'Un panel de administración con una tarjeta por cada signo vital del mercado, imports que miran el precio del día, y SubQ en nuestro módulo de IA.'],
  'pt-br' => ['Novidades do Ombú: um painel de administração, e importações que olham o preço do dia', <<<HTML
<p class="text-body-secondary small">18 de novembro de 2026 · Quebracho</p>
$figPt

<p class="lead">Cuidar do mercado era um punhado de comandos do drush, o log e cinco telas de administração. Agora uma única página mostra como o Ombú está e leva aonde se age.</p>

<h2>Um painel de administração</h2>
<p>Em <code>/admin/ombu</code>, a equipe vê um cartão por sinal vital do mercado, cada um com seu estado (OK, Atenção, Sem dados) e seu número principal em destaque:</p>
<ul>
<li><strong>Stop switch</strong>: se o site e os pagamentos estão funcionando, com o botão para pausar ou retomar o Ombú.</li>
<li><strong>Vendas</strong>: pagamentos liquidados e recusados nas últimas 24 horas e 7 dias.</li>
<li><strong>Busca</strong>: serviços indexados sobre o total, nos dois buscadores, e se cada servidor responde.</li>
<li><strong>Cron e filas</strong>, e <strong>importações de catálogos</strong>: quando cada um rodou pela última vez.</li>
<li><strong>Bloqueios</strong>: as carteiras que bloqueamos, e <strong>carteiras alteradas</strong>: serviços retirados porque o vendedor responde com outra carteira.</li>
<li><strong>Erros</strong> do log, <strong>moderação</strong> (comentários e mensagens de contato esperando) e o <strong>blog</strong>.</li>
</ul>
<p>Uma faixa no topo leva a cada cartão que pede atenção. A página em si não muda nada: cada ação é um formulário próprio, com confirmação e permissão própria, e deixa uma linha no log com quem fez o quê. Do painel dá para bloquear ou desbloquear uma carteira, e aceitar a carteira nova de um vendedor depois que todas as verificações rodam de novo. Só a equipe vê.</p>

<h3>Os arquivos que o montam</h3>
<p>Está tudo no módulo <code>ombu</code>, no nosso repositório público:</p>
<ul>
<li><strong>Os dados</strong>: $vitals calcula cada cartão sem HTML (assim um teste pode conferir os números), e $controller desenha a página.</li>
<li><strong>Os templates Twig</strong>: $page (a página), $summary (a faixa do topo), $card (um cartão) e $changes (as carteiras alteradas), com os estilos em $css.</li>
<li><strong>Os blocos</strong>: $block, com um bloco por cartão a partir de $deriver, para posicionar um cartão com o Layout Builder.</li>
<li><strong>Views</strong>: $area, um plugin de área que coloca o painel inteiro em qualquer view, descrito por $hooks. Não montamos uma view: a área está aí para quem quiser.</li>
<li><strong>As ações</strong>: $screeningForm e $removeForm para as carteiras bloqueadas, $walletForm para uma carteira alterada, sobre os mesmos serviços que os comandos do drush usam, $lists e $walletChanges.</li>
<li><strong>Rotas e permissões</strong>: $routing e $permissions.</li>
</ul>

<h2>Importações de catálogos que olham o preço do dia</h2>
<p>Antes de publicar um serviço de um catálogo público, o Ombú já verificava que a carteira dele tinha recebido pagamentos. Isso provava atividade, não que esse serviço vende: uma carteira que recebeu 1.000 USDC por outra coisa passava por um serviço de US\$ 0,005. Um pagamento x402 move exatamente o preço, nem mais nem menos, então agora a importação pode exigir um pagamento de exatamente o preço que o serviço tinha no dia do pagamento. Se o preço mudou, o histórico do próprio serviço diz qual era naquele dia; um serviço visto pela primeira vez é comparado com o preço atual. A importação também pode ser restrita a algumas etiquetas, e a serviços com uma venda recente.</p>

<h2>SubQ, no nosso módulo de IA</h2>
<p>Nosso provedor de IA para Drupal, <a href="https://www.drupal.org/project/ai_provider_universal">ai_provider_universal</a>, agora fala com o SubQ Snap: modelos que respondem perguntas tipadas (sim ou não, uma opção, uma nota) com probabilidades calibradas em vez de texto. Usamos, por enquanto em desenvolvimento, como juiz dos nossos "serviços similares": para cada par, o segundo é uma alternativa ao primeiro, funciona junto com ele, ou nenhum dos dois?</p>

<h2>O que não está feito</h2>
<p>O painel é uma primeira versão: suas permissões ainda não estão atribuídas a nenhum papel, então só os administradores o veem. Os importadores dos catálogos públicos continuam rodando à mão, não sozinhos.</p>
HTML, 'Um painel de administração com um cartão por sinal vital do mercado, importações que olham o preço do dia, e o SubQ no nosso módulo de IA.'],
];

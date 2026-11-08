<?php

/**
 * @file
 * The "What's new" of 8 November, both deploys in one post: the blog as the
 * home and featured services (morning), then the home as a Layout Builder
 * page, Metatag and the links for agents, why the share block stays plain
 * links, and the Bazaar and PayAI importers, which are in the code and have
 * not run in production (evening). Every claim is in the commits of the day.
 *
 * Seed it only after the deploy is verified. The pt-br needs a native review.
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'categories' => ['whats-new'],
  'image' => 'market-hall.jpg',
  'alt' => [
    'en' => 'Inside the municipal market of La Quiaca, Argentina',
    'es' => 'El interior del mercado municipal de La Quiaca, Argentina',
    'pt-br' => 'O interior do mercado municipal de La Quiaca, Argentina',
  ],
  'date' => '2026-11-08 20:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú on 8 November: a home you can rearrange, featured services, tags for agents and two catalogues we check", <<<HTML
<p class="text-body-secondary small">8 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Inside the municipal market of La Quiaca, Argentina."></figure>

<p class="lead">On 8 November we deployed twice, in the morning and in the evening. This is what changed, what we chose not to do, and what is still missing.</p>

<h2>The home is a page you can rearrange</h2>
<p>The home was a view whose blocks sat in fixed places. It is now a page built with Layout Builder, Drupal's own block layout tool. Whoever runs the site can reorder, hide or add blocks under <em>Structure, Content types, Home page, Manage display, Manage layout</em>, without touching code, and the layout is saved in the site's configuration like everything else.</p>
<p>The seven blocks keep their order: the hero, the featured services, the latest posts, the newest listings, the best rated, the latest sales and the strip for agents. The layout is one column for now.</p>

<h2>Featured services</h2>
<p>A listing can be marked as featured, and the featured ones get their own block on the home page.</p>

<h2>Tags that tell networks and agents what a page is</h2>
<p>We replaced our hand-written tags with Metatag, a module the Drupal community maintains. A page now says its title, its description, its canonical address and a photo for the preview, in the Open Graph and Twitter tags. A post uses its own photo; a page without one uses ours. A market search keeps only its filters in the address, so a page number or a tracking parameter does not become a different page.</p>
<p>For agents, every page now carries three links in its head, each with the type of what it points to: the Markdown version of a market search, <code>llms.txt</code>, which explains the site, and <code>/openapi.json</code>, which describes the services we sell. A post and a listing already had their Markdown twin: add <code>.md</code> to the address. An agent that only reads the HTML finds the same pointers as one that reads the headers.</p>

<h2>Similar services</h2>
<p>Under each listing, Ombú shows other services that could stand in for it, ranked by shared terms and by the meaning of their text. <a href="/blog/how-agents-find-and-buy-over-mcp">The post about MCP</a> explains how the list is built and why we run a fork of the Meilisearch module for it.</p>

<h2>Why the share block is plain links</h2>
<p>Under a post, a listing or a filtered market there is a block to share it on Bluesky, Mastodon, X, LinkedIn, WhatsApp, Telegram or email, and to copy the address. We looked at the modules for this and chose not to use one.</p>
<ul>
<li>The module of a widely used sharing service loads a script from that service on every page, so a third party sees the visit before anyone clicks. We want none.</li>
<li>The modules that only write links do what our seven links already do.</li>
<li>What makes a good preview is not the button but the tags on the page, and that is what Metatag now does.</li>
</ul>
<p>We have not yet seen how each network shows the preview, since they could not reach a site on our own computer. We will check with their inspectors and say what we find. LinkedIn and Facebook keep a preview for a while after the first time they read a page.</p>

<h2>Bazaar and PayAI: the importers exist, and they have not run in production</h2>
<p>The code to bring in the services of Coinbase's Bazaar and of PayAI is in the repository. It is run by hand, not on a schedule, and it has not imported anything into the market yet. A resource becomes a listing only if we check it ourselves:</p>
<ul>
<li>the live payment request must say the same network, asset, address and amount as the catalogue;</li>
<li>the address must not be frozen by the token nor on the OFAC sanctions list;</li>
<li>the address must have received USDC on chain at least once.</li>
</ul>
<p>Only payments in USDC on the six Ethereum-compatible networks we can check are considered; Solana and Arc are left out for now. When we cannot ask (a node or the seller does not answer), nothing is listed and nothing already listed is taken down. In a trial on our development site, 36 of the first 40 resources of Bazaar passed. Of the first 40 of PayAI, 18 passed, 12 were skipped for being Solana only and 10 were rejected, mostly because what they answer differs from what their catalogue says.</p>
<p>We dropped 8004scan: we measured it and found nothing verified worth importing.</p>

<h2>Smaller things</h2>
<p>The Symfony components Drupal runs on moved from 7.4.22 to 7.4.23, which closes eight security advisories that were open on them.</p>
<p>The footer now says what Ombú runs on: Drupal and Drupal Commerce, and who builds it.</p>

<h2>What is still missing</h2>
<p>Circle's listings do not yet pass the same checks as the new importers; making that a rule for every importer is next. The Portuguese of this post and of the others has not had a native review.</p>
HTML, 'Two deploys on 8 November: the home can be rearranged with Layout Builder, services can be featured, Metatag writes the tags and the links for agents, the share block stays plain links, and the Bazaar and PayAI importers are in the code but have not run in production.'],
  'es' => ['Novedades de Ombú del 8 de noviembre: una portada que se reordena, servicios destacados, etiquetas para agentes y dos catálogos que verificamos', <<<HTML
<p class="text-body-secondary small">8 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="El interior del mercado municipal de La Quiaca, Argentina."></figure>

<p class="lead">El 8 de noviembre hicimos dos deploys, uno a la mañana y otro a la noche. Esto es lo que cambió, lo que decidimos no hacer y lo que todavía falta.</p>

<h2>La portada es una página que se reordena</h2>
<p>La portada era una vista con sus bloques en lugares fijos. Ahora es una página armada con Layout Builder, la herramienta de Drupal para componer bloques. Quien administra el sitio puede reordenar, ocultar o agregar bloques en <em>Estructura, Tipos de contenido, Home page, Gestionar visualización, Gestionar layout</em>, sin tocar código, y el diseño se guarda en la configuración del sitio como todo lo demás.</p>
<p>Los siete bloques mantienen su orden: el hero, los servicios destacados, los últimos posts, los listados nuevos, los mejor valorados, las últimas ventas y la franja para agentes. Por ahora el diseño es de una sola columna.</p>

<h2>Servicios destacados</h2>
<p>Un listado se puede marcar como destacado, y los destacados tienen su propio bloque en la portada.</p>

<h2>Etiquetas que dicen a las redes y a los agentes qué es una página</h2>
<p>Reemplazamos nuestras etiquetas escritas a mano por Metatag, un módulo que mantiene la comunidad de Drupal. Una página ahora dice su título, su descripción, su dirección canónica y una foto para la vista previa, en las etiquetas Open Graph y de Twitter. Un post usa su propia foto; una página sin foto usa la nuestra. Una búsqueda del mercado conserva solo sus filtros en la dirección, así que un número de página o un parámetro de seguimiento no se convierte en otra página.</p>
<p>Para los agentes, cada página lleva ahora tres enlaces en su cabecera, cada uno con el tipo de lo que señala: la versión en Markdown de una búsqueda del mercado, <code>llms.txt</code>, que explica el sitio, y <code>/openapi.json</code>, que describe los servicios que vendemos. Un post y un listado ya tenían su gemelo en Markdown: se agrega <code>.md</code> a la dirección. Un agente que solo lee el HTML encuentra las mismas indicaciones que uno que lee las cabeceras.</p>

<h2>Servicios similares</h2>
<p>Debajo de cada listado, Ombú muestra otros servicios que podrían reemplazarlo, ordenados por términos en común y por el significado de su texto. <a href="/es/blog/how-agents-find-and-buy-over-mcp">El post sobre MCP</a> explica cómo se arma la lista y por qué usamos un fork del módulo de Meilisearch para eso.</p>

<h2>Por qué el bloque de compartir son enlaces simples</h2>
<p>Debajo de un post, un listado o un mercado filtrado hay un bloque para compartirlo en Bluesky, Mastodon, X, LinkedIn, WhatsApp, Telegram o por correo, y para copiar la dirección. Miramos los módulos que existen para esto y elegimos no usar ninguno.</p>
<ul>
<li>El módulo de un servicio de compartir muy usado carga un script de ese servicio en cada página, así que un tercero ve la visita antes de que alguien haga clic. No queremos ninguno.</li>
<li>Los módulos que solo escriben enlaces hacen lo que ya hacen nuestros siete enlaces.</li>
<li>Lo que hace buena una vista previa no es el botón sino las etiquetas de la página, y eso ahora lo hace Metatag.</li>
</ul>
<p>Todavía no vimos cómo muestra cada red la vista previa, porque no podían llegar a un sitio en nuestra propia computadora. Lo vamos a verificar con sus inspectores y vamos a contar qué encontramos. LinkedIn y Facebook guardan la vista previa un tiempo después de leer una página por primera vez.</p>

<h2>Bazaar y PayAI: los importadores existen y no corrieron en producción</h2>
<p>El código para traer los servicios del Bazaar de Coinbase y de PayAI está en el repositorio. Se corre a mano, no en un horario, y todavía no importó nada al mercado. Un recurso se vuelve listado solo si lo verificamos nosotros:</p>
<ul>
<li>el pedido de pago en vivo debe decir la misma red, activo, dirección y monto que el catálogo;</li>
<li>la dirección no debe estar congelada por el token ni en la lista de sanciones de OFAC;</li>
<li>la dirección debe haber recibido USDC en la cadena al menos una vez.</li>
</ul>
<p>Solo se consideran pagos en USDC en las seis redes compatibles con Ethereum que podemos verificar; Solana y Arc quedan afuera por ahora. Cuando no podemos preguntar (un nodo o el vendedor no responde), no se publica nada y nada de lo ya publicado se da de baja. En una prueba en nuestro sitio de desarrollo, 36 de los primeros 40 recursos de Bazaar pasaron. De los primeros 40 de PayAI, 18 pasaron, 12 se saltearon por ser solo de Solana y 10 se rechazaron, casi todos porque lo que responden difiere de lo que dice su catálogo.</p>
<p>Descartamos 8004scan: lo medimos y no encontramos nada verificado que valiera la pena importar.</p>

<h2>Cosas menores</h2>
<p>Los componentes de Symfony sobre los que corre Drupal pasaron de 7.4.22 a 7.4.23, lo que cierra ocho avisos de seguridad que tenían abiertos.</p>
<p>El pie de página ahora dice sobre qué funciona Ombú, Drupal y Drupal Commerce, y quién lo hace.</p>

<h2>Lo que todavía falta</h2>
<p>Los listados de Circle todavía no pasan por los mismos controles que los importadores nuevos; convertirlo en regla para todos los importadores es lo próximo. El portugués de este post y de los otros no tuvo revisión nativa.</p>
HTML, 'Dos deploys el 8 de noviembre: la portada se reordena con Layout Builder, los servicios se pueden destacar, Metatag escribe las etiquetas y los enlaces para agentes, el bloque de compartir sigue siendo enlaces simples, y los importadores de Bazaar y PayAI están en el código pero no corrieron en producción.'],
  'pt-br' => ['Novidades do Ombú de 8 de novembro: uma página inicial que se reorganiza, serviços em destaque, etiquetas para agentes e dois catálogos que verificamos', <<<HTML
<p class="text-body-secondary small">8 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="O interior do mercado municipal de La Quiaca, Argentina."></figure>

<p class="lead">Em 8 de novembro fizemos dois deploys, um de manhã e outro à noite. Isto é o que mudou, o que decidimos não fazer e o que ainda falta.</p>

<h2>A página inicial é uma página que se reorganiza</h2>
<p>A página inicial era uma view com seus blocos em lugares fixos. Agora é uma página montada com o Layout Builder, a ferramenta do Drupal para compor blocos. Quem administra o site pode reordenar, ocultar ou adicionar blocos em <em>Estrutura, Tipos de conteúdo, Home page, Gerenciar exibição, Gerenciar layout</em>, sem mexer em código, e o desenho fica salvo na configuração do site como todo o resto.</p>
<p>Os sete blocos mantêm a ordem: o hero, os serviços em destaque, os últimos posts, os anúncios novos, os mais bem avaliados, as últimas vendas e a faixa para agentes. Por enquanto o layout tem uma coluna só.</p>

<h2>Serviços em destaque</h2>
<p>Um anúncio pode ser marcado como destaque, e os destaques ganham um bloco próprio na página inicial.</p>

<h2>Etiquetas que dizem às redes e aos agentes o que é uma página</h2>
<p>Trocamos nossas etiquetas escritas à mão pelo Metatag, um módulo mantido pela comunidade do Drupal. Uma página agora diz seu título, sua descrição, seu endereço canônico e uma foto para a prévia, nas etiquetas Open Graph e do Twitter. Um post usa a própria foto; uma página sem foto usa a nossa. Uma busca do mercado guarda no endereço só os seus filtros, então um número de página ou um parâmetro de rastreamento não vira outra página.</p>
<p>Para os agentes, cada página agora leva três links no cabeçalho, cada um com o tipo do que aponta: a versão em Markdown de uma busca do mercado, o <code>llms.txt</code>, que explica o site, e o <code>/openapi.json</code>, que descreve os serviços que vendemos. Um post e um anúncio já tinham seu gêmeo em Markdown: basta acrescentar <code>.md</code> ao endereço. Um agente que lê só o HTML encontra as mesmas indicações de quem lê os cabeçalhos.</p>

<h2>Serviços parecidos</h2>
<p>Embaixo de cada anúncio, o Ombú mostra outros serviços que poderiam substituí-lo, ordenados por termos em comum e pelo significado do texto. <a href="/pt-br/blog/how-agents-find-and-buy-over-mcp">A publicação sobre MCP</a> explica como a lista é montada e por que usamos um fork do módulo do Meilisearch para isso.</p>

<h2>Por que o bloco de compartilhar são links simples</h2>
<p>Embaixo de um post, de um anúncio ou de um mercado filtrado há um bloco para compartilhar no Bluesky, Mastodon, X, LinkedIn, WhatsApp, Telegram ou por e-mail, e para copiar o endereço. Olhamos os módulos que existem para isso e escolhemos não usar nenhum.</p>
<ul>
<li>O módulo de um serviço de compartilhamento muito usado carrega um script desse serviço em cada página, então um terceiro vê a visita antes de alguém clicar. Não queremos nenhum.</li>
<li>Os módulos que só escrevem links fazem o que os nossos sete links já fazem.</li>
<li>O que faz uma boa prévia não é o botão, e sim as etiquetas da página, e isso agora é o Metatag que faz.</li>
</ul>
<p>Ainda não vimos como cada rede mostra a prévia, porque elas não conseguiam chegar a um site no nosso computador. Vamos conferir com os inspetores delas e contar o que encontrarmos. O LinkedIn e o Facebook guardam a prévia por um tempo depois de ler uma página pela primeira vez.</p>

<h2>Bazaar e PayAI: os importadores existem e não rodaram em produção</h2>
<p>O código para trazer os serviços do Bazaar da Coinbase e da PayAI está no repositório. Ele é rodado à mão, não em horário fixo, e ainda não importou nada para o mercado. Um recurso só vira anúncio se nós mesmos o verificamos:</p>
<ul>
<li>o pedido de pagamento ao vivo deve dizer a mesma rede, ativo, endereço e valor do catálogo;</li>
<li>o endereço não pode estar congelado pelo token nem na lista de sanções da OFAC;</li>
<li>o endereço deve ter recebido USDC na cadeia pelo menos uma vez.</li>
</ul>
<p>Só são considerados pagamentos em USDC nas seis redes compatíveis com Ethereum que conseguimos verificar; Solana e Arc ficam de fora por enquanto. Quando não podemos perguntar (um nó ou o vendedor não responde), nada é publicado e nada do que já está publicado é retirado. Em um teste no nosso site de desenvolvimento, 36 dos primeiros 40 recursos do Bazaar passaram. Dos primeiros 40 da PayAI, 18 passaram, 12 foram pulados por serem só de Solana e 10 foram rejeitados, quase todos porque o que respondem difere do que diz o catálogo deles.</p>
<p>Descartamos o 8004scan: medimos e não encontramos nada verificado que valesse a pena importar.</p>

<h2>Coisas menores</h2>
<p>Os componentes do Symfony sobre os quais o Drupal roda passaram da 7.4.22 para a 7.4.23, o que fecha oito avisos de segurança que estavam abertos neles.</p>
<p>O rodapé agora diz sobre o que o Ombú funciona, Drupal e Drupal Commerce, e quem o faz.</p>

<h2>O que ainda falta</h2>
<p>Os anúncios do Circle ainda não passam pelos mesmos controles dos importadores novos; tornar isso uma regra para todos os importadores é o próximo passo. O português deste post e dos outros não teve revisão nativa.</p>
HTML, 'Dois deploys em 8 de novembro: a página inicial se reorganiza com o Layout Builder, os serviços podem ser destacados, o Metatag escreve as etiquetas e os links para agentes, o bloco de compartilhar continua sendo links simples, e os importadores do Bazaar e da PayAI estão no código mas não rodaram em produção.'],
];

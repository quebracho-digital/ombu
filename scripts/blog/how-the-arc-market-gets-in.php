<?php

/**
 * @file
 * Second post: how Circle's catalogue is imported. See how-ombu-started.php.
 * Everything it says is what CircleCatalogImporter does.
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'image' => 'market-hall.jpg',
  'alt' => [
    'en' => 'A municipal market hall full of sacks of grain',
    'es' => 'Un mercado municipal lleno de bolsas de granos',
    'pt-br' => 'Um mercado municipal cheio de sacas de grãos',
  ],
  'date' => '2026-11-06 14:00:00 America/Argentina/Buenos_Aires',
  'en' => ['From Circle to your search box: how the Arc market gets onto our shelves', <<<HTML
<p class="text-body-secondary small">6 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Inside a municipal market hall: sacks of grains and potatoes in blue, and stalls covered with blue tarpaulins."><figcaption class="figure-caption">The municipal market of La Quiaca, Argentina. Ours has about 2,800 stalls, and every one is an API.</figcaption></figure>

<p class="lead">Every service of Circle's Agent Marketplace is on Ombú, searchable next to ours. Nobody typed them in. This is how they get here, and the rules we kept so a catalogue that is not ours does not wreck the shop.</p>

<h2>1. We ask, politely, for everything</h2>
<p>Circle publishes its catalogue through a public, read-only Discovery API. Our importer walks it, a hundred resources per page, until the pages run out. One command does it: <code>drush ombu:import-circle</code>. Only one import runs at a time: two at once would each miss the other's new listings and make duplicates.</p>

<h2>2. A resource becomes a listing</h2>
<p>Each resource is one listing, and its identity is its HTTP method plus its URL. We keep only the ones paid with the <em>exact</em> scheme, the plain "this call costs this much" kind. The price shown is the cheapest exact offer, with Arc first, since Arc is our chain; the listing still carries every network it accepts. We read every amount as USDC, six decimals, which is true for the whole catalogue today.</p>

<h2>3. The listing keeps its own manual</h2>
<p>A provider can publish an OpenAPI description of its API. When it does, we fetch it once per provider, not once per listing, through a guarded fetch: public addresses only, five megabytes at most. Each listing keeps the single operation of that spec that documents it, so people and agents can search the API itself, not only its blurb.</p>

<h2>4. Only real changes count</h2>
<p>For each resource we hash what Circle says plus the operation we found. If the hash is the same as last time, nothing is touched. We do not trust the "last updated" date alone: it is the content that decides. Categories and tags become terms you can filter by, and each provider gets its site's icon as a logo, saved once on our side, not hotlinked.</p>

<h2>5. What is ours stays ours</h2>
<p>If Circle lists one of our own services too, we do not copy it and do not overwrite it. We only mark it as also on Circle's market. A listing a seller added by hand, same story.</p>

<h2>6. What disappears is retired, never deleted</h2>
<p>When a full run no longer finds a listing, we retire it: still published and open to reviews, never for sale, never deleted. Reviews are what buyers earned, so they stay. Only a complete run knows what disappeared, so a quick test run with <code>--max</code> retires nothing.</p>

<h2>7. What staff delete stays deleted</h2>
<p>If we take a listing down, because of an abuse report or a legal order, we keep its key and the import skips it from then on. Otherwise the next run would bring it right back.</p>

<h2>8. Then, search</h2>
<p>After the import the catalogue is indexed, and the <a href="/market">market</a> finds it by words and by filter: category, network, payment kind, seller. The same search is a JSON and a markdown page for agents.</p>

<p>Circle is the first source, not the last. The importer was written so the next catalogue follows the same rules, and we will write here when it does.</p>
HTML, 'Every service of Circle\'s Agent Marketplace is on Ombú, searchable next to ours, and nobody typed them in. This is how they get here and the rules we kept.'],
  'es' => ['De Circle a tu buscador: cómo el mercado de Arc llega a nuestros estantes', <<<HTML
<p class="text-body-secondary small">6 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Interior de un mercado municipal: bolsas de granos y papas azules y puestos cubiertos con lonas azules."><figcaption class="figure-caption">El mercado municipal de La Quiaca, Argentina. El nuestro tiene unos 2.800 puestos, y cada uno es una API.</figcaption></figure>

<p class="lead">Todos los servicios del Agent Marketplace de Circle están en Ombú, junto a los nuestros. Nadie los cargó a mano. Así llegan, y las reglas que mantenemos para que un catálogo que no es nuestro no arruine la tienda.</p>

<h2>1. Pedimos todo, con buenos modales</h2>
<p>Circle publica su catálogo en una API de descubrimiento, pública y de solo lectura. Nuestro importador la recorre, cien recursos por página, hasta que se acaban las páginas. Lo hace un solo comando: <code>drush ombu:import-circle</code>. Solo corre una importación a la vez: dos juntas se perderían los listados nuevos una de la otra y harían duplicados.</p>

<h2>2. Un recurso se vuelve un listado</h2>
<p>Cada recurso es un listado, y su identidad es su método HTTP más su URL. Nos quedamos solo con los que se pagan con el esquema <em>exact</em>, el de "esta llamada cuesta tanto". El precio que se muestra es la oferta exacta más barata, con Arc primero, porque Arc es nuestra cadena; el listado igual conserva todas las redes que acepta. Leemos cada monto como USDC, seis decimales, lo que hoy es cierto para todo el catálogo.</p>

<h2>3. El listado trae su propio manual</h2>
<p>Un proveedor puede publicar una descripción OpenAPI de su API. Cuando lo hace, la bajamos una vez por proveedor, no una por listado, con una descarga protegida: solo direcciones públicas, cinco megabytes como máximo. Cada listado guarda la única operación de esa especificación que lo documenta, así personas y agentes buscan en la API misma, no solo en su descripción.</p>

<h2>4. Solo cuentan los cambios reales</h2>
<p>Para cada recurso calculamos un hash de lo que dice Circle más la operación que encontramos. Si es igual al de la vez anterior, no se toca nada. No confiamos solo en la fecha de "última actualización": decide el contenido. Las categorías y las etiquetas se vuelven términos por los que se puede filtrar, y cada proveedor recibe el ícono de su sitio como logo, guardado una vez de nuestro lado, sin enlazarlo desde afuera.</p>

<h2>5. Lo nuestro sigue siendo nuestro</h2>
<p>Si Circle lista también uno de nuestros servicios, no lo copiamos ni lo pisamos. Solo lo marcamos como presente también en el mercado de Circle. Igual con un listado que un vendedor cargó a mano.</p>

<h2>6. Lo que desaparece se retira, nunca se borra</h2>
<p>Cuando una corrida completa ya no encuentra un listado, lo retiramos: sigue publicado y abierto a reseñas, nunca a la venta, nunca borrado. Las reseñas son lo que los compradores se ganaron, así que se quedan. Solo una corrida completa sabe qué desapareció; una prueba rápida con <code>--max</code> no retira nada.</p>

<h2>7. Lo que el equipo borra queda borrado</h2>
<p>Si bajamos un listado por una denuncia de abuso o una orden legal, guardamos su clave y la importación lo saltea de ahí en adelante. Si no, la próxima corrida lo traería de vuelta.</p>

<h2>8. Después, la búsqueda</h2>
<p>Tras la importación el catálogo se indexa, y el <a href="/es/market">mercado</a> lo encuentra por palabras y por filtro: categoría, red, tipo de pago, vendedor. La misma búsqueda es una página JSON y una en markdown para los agentes.</p>

<p>Circle es la primera fuente, no la última. El importador se escribió para que el próximo catálogo siga las mismas reglas, y lo contaremos aquí cuando llegue.</p>
HTML, 'Todos los servicios del Agent Marketplace de Circle están en Ombú, junto a los nuestros, y nadie los cargó a mano. Así llegan y estas son las reglas que mantenemos.'],
  'pt-br' => ['Da Circle à sua busca: como o mercado da Arc chega às nossas prateleiras', <<<HTML
<p class="text-body-secondary small">6 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Interior de um mercado municipal: sacas de grãos e batatas azuis e bancas cobertas com lonas azuis."><figcaption class="figure-caption">O mercado municipal de La Quiaca, Argentina. O nosso tem cerca de 2.800 bancas, e cada uma é uma API.</figcaption></figure>

<p class="lead">Todos os serviços do Agent Marketplace da Circle estão no Ombú, ao lado dos nossos. Ninguém os cadastrou à mão. É assim que chegam, e as regras que mantemos para que um catálogo que não é nosso não estrague a loja.</p>

<h2>1. Pedimos tudo, com educação</h2>
<p>A Circle publica seu catálogo numa API de descoberta, pública e somente de leitura. Nosso importador a percorre, cem recursos por página, até as páginas acabarem. Um único comando faz isso: <code>drush ombu:import-circle</code>. Só roda uma importação por vez: duas juntas perderiam os anúncios novos uma da outra e criariam duplicatas.</p>

<h2>2. Um recurso vira um anúncio</h2>
<p>Cada recurso é um anúncio, e sua identidade é seu método HTTP mais sua URL. Ficamos só com os pagos pelo esquema <em>exact</em>, o do "esta chamada custa tanto". O preço exibido é a oferta exata mais barata, com a Arc primeiro, porque a Arc é nossa rede; o anúncio mantém todas as redes que aceita. Lemos cada valor como USDC, seis casas decimais, o que hoje vale para todo o catálogo.</p>

<h2>3. O anúncio traz o próprio manual</h2>
<p>Um provedor pode publicar uma descrição OpenAPI da sua API. Quando publica, baixamos uma vez por provedor, não uma por anúncio, com um download protegido: só endereços públicos, cinco megabytes no máximo. Cada anúncio guarda a única operação dessa especificação que o documenta, e assim pessoas e agentes buscam na própria API, não só na sua descrição.</p>

<h2>4. Só mudanças reais contam</h2>
<p>Para cada recurso calculamos um hash do que a Circle diz mais a operação que encontramos. Se for igual ao da vez anterior, nada é tocado. Não confiamos só na data de "última atualização": quem decide é o conteúdo. Categorias e tags viram termos pelos quais se pode filtrar, e cada provedor recebe o ícone do seu site como logo, salvo uma vez do nosso lado, sem link externo.</p>

<h2>5. O que é nosso continua sendo nosso</h2>
<p>Se a Circle lista também um serviço nosso, não o copiamos nem o sobrescrevemos. Só o marcamos como presente também no mercado da Circle. O mesmo vale para um anúncio que um vendedor cadastrou à mão.</p>

<h2>6. O que some é aposentado, nunca apagado</h2>
<p>Quando uma execução completa não encontra mais um anúncio, nós o aposentamos: continua publicado e aberto a avaliações, nunca à venda, nunca apagado. As avaliações são o que os compradores conquistaram, então ficam. Só uma execução completa sabe o que sumiu; um teste rápido com <code>--max</code> não aposenta nada.</p>

<h2>7. O que a equipe apaga fica apagado</h2>
<p>Se tiramos um anúncio do ar por uma denúncia de abuso ou uma ordem judicial, guardamos sua chave e a importação o pula daí em diante. Senão, a próxima execução o traria de volta.</p>

<h2>8. Depois, a busca</h2>
<p>Após a importação o catálogo é indexado, e o <a href="/pt-br/market">mercado</a> o encontra por palavras e por filtro: categoria, rede, tipo de pagamento, vendedor. A mesma busca é uma página JSON e outra em markdown para os agentes.</p>

<p>A Circle é a primeira fonte, não a última. O importador foi escrito para que o próximo catálogo siga as mesmas regras, e contaremos aqui quando chegar.</p>
HTML, 'Todos os serviços do Agent Marketplace da Circle estão no Ombú, ao lado dos nossos, e ninguém os cadastrou à mão. É assim que chegam e estas são as regras que mantemos.'],
];

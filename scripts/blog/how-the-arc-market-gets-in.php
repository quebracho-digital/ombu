<?php

/**
 * @file
 * Second post: why a service is better on Ombú than on a bare catalogue, and
 * how Circle's services get here. Everything it claims is in the code: the
 * facets (CatalogueController), reviews (Rating, ReviewVerifier), the MCP
 * server (McpPayments) and the importer (CircleCatalogImporter).
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'categories' => ['how-it-works'],
  'image' => 'market-hall.jpg',
  'alt' => [
    'en' => 'A municipal market hall full of sacks of grain',
    'es' => 'Un mercado municipal lleno de bolsas de granos',
    'pt-br' => 'Um mercado municipal cheio de sacas de grãos',
  ],
  'date' => '2026-11-06 14:00:00 America/Argentina/Buenos_Aires',
  'en' => ['Why a service is easier to find, judge and buy on Ombú', <<<HTML
<p class="text-body-secondary small">6 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Inside a municipal market hall: sacks of grains and potatoes in blue, and stalls covered with blue tarpaulins."><figcaption class="figure-caption">The municipal market of La Quiaca, Argentina. Ours has about 2,800 stalls, and every one is an API.</figcaption></figure>

<p class="lead">A catalogue tells an agent what exists. A market also has to answer the questions that come before a purchase: is it the right service, who else bought it, can I read it in my language, and can I pay it without a person in the loop. This is what Ombú adds to the same services, including the ones that come from Circle's Agent Marketplace.</p>

<h2>Find it: filters that narrow down</h2>
<p>Every search is faceted: category, network, provider, payment kind, tag and seller. Each facet shows how many services match, and selecting one narrows the rest at once. Within a facet, several values are an "any of" (Arc <em>or</em> Base), and across facets it is an "and". The same filters work on the page, in the JSON catalogue and in the Markdown market, so a person and an agent get the same answer to the same question.</p>

<h2>Judge it: reviews from people who paid</h2>
<p>A review on Ombú comes from a wallet that paid for the service, signed by that wallet. Reviews from anyone else are not counted in the average, and a listing shows its stars and how many reviews back them. Buyers can also comment, so the question "does this work for my case?" gets an answer from someone who tried it.</p>

<h2>Read it: in your language</h2>
<p>The site speaks English, Spanish and Brazilian Portuguese, and listings carry their own descriptions in each language. A service described only in English still shows, with the page in your language and its description in the one that exists, never a broken link.</p>

<h2>Compare it: the same service, each listing with its facts</h2>
<p>Each listing states its price, its networks and the rails that pay it, its provider and its rating, and whether Ombú verified the seller. The price is what the endpoint charges today: the page, the JSON and the Markdown read it from the same place, so they never disagree.</p>

<h2>Trust the money: sales you can check on chain</h2>
<p>The market shows the latest sales settled on chain, each with a link to its transaction. On the networks Ombú settles itself, its sales come from one address that the market shows, so anyone can check that a sale went through Ombú. Retired services stay on the page with their reviews; they are never deleted, and they are never for sale.</p>

<h2>Built for agents, not only for people</h2>
<p>Every page has a machine version. The market is available as JSON, with Circle's Discovery shape, and as Markdown, with its filters as links. Each service has its own page in Markdown, and the OpenAPI description of the endpoints is published too. An agent can also connect to our MCP server to search services, read one and buy a call, paid in USDC on the same rails. Discovery for x402 scanners is at <code>/.well-known/x402</code>.</p>

<h2>Where Circle's services come from</h2>
<p>Circle publishes its catalogue through a public, read-only Discovery API. Our importer walks it a hundred resources at a time and keeps only the exact-price ones, the kind that says "this call costs this much". A resource becomes one listing, identified by its HTTP method and URL. The price shown is the cheapest exact offer, with Arc first, since Arc is our chain. Each listing keeps the single operation of the provider's OpenAPI that documents it, so the API itself can be searched.</p>

<p>The import never overwrites a listing that is ours, and a listing that disappears from the source is retired, not deleted. A listing staff take down stays down: the importer keeps its key and skips it from then on.</p>

<p>Circle is the first source, not the last. The importer was written so the next catalogue follows the same rules, and we will write here when it does.</p>
HTML, 'A catalogue tells an agent what exists. A market also answers what comes before a purchase: the right service, who bought it, in what language, and a way to pay without a person. Here is what Ombú adds to the same services.'],
  'es' => ['Por qué un servicio es más fácil de encontrar, juzgar y comprar en Ombú', <<<HTML
<p class="text-body-secondary small">6 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Interior de un mercado municipal: bolsas de granos y papas azules y puestos cubiertos con lonas azules."><figcaption class="figure-caption">El mercado municipal de La Quiaca, Argentina. El nuestro tiene unos 2.800 puestos, y cada uno es una API.</figcaption></figure>

<p class="lead">Un catálogo dice qué existe. Un mercado tiene que responder lo que viene antes de comprar: si es el servicio correcto, quién lo compró antes, si puedo leerlo en mi idioma y si puedo pagarlo sin una persona de por medio. Esto es lo que Ombú le suma a los mismos servicios, incluidos los que vienen del Agent Marketplace de Circle.</p>

<h2>Encontrarlo: filtros que achican</h2>
<p>Cada búsqueda tiene facetas: categoría, red, proveedor, tipo de pago, etiqueta y vendedor. Cada faceta muestra cuántos servicios coinciden, y al elegir una se achican las demás de una vez. Dentro de una faceta, varios valores son "cualquiera de" (Arc <em>o</em> Base), y entre facetas es "y". Los mismos filtros valen en la página, en el catálogo JSON y en el mercado en Markdown, así que una persona y un agente reciben la misma respuesta a la misma pregunta.</p>

<h2>Juzgarlo: reseñas de quien pagó</h2>
<p>Una reseña en Ombú sale de una billetera que pagó el servicio, firmada por esa billetera. Las reseñas de cualquier otro no cuentan en el promedio, y cada listado muestra sus estrellas y cuántas reseñas las respaldan. Los compradores también pueden comentar, así que la pregunta "¿esto sirve para mi caso?" la responde alguien que lo probó.</p>

<h2>Leerlo: en tu idioma</h2>
<p>El sitio habla inglés, español y portugués de Brasil, y cada listado tiene su descripción en cada idioma. Un servicio descrito solo en inglés igual se muestra: la página sale en tu idioma y la descripción en la que exista, nunca un enlace roto.</p>

<h2>Compararlo: el mismo servicio, con sus datos</h2>
<p>Cada listado dice su precio, sus redes y los medios que lo pagan, su proveedor y su calificación, y si Ombú verificó al vendedor. El precio es lo que el endpoint cobra hoy: la página, el JSON y el Markdown lo leen del mismo lugar, así que nunca se contradicen.</p>

<h2>Confiar en el dinero: ventas que podés verificar en la cadena</h2>
<p>El mercado muestra las últimas ventas liquidadas en la cadena, cada una con un enlace a su transacción. En las redes que Ombú liquida por sí mismo, sus ventas salen de una misma dirección que el mercado muestra, así que cualquiera puede comprobar que una venta pasó por Ombú. Los servicios retirados quedan en la página con sus reseñas: nunca se borran, y nunca están a la venta.</p>

<h2>Hecho para agentes, no solo para personas</h2>
<p>Cada página tiene una versión para máquinas. El mercado está disponible en JSON, con la forma del Discovery de Circle, y en Markdown, con sus filtros como enlaces. Cada servicio tiene su página en Markdown, y también se publica la descripción OpenAPI de sus endpoints. Un agente puede conectarse además a nuestro servidor MCP para buscar servicios, leer uno y comprar una llamada, pagada en USDC por los mismos rieles. La descubribilidad para escáneres de x402 está en <code>/.well-known/x402</code>.</p>

<h2>De dónde salen los servicios de Circle</h2>
<p>Circle publica su catálogo en una API de descubrimiento, pública y de solo lectura. Nuestro importador la recorre de a cien recursos y se queda solo con los de precio exacto, los que dicen "esta llamada cuesta tanto". Un recurso se vuelve un listado, identificado por su método HTTP y su URL. El precio que se muestra es la oferta exacta más barata, con Arc primero, porque Arc es nuestra cadena. Cada listado conserva la única operación de la OpenAPI del proveedor que lo documenta, así se puede buscar en la API misma.</p>

<p>La importación nunca pisa un listado que es nuestro, y un listado que desaparece de la fuente se retira, no se borra. Lo que el equipo baja queda bajado: el importador guarda su clave y lo saltea de ahí en adelante.</p>

<p>Circle es la primera fuente, no la última. El importador se escribió para que el próximo catálogo siga las mismas reglas, y lo contaremos aquí cuando llegue.</p>
HTML, 'Un catálogo dice qué existe. Un mercado responde lo que viene antes de comprar: el servicio correcto, quién lo compró, en qué idioma y cómo pagar sin una persona de por medio. Esto es lo que Ombú le suma a los mismos servicios.'],
  'pt-br' => ['Por que um serviço é mais fácil de achar, avaliar e comprar no Ombú', <<<HTML
<p class="text-body-secondary small">6 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/market-hall.jpg" class="figure-img img-fluid rounded w-100" width="1400" height="1050" alt="Interior de um mercado municipal: sacas de grãos e batatas azuis e bancas cobertas com lonas azuis."><figcaption class="figure-caption">O mercado municipal de La Quiaca, Argentina. O nosso tem cerca de 2.800 bancas, e cada uma é uma API.</figcaption></figure>

<p class="lead">Um catálogo diz o que existe. Um mercado precisa responder o que vem antes da compra: se é o serviço certo, quem já comprou, se posso ler no meu idioma e se posso pagar sem uma pessoa no meio. Isto é o que o Ombú acrescenta aos mesmos serviços, inclusive aos que vêm do Agent Marketplace da Circle.</p>

<h2>Encontrar: filtros que reduzem</h2>
<p>Cada busca tem facetas: categoria, rede, provedor, tipo de pagamento, etiqueta e vendedor. Cada faceta mostra quantos serviços batem, e ao escolher uma as outras se reduzem de uma vez. Dentro de uma faceta, vários valores são "qualquer um de" (Arc <em>ou</em> Base), e entre facetas é "e". Os mesmos filtros valem na página, no catálogo JSON e no mercado em Markdown, então uma pessoa e um agente recebem a mesma resposta para a mesma pergunta.</p>

<h2>Avaliar: avaliações de quem pagou</h2>
<p>Uma avaliação no Ombú vem de uma carteira que pagou o serviço, assinada por essa carteira. Avaliações de qualquer outra pessoa não entram na média, e cada anúncio mostra suas estrelas e quantas avaliações as sustentam. Os compradores também podem comentar, então a pergunta "isto serve para o meu caso?" é respondida por quem já testou.</p>

<h2>Ler: no seu idioma</h2>
<p>O site fala inglês, espanhol e português do Brasil, e cada anúncio tem sua descrição em cada idioma. Um serviço descrito só em inglês continua aparecendo: a página sai no seu idioma e a descrição no que existir, nunca um link quebrado.</p>

<h2>Comparar: o mesmo serviço, com seus dados</h2>
<p>Cada anúncio informa seu preço, suas redes e os meios que o pagam, seu provedor e sua avaliação, e se o Ombú verificou o vendedor. O preço é o que o endpoint cobra hoje: a página, o JSON e o Markdown leem do mesmo lugar, então nunca se contradizem.</p>

<h2>Confiar no dinheiro: vendas que você pode conferir na cadeia</h2>
<p>O mercado mostra as últimas vendas liquidadas na cadeia, cada uma com um link para sua transação. Nas redes que o Ombú liquida por conta própria, suas vendas saem de um mesmo endereço que o mercado mostra, então qualquer pessoa pode conferir que uma venda passou pelo Ombú. Os serviços aposentados ficam na página com suas avaliações: nunca são apagados, e nunca estão à venda.</p>

<h2>Feito para agentes, não só para pessoas</h2>
<p>Cada página tem uma versão para máquinas. O mercado está disponível em JSON, com o formato do Discovery da Circle, e em Markdown, com os filtros como links. Cada serviço tem sua página em Markdown, e a descrição OpenAPI dos endpoints também é publicada. Um agente pode ainda conectar-se ao nosso servidor MCP para buscar serviços, ler um e comprar uma chamada, paga em USDC pelos mesmos trilhos. A descoberta para os escaneres de x402 está em <code>/.well-known/x402</code>.</p>

<h2>De onde vêm os serviços da Circle</h2>
<p>A Circle publica seu catálogo numa API de descoberta, pública e somente de leitura. Nosso importador a percorre cem recursos por vez e fica só com os de preço exato, os que dizem "esta chamada custa tanto". Um recurso vira um anúncio, identificado pelo seu método HTTP e pela URL. O preço exibido é a oferta exata mais barata, com a Arc primeiro, porque a Arc é nossa rede. Cada anúncio guarda a única operação da OpenAPI do provedor que o documenta, então a própria API pode ser buscada.</p>

<p>A importação nunca sobrescreve um anúncio que é nosso, e um anúncio que some da fonte é aposentado, não apagado. O que a equipe tira do ar continua fora: o importador guarda sua chave e o pula daí em diante.</p>

<p>A Circle é a primeira fonte, não a última. O importador foi escrito para que o próximo catálogo siga as mesmas regras, e contaremos aqui quando chegar.</p>
HTML, 'Um catálogo diz o que existe. Um mercado responde o que vem antes da compra: o serviço certo, quem comprou, em que idioma e como pagar sem uma pessoa no meio. Isto é o que o Ombú acrescenta aos mesmos serviços.'],
];

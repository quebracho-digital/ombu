<?php

/**
 * @file
 * Brazilian Portuguese (ombu_update_10026): the guide, About, the terms and
 * privacy, the FAQ, the contact forms, the menus and My listings. Adds the
 * pt-br version of what seed-pages, seed-legal and seed-support made, once:
 * a translation that exists is left as it is, so edits on the site survive
 * (OMBU_SEED_FORCE=1 overwrites the guide and About anyway, never the legal
 * pages).
 *
 * The legal pages need who answers for them, as in seed-legal.php; without
 * it they are skipped:
 *   ddev exec env OMBU_LEGAL_NAME='…' OMBU_LEGAL_EMAIL='…' \
 *     OMBU_LEGAL_ADDRESS='…' drush php:script ../scripts/seed-pt-br.php
 *
 * Not legal advice: a lawyer reads the Portuguese terms before they matter
 * in Brazil (consumer code, LGPD).
 */

$langcode = 'pt-br';
if (!\Drupal::languageManager()->getLanguage($langcode)) {
  throw new \RuntimeException('No pt-br on this site: drush updb first (ombu_update_10026).');
}
$site = 'https://ombu.quebracho.digital';
$force = (bool) getenv('OMBU_SEED_FORCE');
$nodes = \Drupal::entityTypeManager()->getStorage('node');
$links = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$overrides = \Drupal::languageManager();

// Every menu link to $uri (main or footer) gets its pt-br title, once.
$menu = function (string $uri, string $title) use ($links, $langcode): void {
  foreach ($links->loadByProperties(['link.uri' => $uri]) as $link) {
    if (!$link->hasTranslation($langcode)) {
      $link->addTranslation($langcode, ['title' => $title])->save();
    }
  }
};
// The pt-br version of the page at the English $alias, and its menu links.
$page = function (string $alias, string $title, string $body, bool $overwrite) use ($nodes, $langcode, $menu): void {
  $path = \Drupal::service('path_alias.repository')->lookupByAlias($alias, 'en');
  if (!$path) {
    echo "$alias: not on this site, run its seed script first\n";
    return;
  }
  $node = $nodes->load((int) basename($path['path']));
  if ($node->hasTranslation($langcode) && !$overwrite) {
    echo "$alias:$langcode exists: left as it is\n";
  }
  else {
    $t = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node->addTranslation($langcode, ['path' => ['alias' => $alias]]);
    $t->set('title', $title)->set('body', ['value' => $body, 'format' => 'full_html'])->setPublished()->save();
    echo "$alias:$langcode → node/{$node->id()}\n";
  }
  $menu('entity:node/' . $node->id(), $title);
};
// A config translation, only where pt-br has none yet.
$config = function (string $name, array $values) use ($overrides, $langcode): void {
  if (!\Drupal::config($name)->get()) {
    return;
  }
  $override = $overrides->getLanguageConfigOverride($langcode, $name);
  if (!$override->isNew()) {
    echo "$name:$langcode exists: left as it is\n";
    return;
  }
  foreach ($values as $key => $value) {
    $override->set($key, $value);
  }
  $override->save();
  echo "$name:$langcode\n";
};

$page('/how-it-works', 'Como funciona o Ombú', <<<HTML
<p><strong>O Ombú é um mercado onde agentes de IA vendem serviços para pessoas e para outros agentes.</strong> Todo o catálogo do Agent Marketplace da Circle está aqui, ao lado dos nossos próprios vendedores, e se busca do mesmo jeito pelo navegador ou por código. Os pagamentos são em USDC na <a href="https://www.arc.io/">Arc</a>, por chamada, com <a href="https://docs.x402.org/">x402</a>.</p>

<h2>Encontrar um serviço</h2>
<p>O <a href="/pt-br/market">mercado</a> lista cada serviço com seu preço. Refine a busca com os filtros: cada mudança atualiza a lista na hora, e o endereço no navegador guarda a busca, então você pode compartilhá-la.</p>
<ul>
  <li><strong>Categoria</strong>: o que o serviço faz (inteligência social, pesquisa na web, análise financeira…).</li>
  <li><strong>Rede</strong>: a blockchain em que se paga. A Arc é a nossa.</li>
  <li><strong>Pagamento</strong>: <em>x402 direto</em> funciona com qualquer carteira; <em>Circle Gateway</em> precisa de saldo no Gateway.</li>
  <li><strong>Vendedor</strong>: <em>Quebracho</em> para os nossos, <em>De terceiros</em> para quem publicou o próprio serviço, <em>Circle Agent Marketplace</em> para o resto.</li>
</ul>

<h2>Comprar</h2>
<p>Não há carrinho nem conta para abrir. Um serviço se compra chamando-o: ele responde <em>402 Payment Required</em> com seu preço, a carteira de quem compra assina um pagamento em USDC e a chamada é concluída. Os agentes fazem isso sozinhos; <a href="/pt-br/docs/agents">o guia para agentes</a> mostra como. As pessoas experimentam nossos serviços na página deles: <em>Try it</em>, uma assinatura na carteira, sem gas, e o resultado aparece com sua transação.</p>
<p>Nossos vendedores só recebem quando entregam: se uma chamada falha, o pagamento nunca é liquidado e não custa nada. Cada venda é pública e leva à blockchain: <a href="/pt-br/market/sales">todas as vendas</a>.</p>

<h2>Confiar no que você lê</h2>
<p>Só avalia quem comprou, e só o que pagou: cada avaliação leva a transação da compra, e o site a verifica na blockchain antes de publicá-la. Depois de publicada, ninguém pode editá-la, nem nós. O selo <em>Vendedor verificado</em> marca os vendedores cuja carteira conhecemos.</p>
<p>O vendedor pode responder cada avaliação, uma vez, logo abaixo dela. Se quem comprou quiser responder de volta, compra de novo e deixa uma nova avaliação: conta se a correção funcionou, entra na nota e, como toda avaliação, é respaldada por uma compra.</p>
<p>Cada vendedor tem sua página, com link no nome: sua reputação em todos os seus anúncios, a carteira para a qual se paga, seus anúncios e suas últimas vendas.</p>

<h2>Entrar com sua carteira</h2>
<p>Sem e-mail e sem senha: <em>Entrar com uma carteira</em>, na página de login, abre sua conta com uma assinatura do MetaMask ou de qualquer carteira do navegador. Os agentes assinam a mesma mensagem por código.</p>

<h2>No seu idioma</h2>
<p>O Ombú fala inglês, espanhol e português, e adicionar um idioma é uma configuração, não código novo. Os vendedores traduzem os próprios anúncios, assim cada comprador os lê no seu idioma, enquanto preços, endereços de pagamento e URLs continuam iguais em todos. Os agentes continuam usando os endpoints em inglês.</p>

<h2>A seguir</h2>
<ul>
  <li><a href="/pt-br/docs/agents">Para agentes</a>: buscar, pagar e avaliar por código.</li>
  <li><a href="/pt-br/docs/sellers">Para vendedores</a>: como é vender no Ombú.</li>
  <li><a href="/pt-br/about">Sobre o Ombú</a>: quem faz, e por quê.</li>
</ul>
HTML, $force);

$page('/docs/agents', 'Para agentes', <<<HTML
<p>Tudo o que uma pessoa faz no Ombú, um agente pode fazer por HTTP: buscar no catálogo, pagar uma chamada e avaliar o que comprou. Sem API key e sem conta: a carteira é a identidade e o pagamento.</p>

<h2>1. Encontrar o mercado</h2>
<ul>
  <li><a href="/llms.txt"><code>/llms.txt</code></a>: o mercado explicado para modelos de linguagem, com links.</li>
  <li><a href="/openapi.json"><code>/openapi.json</code></a>: a busca e nossos vendedores como operações, com sua entrada.</li>
  <li><a href="/market.json"><code>/market.json</code></a>: o catálogo, no formato da Discovery API da Circle.</li>
  <li>As páginas de conteúdo (estes guias, os termos) em markdown: envie <code>Accept: text/markdown</code> para a URL delas. Os anúncios se leem em <code>/market.json</code>; o que está à venda responde <code>402</code> em cada rota que o mostra.</li>
</ul>
<p>Estes documentos estão em inglês, o idioma que os agentes esperam.</p>

<h2>2. Buscar</h2>
<p><code>/market.json</code> aceita os mesmos filtros que a <a href="/pt-br/market">página do mercado</a>, cada um com vários valores separados por vírgulas: <code>search</code>, <code>category</code>, <code>network</code> (CAIP-2: <code>eip155:5042</code> é a Arc), <code>provider</code>, <code>payment</code> (<code>vanilla</code> ou <code>gateway</code>), <code>tag</code> e <code>seller</code> (<code>ours</code>, <code>third_party</code> ou <code>circle</code>). Cada valor de faceta traz sua contagem e a URL que o adiciona.</p>
<pre><code>curl -s '{$site}/market.json?seller=ours'</code></pre>

<h2>3. Pagar uma chamada (x402)</h2>
<ol>
  <li>Chame a URL do recurso do anúncio, como diz sua entrada no catálogo (a maioria espera um <code>POST</code> com entrada JSON).</li>
  <li>A resposta é <code>402</code>, com o preço no cabeçalho <code>PAYMENT-REQUIRED</code> (JSON em base64): rede, valor, token e <code>payTo</code>.</li>
  <li>Assine um <code>TransferWithAuthorization</code> EIP-3009 exatamente para isso: da sua carteira, para <code>payTo</code>, esse valor, com um nonce novo. O domínio EIP-712 é o do token (<code>extra.name</code>, <code>extra.version</code>, o id da rede e o endereço do token).</li>
  <li>Repita a mesma requisição com o pagamento assinado no cabeçalho <code>PAYMENT-SIGNATURE</code> (JSON em base64).</li>
  <li>A resposta é a do serviço, e <code>PAYMENT-RESPONSE</code> traz o hash da transação.</li>
</ol>
<p>Um comprador completo, em PHP e sem npm: <a href="https://github.com/quebracho-digital/ombu/blob/main/scripts/lib/buyer.php"><code>buyer.php</code></a> (paga, entra, avalia), e nosso <a href="https://github.com/quebracho-digital/ombu/blob/main/scripts/agent.php">agente comprador</a> por cima.</p>
<p>Nossos vendedores só liquidam depois de responder com sucesso: se a chamada falha, seu dinheiro não sai do lugar.</p>

<h2>4. Avaliar o que você comprou</h2>
<ol>
  <li>Entre com sua carteira (Sign-In With Ethereum): <code>GET /wallet/nonce</code>, assine a mensagem, <code>POST /wallet/login</code> com <code>{"message", "signature"}</code>. Guarde o cookie de sessão.</li>
  <li><code>GET /session/token</code> para obter um token CSRF.</li>
  <li><code>POST /market/listing/{id}/reviews</code> com o cabeçalho <code>X-CSRF-Token</code> e <code>{"rating": 1-5, "body": "…", "tx": "0x…"}</code>.</li>
</ol>
<p>A transação tem que comprar esse anúncio a partir da sua carteira, e é verificada na blockchain: para nossos vendedores, uma venda desse anúncio pelo Ombú; para os demais, uma transferência em USDC ao vendedor de pelo menos o seu preço. Uma compra, uma avaliação.</p>
<p>Login e avaliação por código: o mesmo <a href="https://github.com/quebracho-digital/ombu/blob/main/scripts/lib/buyer.php"><code>buyer.php</code></a>.</p>
HTML, $force);

$page('/docs/sellers', 'Para vendedores', <<<HTML
<p>O Ombú lista serviços que respondem por x402: um agente os chama, paga por chamada e recebe o resultado. Isto é o que significa vender no Ombú hoje.</p>

<h2>O que os vendedores ganham</h2>
<ul>
  <li><strong>Quem compra paga direto a você.</strong> Cada venda vai da carteira de quem compra para a sua, na blockchain. O Ombú nunca retém seu dinheiro.</li>
  <li><strong>Sem comissão, por enquanto.</strong> Anunciar é grátis, e os compradores pagam direto ao seu endpoint.</li>
  <li><strong>Encontrado por pessoas e por agentes.</strong> Seu anúncio é uma página para pessoas, uma entrada em <code>/market.json</code> e <code>/openapi.json</code> para agentes, e aparece em <code>/llms.txt</code>.</li>
  <li><strong>Avaliações confiáveis.</strong> Só quem pagou pode avaliar, e você pode responder.</li>
  <li><strong>Sua própria página.</strong> Ao entrar com sua carteira você ganha uma página pública de vendedor: sua reputação em todos os seus anúncios, seus anúncios e suas vendas. Adicione uma foto e um <em>Sobre</em> em Editar, na sua conta.</li>
  <li><strong>Uma carteira fria basta.</strong> Um vendedor pode ser só um endereço: o site não precisa de nenhuma chave sua.</li>
  <li><strong>Seu anúncio em cada idioma.</strong> Traduza você mesmo o título e a descrição; cada comprador o lê no seu.</li>
</ul>

<h2>Já está no Agent Marketplace da Circle?</h2>
<p>Então você já está aqui: o Ombú importa esse catálogo, e seu anúncio aparece com categoria, preço e forma de pagamento. Entre com a carteira para a qual seu anúncio é pago e ele é seu para completar: adicione uma nota, imagens, vídeos e arquivos, responda avaliações e traduza-o para o português ou o espanhol, algo que o marketplace da Circle não oferece. Os dados da Circle ficam sincronizados: o que você adiciona nunca é sobrescrito.</p>
<p>Se o mesmo serviço está aqui e em outro mercado, isso aparece com o selo <em>Também em</em>, em vez de ele aparecer duas vezes.</p>

<h2>Publicar seu próprio serviço</h2>
<p>Já vende por x402? Entre com a carteira para a qual seu endpoint recebe e preencha <a href="/pt-br/market/sell">um formulário</a>: o endpoint, o que ele faz e seu nome. O Ombú pede ao seu endpoint o 402 dele: o preço vem daí, e a carteira para a qual ele paga prova que o serviço é seu, então não há cadastro. Ele entra no ar quando o aprovamos, e a partir daí é seu para completar como qualquer outro, em <a href="/pt-br/my-listings">Meus anúncios</a>.</p>

<h2>Seu próprio mercado</h2>
<p>Tudo o que o Ombú usa é código aberto e está no drupal.org: o <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> cobra por qualquer página ou API do Drupal, e o <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a> permite que pessoas e agentes entrem com uma carteira. O mercado em si está <a href="https://github.com/quebracho-digital/ombu">no GitHub</a>.</p>
HTML, $force);

$page('/about', 'Sobre', <<<HTML
<p>O ombu é a árvore dos pampas: uma só dá sombra por léguas ao redor, e os viajantes se encontram debaixo da sua copa. <strong>O Ombú é um mercado onde os agentes de IA se encontram com as pessoas e os agentes que precisam deles.</strong></p>

<h2>Veja funcionando</h2>
<p>Dois minutos e meio, feitos para o Tameion Agents Hackathon: um agente com cinco centavos e uma pergunta percorre o mercado, paga por chamada e obtém sua resposta. Em inglês, com legendas.</p>
<div class="ratio ratio-16x9 mb-4"><iframe src="https://www.youtube-nocookie.com/embed/zUcBXuzYEaI?cc_load_policy=1" title="Ombú: um agente compra no mercado" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div>

<h2>Por quê</h2>
<p>Os agentes já podem pagar pelo que usam, centavo a centavo, com x402 e USDC. O que falta é um lugar para se encontrarem, aberto a qualquer um: hoje, publicar um serviço nos grandes mercados de agentes significa preencher um formulário e esperar uma revisão. No Ombú um vendedor publica com um formulário, seu 402 como preço e sua carteira como prova; mostra a pessoas e agentes o mesmo mercado, e fala o idioma de quem compra: hoje inglês, espanhol e português, e virão mais.</p>

<h2>Como é feito</h2>
<ul>
  <li>Sobre <a href="https://www.drupal.org/">Drupal</a> e Drupal Commerce, com módulos de código aberto que qualquer um pode reutilizar: <a href="https://www.drupal.org/project/x402_pay">x402 Pay</a> e <a href="https://www.drupal.org/project/wallet_auth">Wallet Auth</a>.</li>
  <li>Pagamentos em USDC na <a href="https://www.arc.io/">Arc</a>, liquidados pelo próprio site: sem intermediários entre comprador e vendedor.</li>
  <li>Nossos vendedores rodam em modelos que nós mesmos hospedamos: checam fatos, leem termos de serviço e transformam páginas web em texto.</li>
</ul>

<h2>Quem</h2>
<p>O Ombú é feito pela <a href="https://quebracho.digital">Quebracho</a>, em Buenos Aires. O código está <a href="https://github.com/quebracho-digital/ombu">no GitHub</a>.</p>
HTML, $force);

$faq = <<<'HTML'
<h2>Comprar</h2>
<h3>Preciso de uma conta para comprar?</h3>
<p>Não. Um serviço se compra chamando-o: ele responde com seu preço, sua carteira assina um pagamento em USDC na Arc e a chamada segue. A conta (uma assinatura da carteira, sem senha) só é necessária para avaliar o que você comprou ou para editar um anúncio que você vende.</p>
<h3>Quanto custa?</h3>
<p>O preço do anúncio, em USDC. Quem compra não paga comissão, e nos nossos vendedores o Ombú paga a taxa da rede.</p>
<h3>E se uma chamada falhar?</h3>
<p>Nossos vendedores só recebem quando entregam: uma chamada que falha, ou que leva mais de 95 segundos, não é cobrada. Os anúncios do Agent Marketplace da Circle são vendidos pelos próprios vendedores, com as próprias regras: o Ombú os indexa.</p>
<h3>Posso receber meu dinheiro de volta?</h3>
<p>Nos nossos serviços, pelo <a href="/pt-br/contact/withdrawal">formulário de cancelamento</a>: o valor volta para a carteira que pagou. Em qualquer outro anúncio, peça ao vendedor.</p>

<h2>Carteiras</h2>
<h3>Que carteira posso usar?</h3>
<p>MetaMask ou qualquer carteira do navegador; os agentes assinam a mesma mensagem por código (veja <a href="/pt-br/docs/agents">Para agentes</a>). Um endereço é o mesmo em todas as redes EVM (Arc, Base, Ethereum…), então a rede que sua carteira mostra ao entrar não importa. Os pagamentos são na Arc.</p>
<h3>Uma conta pode ter várias carteiras?</h3>
<p>Sim. Na página da sua conta, <em>Vincular uma carteira</em> abre sua carteira para escolher outra conta e pede uma assinatura: não custa nada e não envia nenhuma transação. Uma carteira pertence a uma única conta, e você pode dar um nome a cada uma.</p>
<h3>Como removo uma carteira?</h3>
<p>Por enquanto, pelo <a href="/pt-br/contact/support">suporte</a>. Remover uma carteira muda quem pode vender um anúncio e quem pode avaliar uma compra, por isso verificamos quem pede antes de fazer. Escreva a partir da conta e diga qual carteira.</p>
<h3>Perdi o acesso à minha conta.</h3>
<p>Uma conta aberta com uma carteira não tem senha: qualquer carteira vinculada a ela permite entrar. Se você não tiver nenhuma, escreva para o <a href="/pt-br/contact/support">suporte</a>.</p>

<h2>Vender</h2>
<h3>Meu serviço está no Agent Marketplace da Circle. Ele está aqui?</h3>
<p>Sim: o Ombú importa esse catálogo. Entre com a carteira para a qual seu anúncio é pago e <a href="/pt-br/my-listings">Meus anúncios</a> o mostra. Edite-o para adicionar uma nota, imagens, vídeos e arquivos, traduzi-lo e responder avaliações. Os dados da Circle ficam sincronizados e o que você adiciona nunca é sobrescrito.</p>
<h3>Posso anunciar um serviço novo?</h3>
<p>Sim: veja <a href="/pt-br/docs/sellers">Para vendedores</a>.</p>

<h2>Avaliações e denúncias</h2>
<h3>Quem pode avaliar?</h3>
<p>Só quem comprou, e só o que pagou: a avaliação leva a transação da compra, verificada na blockchain antes de ser publicada. Uma compra, uma avaliação.</p>
<h3>Como denuncio um anúncio, uma avaliação ou um vendedor?</h3>
<p>Com o <a href="/pt-br/contact/report">formulário de denúncias</a>, a partir de qualquer anúncio ou diretamente.</p>

<h2>Outra coisa</h2>
<p>Escreva para o <a href="/pt-br/contact/support">suporte</a>.</p>
HTML;
$page('/faq', 'Perguntas frequentes', $faq, FALSE);
$menu('internal:/contact/support', 'Suporte');

// The forms' labels and what is shown after sending.
$config('contact.form.report', ['label' => 'Denunciar abuso', 'message' => 'Recebemos sua denúncia e vamos analisá-la. Se precisarmos de mais informações, escreveremos para o endereço que você informou.']);
$config('contact.form.withdrawal', ['label' => 'Cancelar uma compra', 'message' => 'Recebemos seu pedido. Em até 24 horas escreveremos para você com o número do protocolo, e devolveremos o valor pago à carteira que pagou.']);
$config('contact.form.support', ['label' => 'Suporte', 'message' => 'Recebemos sua mensagem e responderemos no endereço que você informou.']);
$menu('internal:/contact/report', 'Denunciar abuso');
$menu('internal:/contact/withdrawal', 'Cancelar uma compra');

$config('views.view.my_listings', [
  'label' => 'Meus anúncios',
  'display.default.display_options.title' => 'Meus anúncios',
  'display.default.display_options.fields.title.label' => 'Anúncio',
  'display.default.display_options.fields.field_source.label' => 'Origem',
  'display.default.display_options.fields.changed.label' => 'Atualizado',
  'display.default.display_options.header.area_text_custom.content' => 'Os anúncios que pagam a uma das suas carteiras. Edite um para adicionar uma nota, imagens, vídeos e arquivos, ou para traduzi-lo; os dados da Circle ficam sincronizados.',
  'display.default.display_options.empty.area_text_custom.content' => 'Ainda nenhum anúncio paga a uma carteira sua. Entre com a carteira para a qual seu anúncio é pago, ou vincule-a: veja <a href="/pt-br/docs/sellers">Para vendedores</a>.',
  'display.page_1.display_options.menu.title' => 'Meus anúncios',
]);

// The legal pages, with who answers for them (seed-legal.php).
$name = getenv('OMBU_LEGAL_NAME');
$email = getenv('OMBU_LEGAL_EMAIL');
$address = getenv('OMBU_LEGAL_ADDRESS');
if (!$name || !$email || !$address || !\Drupal::service('email.validator')->isValid($email)) {
  echo "/terms, /privacy: skipped, set OMBU_LEGAL_NAME, OMBU_LEGAL_EMAIL and OMBU_LEGAL_ADDRESS\n";
  return;
}
$name = htmlspecialchars($name);
$address = htmlspecialchars($address);
$mail = '<a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>';
$date = '13 de outubro de 2026';
// The privacy policy changed on its own: the similar services log.
$privacy = '23 de outubro de 2026';

$page('/terms', 'Termos de uso', <<<HTML
<p><em>Em vigor desde {$date}. Esta página também está em espanhol e em inglês; se as versões divergirem, prevalece a versão em espanhol.</em></p>
<p><strong>O Ombú é uma demo.</strong> Foi construído para um hackathon e ainda está em teste: pode mudar, falhar ou parar a qualquer momento, e estes termos podem mudar antes da abertura definitiva. Use valores pequenos.</p>

<h2>1. Quem somos e o que estes termos cobrem</h2>
<p>O Ombú (<a href="https://ombu.quebracho.digital">ombu.quebracho.digital</a>, “Ombú”, “nós”) é operado por {$name}, {$address} (o “Operador”). Estes termos regem o uso do site, de suas páginas, de sua API e de seus documentos para máquinas (<code>/market.json</code>, <code>/openapi.json</code>, <code>/llms.txt</code>) por qualquer pessoa ou agente de software (o “Usuário”). Usar o Ombú implica aceitá-los. Se você usa o Ombú por meio de um agente, a pessoa ou organização em nome da qual o agente atua os aceita e responde pelo que ele faz.</p>
<p>O Ombú é para pessoas maiores de idade com plena capacidade civil. Não o use se você tem menos de 18 anos ou se ainda não atingiu a maioridade onde vive.</p>

<h2>2. Três tipos de anúncios, três tipos de responsabilidade</h2>
<p>O Ombú mostra serviços oferecidos por diferentes partes. Cada anúncio indica quem é o vendedor. A diferença importa:</p>
<ol>
  <li><strong>Serviços vendidos pelo Ombú.</strong> Os anúncios marcados como vendidos pela <em>Quebracho</em> (hoje: o carimbo de tempo assinado, página web para markdown, análise de termos e privacidade, e checagem de fatos). O Operador é o vendedor desses serviços e responde por eles conforme estes termos e a lei (seção 4).</li>
  <li><strong>Anúncios importados de catálogos de terceiros.</strong> O Ombú reproduz, como índice, catálogos públicos de serviços oferecidos por outros, como o Agent Marketplace da Circle. Título, descrição, preço, endereço de pagamento e demais dados vêm desse catálogo e do provedor, tal como foram publicados. <strong>O Operador não vende, presta, opera, verifica, endossa nem garante esses serviços</strong>, não é parte de nenhum contrato entre o Usuário e o provedor, e não recebe, retém nem transmite o dinheiro pago a eles.</li>
  <li><strong>Conteúdo publicado por vendedores e usuários.</strong> Os vendedores podem adicionar notas, imagens, vídeos e arquivos aos seus anúncios e responder avaliações; os compradores podem escrever avaliações; no futuro, os vendedores publicarão seus próprios anúncios. <strong>Quem publica um conteúdo é o único responsável por ele</strong> e pelo serviço que descreve. O Operador apenas o hospeda, não o revisa antes da publicação e não o adota como seu.</li>
</ol>
<p>O selo <em>Vendedor verificado</em> só significa que conhecemos a carteira que recebe os pagamentos desse vendedor. Não é uma garantia do serviço, de sua qualidade nem de sua legalidade.</p>

<h2>3. Comprar de terceiros</h2>
<p>Uma compra de um vendedor terceiro é um contrato exclusivamente entre o Usuário e esse vendedor. O Usuário paga diretamente ao vendedor, na blockchain, a partir de sua própria carteira. Entrega, qualidade, legalidade, reembolsos, notas fiscais, impostos e qualquer reclamação sobre esse serviço são questões entre o Usuário e o vendedor. Quando um serviço de terceiros é chamado pela porta de pagamento do Ombú, o Ombú pode transmitir à rede a autorização de pagamento que o Usuário assinou e pagar a taxa da rede, sem nunca reter os fundos; isso não torna o Operador parte da venda. Leia os termos de cada vendedor antes de comprar.</p>

<h2>4. Serviços vendidos pelo Ombú</h2>
<ul>
  <li><strong>Preço e pagamento.</strong> O preço é o que o serviço indica ao ser chamado, em USDC. O pagamento só é liquidado se o serviço respondeu com sucesso; se falhar, o pagamento não é liquidado e não custa nada.</li>
  <li><strong>O que entregam.</strong> Estes serviços usam modelos de linguagem e processamento automatizado. Seus resultados podem ser incompletos ou errados, e são informativos: <strong>não constituem aconselhamento jurídico, financeiro, médico nem profissional de nenhum tipo</strong>, nem um parecer pericial. Uma checagem marcada <em>só modelo</em> não consultou fontes externas. Verifique o que for importante antes de confiar nisso.</li>
  <li><strong>O que você envia.</strong> Você só pode enviar conteúdo que tenha o direito de enviar. Não envie dados pessoais de outras pessoas nem informações confidenciais, salvo se tiver base legal para isso.</li>
  <li><strong>Arrependimento.</strong> Você pode cancelar a compra de um serviço vendido pelo Ombú, sem dar motivo, em até 10 dias corridos a partir da compra, ou no prazo maior que a lei aplicável a você conceder, pelo <a href="/pt-br/contact/withdrawal">formulário de cancelamento</a> ou escrevendo para {$mail}. Inclua a transação do pagamento. Confirmamos o pedido com um número de protocolo em até 24 horas e devolvemos o valor pago, no mesmo token e na mesma rede, à carteira que pagou. Pagamentos na blockchain não podem ser revertidos: o reembolso é uma nova transferência, feita manualmente.</li>
  <li><strong>Consumidores.</strong> Se você é consumidor segundo a lei aplicável a você (na Argentina, a Lei 24.240 de Defesa do Consumidor), nada nestes termos limita os direitos que essa lei lhe reconhece e que não podem ser renunciados.</li>
</ul>

<h2>5. Carteiras, contas e pagamentos</h2>
<ul>
  <li>O login é feito com a assinatura de uma carteira. Você é responsável pela sua carteira e pelas suas chaves. <strong>Nunca vamos pedir sua chave privada nem sua frase-semente.</strong></li>
  <li>Os pagamentos na blockchain são irreversíveis. Um pagamento enviado a um endereço errado ou com um valor errado não pode ser revertido por nós.</li>
  <li>O Ombú não custodia fundos dos Usuários, não faz câmbio de moedas nem de criptoativos, não concede crédito e não presta aconselhamento de investimento. O USDC é emitido por um terceiro; seu valor, disponibilidade e regras não dependem de nós. O Ombú não é afiliado à Circle, à Arc nem a nenhuma rede ou token que liste, salvo se disser isso expressamente.</li>
  <li>Você declara que não está sujeito a sanções que o proíbam de usar um serviço como este, e que cumpre as leis do seu país ao usar o Ombú.</li>
</ul>

<h2>6. O que não é permitido</h2>
<p>Ninguém pode, no Ombú nem por meio dele, publicar, oferecer, anunciar, comprar nem fazer nada do seguinte:</p>
<ul>
  <li>nada ilegal na Argentina ou no país do Usuário, do vendedor ou do comprador, nem que ajude alguém a violar a lei;</li>
  <li>conteúdo que infrinja direitos autorais, marcas, patentes, segredos comerciais ou direitos de outras pessoas sobre sua imagem, sua privacidade ou seus dados pessoais;</li>
  <li>fraudes, golpes, phishing, falsidade ideológica, anúncios enganosos ou serviços que não entregam o que descrevem;</li>
  <li>software malicioso, ataques a sistemas, acessos não autorizados ou serviços pensados para qualquer um deles;</li>
  <li>conteúdo sexual envolvendo menores, conteúdo que promova a violência ou o terrorismo, discurso de ódio, assédio ou ameaças;</li>
  <li>lavagem de dinheiro, financiamento do terrorismo ou a evasão de sanções ou controles;</li>
  <li>avaliações falsas, avaliações de algo que não foi comprado ou a manipulação de notas, rankings ou buscas;</li>
  <li>usar o Ombú de um modo que o sobrecarregue, viole sua segurança ou contorne seus limites ou suas cobranças.</li>
</ul>

<h2>7. Denunciar abusos e remover conteúdo</h2>
<p>Qualquer pessoa, com ou sem conta, pode denunciar um anúncio, uma avaliação, um vendedor, uma conta ou qualquer conteúdo que viole estes termos ou a lei, pelo <a href="/pt-br/contact/report">formulário de denúncias</a> ou escrevendo para {$mail}. Uma denúncia útil inclui o link para o que é denunciado, o motivo pelo qual deveria ser removido e, se forem direitos próprios (por exemplo, direitos autorais), que você é o titular ou age em nome dele.</p>
<p>O Operador não tem uma obrigação geral de monitorar o que terceiros publicam, mas quando toma conhecimento de conteúdo ilícito ou que viole estes termos age com diligência. A seu critério, e sem prejuízo de outras medidas, pode:</p>
<ul>
  <li>ocultar, despublicar ou <strong>excluir definitivamente</strong> qualquer anúncio, importado ou não, e impedir que volte a ser importado;</li>
  <li>excluir avaliações, respostas, notas, imagens, vídeos e arquivos;</li>
  <li>suspender, bloquear ou <strong>excluir definitivamente</strong> contas, e negar o serviço às carteiras associadas a elas;</li>
  <li>conservar as informações necessárias para responder a uma reclamação ou a uma requisição de uma autoridade, e compartilhá-las com a autoridade quando a lei exigir.</li>
</ul>
<p>Um anúncio que o vendedor retira, ou que desaparece do catálogo de onde foi importado, não é excluído: continua no Ombú marcado como não mais oferecido, não pode mais ser comprado e seus compradores verificados ainda podem avaliá-lo. Só é excluído o conteúdo removido conforme esta seção. Retirar um anúncio não exclui suas avaliações.</p>
<p>Quando for possível, e se isso não puser ninguém em risco nem obstruir uma investigação, informamos à pessoa afetada o que foi removido e por quê; ela pode responder para {$mail} se achar que foi um erro. Remover um anúncio do Ombú não remove o serviço da internet nem de outros catálogos: isso deve ser pedido ao provedor. Os pagamentos já feitos na blockchain não podem ser revertidos por nós.</p>

<h2>8. Propriedade intelectual</h2>
<p>O código do Ombú é de código aberto, sob as licenças indicadas em cada repositório. O nome e as marcas Ombú e Quebracho pertencem ao Operador. Os catálogos importados e os nomes, marcas e descrições de terceiros pertencem aos seus titulares e são mostrados apenas para identificar seus serviços. Ao publicar conteúdo no Ombú você mantém seus direitos sobre ele e concede ao Operador uma licença gratuita, não exclusiva e mundial para hospedá-lo, reproduzi-lo, traduzi-lo, adaptá-lo para exibição e mostrá-lo no Ombú e em seus documentos para máquinas, enquanto estiver publicado e pelo tempo necessário para manter registros; você declara que tem o direito de concedê-la.</p>

<h2>9. Disponibilidade e responsabilidade</h2>
<p>O Ombú é oferecido no estado em que se encontra e conforme sua disponibilidade; pode mudar, ter erros ou ser interrompido. Na medida em que a lei permitir, o Operador não responde por: serviços, conteúdos ou condutas de terceiros (seções 2 e 3); perdas causadas por redes blockchain, carteiras, tokens ou seus emissores; o uso sem verificação de resultados de serviços automatizados; nem por danos indiretos ou lucros cessantes. Pelos serviços que vende, a responsabilidade do Operador se limita, na medida em que a lei permitir, ao preço pago pela chamada que causou o dano. Nada disso limita a responsabilidade por dolo ou culpa grave, nem os direitos dos consumidores.</p>
<p>Quem publica conteúdo ou oferece um serviço no Ombú manterá o Operador indene de reclamações de terceiros causadas por esse conteúdo ou serviço, ou pelo descumprimento destes termos ou da lei.</p>

<h2>10. Alterações e fim do uso</h2>
<p>Podemos alterar estes termos; a data no início indica quando. As alterações valem a partir da publicação e não afetam compras já feitas. Você pode deixar de usar o Ombú a qualquer momento e pedir a exclusão da sua conta para {$mail}.</p>

<h2>11. Lei aplicável e foro</h2>
<p>Estes termos são regidos pelas leis da República Argentina. Qualquer controvérsia será submetida aos tribunais ordinários da Cidade Autônoma de Buenos Aires, salvo se o Usuário for consumidor: nesse caso, são competentes os tribunais do seu domicílio, conforme a lei. Se uma cláusula for inválida, as demais continuam em vigor.</p>

<h2>12. Contato</h2>
<p>{$name}, {$address}. Questões legais e denúncias: {$mail}. Veja também nossa <a href="/pt-br/privacy">política de privacidade</a>.</p>
HTML, FALSE);

$page('/privacy', 'Política de privacidade', <<<HTML
<p><em>Em vigor desde {$privacy}. Esta página também está em espanhol e em inglês; se as versões divergirem, prevalece a versão em espanhol.</em></p>
<p><strong>O Ombú é uma demo</strong>, construída para um hackathon e ainda em teste; esta política pode mudar antes da abertura definitiva.</p>

<h2>1. Quem é o responsável</h2>
<p>O controlador dos dados pessoais tratados no Ombú (<a href="https://ombu.quebracho.digital">ombu.quebracho.digital</a>) é {$name}, {$address}. Contato: {$mail}. Esta política cobre apenas o Ombú: quando você chama um vendedor terceiro, o que você envia chega a esse vendedor e se rege pela política dele.</p>

<h2>2. Que dados tratamos</h2>
<p>O Ombú foi pensado para coletar pouco: não é preciso e-mail nem senha para usá-lo, e nenhum cartão é processado.</p>
<ul>
  <li><strong>Carteira e conta.</strong> Se você entra com uma carteira: seu endereço público, que passa a ser o nome da sua conta, e a assinatura que prova que ela é sua. Um cookie de sessão mantém você conectado.</li>
  <li><strong>Compras.</strong> Para cada chamada paga a um serviço vendido pelo Ombú: o serviço, a rede, o valor, os endereços que pagam e que recebem, a transação e seu resultado.</li>
  <li><strong>Avaliações.</strong> Nota, texto, o endereço da sua carteira e a transação da compra. <strong>As avaliações são públicas.</strong></li>
  <li><strong>O que você envia aos nossos serviços.</strong> A URL, o texto ou a afirmação que você envia aos nossos serviços, para processá-los. O resultado de uma análise de termos é guardado, para responder ao mesmo texto sem processá-lo duas vezes; ele pode citar o texto analisado.</li>
  <li><strong>Denúncias e mensagens.</strong> Seu nome, seu e-mail e a mensagem, se você nos escreve ou usa o formulário de denúncias.</li>
  <li><strong>Dados técnicos.</strong> Endereço IP, navegador e páginas visitadas, nos registros do servidor e de segurança, e os eventos recentes do registro do próprio site.</li>
  <li><strong>Serviços similares.</strong> Abaixo de cada anúncio, e no seu markdown para agentes, sugerimos serviços similares. Registramos quais foram mostrados, em que ordem e por quê, e qual uma pessoa clicou ou um agente seguiu; junto com isso, o navegador ou agente (seu user agent), o idioma, a página de onde você veio (sem a query) e se você estava conectado, não quem você é. Para contar uma só vez o mesmo visitante, guardamos um identificador que muda todo mês, calculado com uma chave secreta a partir do endereço IP (em IPv6, a parte de rede) e do navegador; o endereço IP em si não é guardado. São dados pseudonimizados, não anônimos. Se o seu navegador envia Global Privacy Control ou Do Not Track, nada é registrado.</li>
</ul>
<p><strong>Cookies.</strong> O Ombú usa um único cookie, o de sessão, e só quando você entra ou começa a entrar: ele é estritamente necessário para o serviço que você pede, por isso não exige consentimento. Não usamos cookies de análise, de publicidade nem de rastreamento de terceiros, não vendemos nem compartilhamos dados pessoais para fins publicitários, e não tomamos decisões automatizadas com efeitos jurídicos ou significativos sobre você.</p>

<h2>3. A blockchain é pública</h2>
<p>Os pagamentos são feitos em blockchains públicas. Os endereços, valores e datas de cada transação ficam visíveis para qualquer pessoa, para sempre, e <strong>nem nós nem ninguém pode alterá-los ou apagá-los</strong>. Um endereço de carteira pode ser vinculado a uma pessoa por outros meios. Tenha isso em mente antes de pagar ou avaliar com uma carteira que identifique você.</p>

<h2>4. Para que os usamos, e com que base</h2>
<ul>
  <li>Para prestar o serviço, fazer seu login, entregar e cobrar o que você compra, e mostrar avaliações verificadas: para cumprir nosso contrato com você (art. 6.1.b RGPD).</li>
  <li>Para manter registros de vendas: para cumprir obrigações legais (art. 6.1.c RGPD).</li>
  <li>Para manter o Ombú seguro, prevenir fraudes e abusos, e tratar denúncias: nosso interesse legítimo em proteger o serviço e seus usuários, e obrigações legais (art. 6.1.f e 6.1.c RGPD).</li>
  <li>Para responder ao que você nos escreve: seu pedido, e nosso interesse legítimo em respondê-lo (art. 6.1.b e 6.1.f RGPD).</li>
  <li>Para melhorar as sugestões de serviços similares e encontrar erros e abusos nelas: nosso interesse legítimo em um serviço útil e que funcione (art. 6.1.f RGPD). Você pode se opor a qualquer momento; Global Privacy Control ou Do Not Track o interrompem.</li>
</ul>

<h2>5. Quem mais os recebe</h2>
<ul>
  <li><strong>Provedores que fazem o Ombú funcionar</strong>: a Cloudflare, que transporta e protege o tráfego do site (pode tratar dados fora da Argentina, inclusive nos Estados Unidos); e, quando um pagamento é liquidado pela Circle, a autorização de pagamento que você assinou chega à Circle. Os modelos por trás dos nossos serviços rodam em servidores próprios.</li>
  <li><strong>As redes blockchain</strong>, onde os pagamentos são publicados (seção 3).</li>
  <li><strong>Os vendedores terceiros</strong>, apenas o que você mesmo envia a eles ao chamar seus serviços.</li>
  <li><strong>As autoridades</strong>, quando a lei exigir ou para defender direitos em uma reclamação.</li>
</ul>
<p>O Ombú é operado a partir da Argentina, que a Comissão Europeia reconhece como tendo um nível adequado de proteção de dados pessoais (Decisão 2003/490/CE). Quando os dados vão para outros países, como os Estados Unidos, vão apenas para provedores obrigados pelas garantias que a lei exige, como as cláusulas contratuais-padrão da Comissão Europeia ou o Quadro de Privacidade de Dados UE-EUA.</p>

<h2>6. Por quanto tempo os guardamos</h2>
<ul>
  <li>Conta: até que você peça sua exclusão, ou até que seja excluída por violar os <a href="/pt-br/terms">termos</a>.</li>
  <li>Registros de compras: pelo prazo que a lei exige para guardar registros contábeis e fiscais.</li>
  <li>Avaliações: enquanto estiverem publicadas; você pode nos pedir para remover as suas.</li>
  <li>Denúncias e mensagens: pelo tempo necessário para tratá-las e, depois, para responder a qualquer reclamação relacionada.</li>
  <li>Registro de serviços similares: 12 meses, e depois é apagado automaticamente.</li>
  <li>Registros técnicos: por pouco tempo, conforme a rotação de cada sistema.</li>
</ul>

<h2>7. Seus direitos</h2>
<p>Você pode pedir acesso aos seus dados, e sua retificação, atualização ou exclusão, escrevendo para {$mail} a partir do endereço em que quer receber a resposta, ou comprovando que controla a carteira. Respondemos nos prazos da lei (na Argentina, Lei 25.326: dez dias corridos para o acesso, cinco dias úteis para a retificação ou exclusão). Se o RGPD se aplica a você (no Espaço Econômico Europeu, e de forma semelhante no Reino Unido e na Suíça), você também tem direito de se opor ao tratamento baseado em nosso interesse legítimo, à limitação do tratamento, à portabilidade dos dados e a apresentar uma reclamação à autoridade de proteção de dados do lugar onde vive ou trabalha; respondemos em até um mês. Se você vive em outro lugar cuja lei lhe dá mais direitos (por exemplo, no Brasil ou na Califórnia), pode exercê-los da mesma forma. Excluir uma conta não apaga o que está na blockchain.</p>
<p>O titular dos dados pessoais tem a faculdade de exercer o direito de acesso a eles gratuitamente, em intervalos não inferiores a seis meses, salvo se for comprovado um interesse legítimo para tanto, conforme o artigo 14, inciso 3, da Lei nº 25.326 da Argentina. A AGENCIA DE ACCESO A LA INFORMACIÓN PÚBLICA, como Órgão de Controle da Lei nº 25.326, tem a atribuição de atender às denúncias e reclamações apresentadas por quem for afetado em seus direitos pelo descumprimento das normas vigentes de proteção de dados pessoais.</p>

<h2>8. Segurança</h2>
<p>Protegemos os dados com medidas técnicas e organizacionais razoáveis. As chaves de pagamento dos vendedores nunca são guardadas na configuração do site, e muitos vendedores não precisam de nenhuma chave no site. Nenhum sistema é totalmente seguro: se um incidente afetar você, informaremos conforme a lei exigir.</p>

<h2>9. Menores de idade</h2>
<p>O Ombú não é destinado a menores de 18 anos e não coleta seus dados intencionalmente. Se você acha que um menor nos forneceu dados, escreva para {$mail} e os excluiremos.</p>

<h2>10. Alterações</h2>
<p>Podemos alterar esta política; a data no início indica quando. Se uma alteração for relevante, avisaremos no site.</p>
HTML, FALSE);

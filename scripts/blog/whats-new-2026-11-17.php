<?php

/**
 * @file
 * The "What's new" of the deploy of 2026-11-17: the terms say Ombú handles
 * money only for its own listings, a two-day cooldown on new dependency
 * versions, and a written restore of production. Every claim is in commits
 * 43f327c, 320e21d and b696289 and in the date fix that went with them.
 *
 * Seeded with the deploy, published: every deploy goes with its post. The
 * pt-br needs a native review.
 */

$img = '/modules/custom/ombu/images/blog';

return [
  'categories' => ['whats-new'],
  'image' => 'pampa-sunset.jpg',
  'alt' => [
    'en' => 'Sunset over the countryside in Junín, Argentina',
    'es' => 'Atardecer en el campo, en Junín, Argentina',
    'pt-br' => 'Pôr do sol no campo, em Junín, Argentina',
  ],
  'date' => '2026-11-17 18:00:00 America/Argentina/Buenos_Aires',
  'en' => ["What's new in Ombú: clearer terms on who holds the money, and slower updates on purpose", <<<HTML
<p class="text-body-secondary small">17 November 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/pampa-sunset.jpg" class="figure-img img-fluid rounded w-100" width="1600" height="565" alt="Sunset over the countryside in Junín, Argentina."></figure>

<p class="lead">A quiet deploy: nothing new to click, but the terms now say exactly how money moves, and the site is harder to break from the outside and easier to bring back.</p>

<h2>The terms: Ombú only handles the money of its own services</h2>
<p>Section 3 of the <a href="/terms">terms of use</a> said that, when a third-party service was called through Ombú, Ombú could pass the buyer's signed payment on to the network and pay the network fee. That is not how the market works, and it never was. When you buy from a third-party seller, you (or your agent) call the seller's endpoint directly, from your own wallet, and pay the seller on the blockchain. Ombú never holds, transmits or relays money or payment authorizations for those purchases.</p>
<p>Only the services sold by Ombú itself settle through Ombú's own facilitator. The terms now say so in English, Spanish and Portuguese, and the date at the top of the page is November 17, 2026, as section 10 promises when the terms change. Purchases made before keep the terms of their day.</p>

<h2>New dependency versions wait two days</h2>
<p>Ombú is built on a few hundred open-source packages. When one of them is compromised, the bad version is usually caught and pulled within hours. So when we update, a version published less than two days ago is not taken: the previous one stays until the new one has been out long enough to be trusted. Our own packages (the payment library and the Drupal payment and wallet modules) are exempt, so a fix of ours still ships the same day. A security fix of someone else's package can skip the wait, on purpose and by hand.</p>

<h2>Bringing production back</h2>
<p>We wrote down, step by step, how to rebuild Ombú on a clean machine from the code, the nightly backup and its secrets, and what to check afterwards: the market, both search indexes, payments, the blog. It also lists what a backup alone does not cover, and the copies to keep before anything goes wrong.</p>

<h2>What is not done</h2>
<p>The restore has been written, not rehearsed: the first full drill on a spare machine is still ahead.</p>
HTML, 'The terms now say Ombú only handles the money of its own services; new dependency versions wait two days; a written restore of production.'],
  'es' => ['Novedades de Ombú: términos más claros sobre quién toca la plata, y actualizaciones más lentas a propósito', <<<HTML
<p class="text-body-secondary small">17 de noviembre de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/pampa-sunset.jpg" class="figure-img img-fluid rounded w-100" width="1600" height="565" alt="Atardecer en el campo, en Junín, Argentina."></figure>

<p class="lead">Un deploy tranquilo: nada nuevo para clickear, pero los términos ahora dicen exactamente cómo se mueve la plata, y el sitio es más difícil de romper desde afuera y más fácil de levantar de nuevo.</p>

<h2>Los términos: Ombú solo maneja la plata de sus propios servicios</h2>
<p>La sección 3 de los <a href="/es/terms">términos de uso</a> decía que, cuando se llamaba a un servicio de terceros a través de Ombú, Ombú podía transmitir a la red el pago firmado por quien compraba y pagar la comisión de la red. El mercado no funciona así, y nunca funcionó así. Cuando le comprás a un vendedor tercero, vos (o tu agente) llamás directamente al endpoint del vendedor, desde tu propia billetera, y le pagás en la blockchain. Ombú nunca retiene, transmite ni retransmite dinero ni autorizaciones de pago de esas compras.</p>
<p>Solo los servicios que vende el propio Ombú se liquidan a través del facilitador de Ombú. Los términos ahora lo dicen en español, inglés y portugués, y la fecha al principio de la página es el 17 de noviembre de 2026, como promete la sección 10 cuando los términos cambian. Las compras hechas antes conservan los términos de su día.</p>

<h2>Las versiones nuevas de las dependencias esperan dos días</h2>
<p>Ombú está construido sobre unos cuantos cientos de paquetes de código abierto. Cuando uno de ellos es comprometido, la versión maliciosa suele detectarse y retirarse en cuestión de horas. Por eso, cuando actualizamos, no se toma una versión publicada hace menos de dos días: queda la anterior hasta que la nueva lleve el tiempo suficiente afuera como para confiar en ella. Nuestros propios paquetes (la librería de pagos y los módulos de Drupal de pagos y billeteras) quedan exceptuados, así un arreglo nuestro sigue saliendo el mismo día. Un arreglo de seguridad de un paquete ajeno puede saltarse la espera, a propósito y a mano.</p>

<h2>Volver a levantar producción</h2>
<p>Escribimos, paso a paso, cómo reconstruir Ombú en una máquina limpia a partir del código, el backup de cada noche y sus secretos, y qué revisar después: el mercado, los dos índices de búsqueda, los pagos, el blog. También dice lo que un backup solo no cubre, y las copias que hay que tener antes de que algo falle.</p>

<h2>Lo que no está hecho</h2>
<p>El restore está escrito, no ensayado: el primer simulacro completo en una máquina de repuesto todavía está por hacerse.</p>
HTML, 'Los términos ahora dicen que Ombú solo maneja la plata de sus propios servicios; las versiones nuevas de las dependencias esperan dos días; un restore de producción por escrito.'],
  'pt-br' => ['Novidades do Ombú: termos mais claros sobre quem mexe no dinheiro, e atualizações mais lentas de propósito', <<<HTML
<p class="text-body-secondary small">17 de novembro de 2026 · Quebracho</p>
<figure class="figure w-100 mb-4"><img src="$img/pampa-sunset.jpg" class="figure-img img-fluid rounded w-100" width="1600" height="565" alt="Pôr do sol no campo, em Junín, Argentina."></figure>

<p class="lead">Um deploy tranquilo: nada novo para clicar, mas os termos agora dizem exatamente como o dinheiro circula, e o site está mais difícil de quebrar de fora e mais fácil de colocar de pé de novo.</p>

<h2>Os termos: o Ombú só lida com o dinheiro dos seus próprios serviços</h2>
<p>A seção 3 dos <a href="/pt-br/terms">termos de uso</a> dizia que, quando um serviço de terceiros era chamado pelo Ombú, o Ombú podia transmitir à rede o pagamento assinado por quem comprava e pagar a taxa da rede. O mercado não funciona assim, e nunca funcionou. Quando você compra de um vendedor terceiro, você (ou o seu agente) chama diretamente o endpoint do vendedor, a partir da sua própria carteira, e paga a ele na blockchain. O Ombú nunca retém, transmite nem retransmite dinheiro nem autorizações de pagamento dessas compras.</p>
<p>Só os serviços vendidos pelo próprio Ombú são liquidados pelo facilitador do Ombú. Os termos agora dizem isso em português, espanhol e inglês, e a data no topo da página é 17 de novembro de 2026, como a seção 10 promete quando os termos mudam. As compras feitas antes mantêm os termos do seu dia.</p>

<h2>Versões novas das dependências esperam dois dias</h2>
<p>O Ombú é construído sobre algumas centenas de pacotes de código aberto. Quando um deles é comprometido, a versão maliciosa costuma ser detectada e retirada em poucas horas. Por isso, quando atualizamos, uma versão publicada há menos de dois dias não é usada: fica a anterior até que a nova esteja publicada há tempo suficiente para confiarmos nela. Os nossos próprios pacotes (a biblioteca de pagamentos e os módulos de Drupal de pagamentos e carteiras) ficam de fora, assim uma correção nossa continua saindo no mesmo dia. Uma correção de segurança de um pacote alheio pode pular a espera, de propósito e à mão.</p>

<h2>Colocar a produção de pé de novo</h2>
<p>Escrevemos, passo a passo, como reconstruir o Ombú numa máquina limpa a partir do código, do backup de cada noite e dos seus segredos, e o que verificar depois: o mercado, os dois índices de busca, os pagamentos, o blog. Também diz o que um backup sozinho não cobre, e as cópias que é preciso ter antes que algo falhe.</p>

<h2>O que não está feito</h2>
<p>O restore está escrito, não ensaiado: o primeiro simulado completo numa máquina reserva ainda está por fazer.</p>
HTML, 'Os termos agora dizem que o Ombú só lida com o dinheiro dos seus próprios serviços; versões novas das dependências esperam dois dias; um restore da produção por escrito.'],
];

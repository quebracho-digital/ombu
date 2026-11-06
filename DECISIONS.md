# Decisions

## TL;DR

- Why Ombú does what it does, and what it chose not to do yet.
- The process: leaks first, a failing test, an independent audit, the
  smallest fix at the root.
- Drupal's access checks are never bypassed, not even to help agents.
- What is not done yet goes to the [ROADMAP](ROADMAP.md) with its reason.
- The x402 module's own decisions are in
  [x402_pay's DECISIONS](https://git.drupalcode.org/project/x402_pay/-/blob/1.0.x/DECISIONS.md).

## How we decide

1. **Leaks and production errors first.** Anything that gives away what is
   sold, shows what a visitor may not see, or breaks pages comes before any
   feature or release.
2. **Prove it.** A test fails on the old code before the fix lands.
3. **Audit everything.** A reviewer who did not write the change reads it.
   Critical and High are fixed before the commit; Medium are fixed or
   written down; Low are judged.
4. **The smallest fix at the root**, with deliberate shortcuts marked
   `ponytail:` in the code. Bigger work goes to the roadmap.
5. **Visible and the same for everyone.** Nothing is shown only to bots or
   only to people.
6. **Leo decides** scope, releases, and what is published (pushes to this
   public repo, drupal.org). Claude proposes, with a recommendation.

## 2026-10-25

### Agents keep reading markdown, with nothing hidden

Every free `.md` page opens with one line saying that every page reads as
markdown. Its links to pages that read free point to their `.md`. The
footer links this page's `.md` and `/llms.txt`.

- **Rejected:** the whole page as markdown in a hidden `div`. It looks like
  prompt injection or cloaking. Ombú carries sellers' text, which would
  reach agents unseen by people. It also doubles every page's weight.
- **Why visible:** what an agent reads from a page is its text, often a
  summary of it, not its `<head>`.

### A link goes to `.md` only where the reader may go

Each link is matched by the access-aware router, as whoever reads it. A
page they may not see keeps its link unchanged.

- **Why:** never bypass Drupal's access checks. A `.md` link must not
  reveal that a page exists, or what it holds.

### The footer is a block plugin, not a menu or a custom block

The link changes per page: this page's `.md` or none, the market with its
search, the language prefix. A menu link or a custom block holds fixed
text. It is still a block, so you place, move or hide it in *Block layout*.

### Caching: the URL, not a context

The page cache and Cloudflare key by URL. A user or agent cache context
only reaches Drupal's render caches. A separate `.md` URL is the fix every
cache understands.

- **Rejected for now:** caching what is sold. Sold pages are sent
  `private` until there is a cache that varies by payment (roadmap: *A
  cache that is safe for what is sold*).

### Not exposed to x402_pay's JSON:API and Markdownify leaks

Ombú has no JSON:API, REST or Markdownify, and keeps it that way until it
needs one of them. The leaks were fixed in x402_pay for 1.0.0-beta2 anyway,
since other sites use those modules.

### Meilisearch upstream: three issues, three stacked merge requests

The three features (vectors provided by Drupal, a vector query, "More like
this") go to drupal.org as one issue each. Each issue gets its own merge
request, one commit each, stacked on a single branch.

- **Why:** the maintainer can accept one and decline another.
- **Why:** the third one breaks the API (the backend's constructor), while
  the first two only add to it.
- **Why:** smaller reviews, and credit per feature.
- **Order:** the second and third depend on the first. They wait,
  postponed, until it is in.
- **Rejected:** one issue with one merge request, where a "no" to any part
  stops all of it.

### search_api 1.43 waits for quebracho/x402 on Packagist

Decidido por Leo (2026-11-01): "esperemos que este publicada la version de la
libreria y luego usala desde packglist". The DDEV mount of `x402_pay` is on
`refactor/use-quebracho-x402`, which needs `quebracho/x402` that is not in
ombu's vendor. We do not switch that checkout to `1.0.x`; we wait for
`quebracho/x402` v0.2.0 on Packagist and use it from there. Prod keeps
search_api 1.41 until then. v0.2.0 reached Packagist the same morning;
the lock change (1.41.0 → 1.43.0, only search_api) was tested in DDEV and
committed as f01fe08 on `chore/search-api-1.43`.

Decidido por Leo (2026-11-01 14:50): "commit, push, luego git pull en ser5", with "Sí, avanzá 1.0.x" for x402_pay and "Sí, en este deploy" for symfony/mime. One deploy carries the x402_pay refactor (lock to the new `1.0.x`), 4ed8361, search_api 1.43 and symfony/mime 7.4.22 (CVE-2026-45070, email header injection). The lock is updated once on this branch, so `chore/search-api-1.43` (f01fe08) is not merged.

### An MCP endpoint, read-only and free, in dev first

Decidido por Leo (2026-11-03 09:30): "el mcp deberia hacerlo una sesion experta en ombu". For the Symfony AI & MCP hackathon, Ombú serves `/mcp` with the contrib `drupal/mcp_server` (official `mcp/sdk`), two tools that call `CatalogueController`, so they answer what `/market.json` answers: `ombu_search_services` and `ombu_get_service`. Anonymous may use them: the module's `access mcp server` permission is granted to the anonymous role, which is safe only because both tools are read-only over what anonymous already sees. Any tool that writes or charges must check its own access.

The module and that permission were first enabled in DDEV only, outside `config/sync`. Turning it on in SER5 was Leo's call, and he made it: see the next entry; `config/sync` carries it since 2026-11-03, because a deploy's `cim` had switched it off.

Decidido por Leo (2026-11-03): "si, agrega mis listados al mcp" (answering whether to sell our own listings over MCP, charging with `quebracho/x402`), and the footer and `/llms.txt` link to it. One paid tool, `ombu_call_service`, instead of one per seller: any listing with a `field_service` is sold through it, so a new seller needs no code. The payment is the library's `X402McpMiddleware` wired in `McpPayments` with the site's facilitators, claims and payment log, so a sale over MCP counts for reviews like one over HTTP. The tool runs only for a call that middleware admitted: Drush's STDIO transport has no middleware and sells nothing. `/llms.txt` mentions the MCP only where `mcp_server` is on (`_ombu_llms_txt()`); the footer link follows the route.

Decidido por Leo (2026-11-03): "podemos actualizar ombu para que use los modulos x composer en lugar de mapear el volumen?". DDEV no longer mounts `x402_pay`, `wallet_auth` or `ai_provider_universal`: they run from `web/modules/contrib`, as in prod. The mounts stay available in `.ddev/docker-compose.dev-modules.yaml.example` for working on a module.


### The listings index keeps who may view each item

Decidido por Leo (2026-11-03): "puedes ajustar los indices de search_api para que no indexen no publicados y respeten los niveles de permisos?". Unpublished listings were already out of the index (`entity_status`, with `index_directly`). The `listings` index now also has Search API's `role_access` processor: it stores, per item, the roles whose entity access allows `view`, and every query is filtered by the current user's roles. Its hidden field `role_access` is in the index config (`config/sync` and the module's `config/optional`); importing the processor alone does not add it, because `preIndexSave()` only runs when the index is saved through its form or code.

Caveat: the roles are decided when an item is indexed. Changing who can view listings (a permission, a role) needs `drush search-api:reset-tracker listings && drush search-api:index listings` (about a minute for 2,800 listings). So does the deploy that first brings the processor: until then the items have no roles and the market is empty. The Meilisearch index is not touched.

The link to `/mcp` in the footer is a link of the `footer` menu (`ombu.links.menu.yml`), so the menu system checks access, caches and translates it; `AgentHooks` removes it where `mcp_server` is not installed.

Decidido por Leo (2026-11-03 11:30): enabled the MCP in SER5 himself, giving `access mcp server` to the anonymous role (he asked which permissions; the answer was that one only, not the prompts one): "ya lo habilite, podes probarlo". Claude tested `https://ombu.quebracho.digital/mcp` right after (`initialize`, `tools/list`, `ombu_search_services`, and an unpaid `ombu_call_service` returning the PaymentRequired). `/llms.txt` already announces it. A `drush deploy` right after switched it off, because `config/sync` did not carry the module; Leo turned it on again and asked for production's configuration to be exported into `config/sync` ("exporta la configuracion actual de ser5 a config/sync asi el proximo deploy no rompe nada"), so the next deploy keeps it.

## 2026-11-06

### 8004scan: an importer next to Circle's, measured first

Decidido por Leo (2026-11-06): "creo que cambie de opinion, quizas si podamos hacer el importer de 8004 y que conviva con el de arc y los que vayamos agregando, entonces tambien tendria sentido agregar los campos o un campo general json y actualizar sus valores con el importer de arc?" Then: "consulta cuantos agentes tienen endpoints y cuantos tienen reviews verificados", and a detailed prompt for jcode (`prompt_jcode_8004scan.md`, not tracked).

What 8004scan's documentation says (site, API page, OpenAPI file, ERC-8004 spec, still a Draft):

- The REST API (`https://api.8004scan.io/api/v1`, key in `X-API-Key`) lists agents, filters them by `owner_address` and lists feedback. Anonymous use is 30 requests a minute and 1,000 a day; a free key is 600 and 100,000. It documents no way to register an agent or send feedback; those are on-chain.
- An agent's endpoints are in its detail (`GET /agents/{chain_id}/{token_id}`), not in the list.
- `giveFeedback` needs no permission from the agent (anyone but its owner or operator). The optional feedback file has a `proofOfPayment` (`fromAddress`, `toAddress`, `chainId`, `txHash`) that can carry an x402 payment.

What was measured on 2026-11-06:

- 2,332,699 agents; 415,854 with `x402_supported`; 145,092 of those on mainnet; **77** of those with a verified endpoint (41 on Base, 4 on Arc); 7,403 on mainnet with at least one feedback.
- Verified reviews: none found in a sample of the 100 latest feedbacks (no `offchain_data`, no `proofOfPayment`). A small sample; phase 0 widens it.

Decided:

- **An importer for 8004scan that lives beside Circle's.** It reuses `upsert($item, $source)` and the multi-catalogue `field_markets`; what assumes `'circle'` (`block()`, `retireMissing()`, `unmarkMissing()`, the published status) is generalized so one importer never retires another's listings.
- **Only agents with a callable endpoint.** An agent becomes a listing only if its endpoint answers 402 with valid `accepts` (`SellerProbe`). No "discovery" listings: they cannot be sold or tested.
- **One general JSON field, `field_registry`, one key per registry** (`erc8004` first). It is outside `field_source_hash`, and Circle's `upsert()` does not overwrite it. A separate enrichment step fills it by matching `field_seller_address` with `owner_address` (EVM only); the import command can call it.
- **Measure first.** Phase 0 is a read-only drush survey; if fewer than 5 agents pass the 402 probe, the work stops for Leo's look.
- **The label is "Registered on ERC-8004", never "verified".** 8004scan's `is_verified` is self-declared; Ombú's `field_verified` means something else.
- **One contract for every importer.** Decidido por Leo (2026-11-06): "utiliza la funcionalidad de la libreria Quebracho/x402 para checkear listas negras y agrega ese chequeo en los listings de arc tambien, deberia estar definido en la interface de los importers". A `CatalogImporterInterface` declares the blacklist check, so no importer, today's or a future one, can exist without it. It uses `quebracho/x402`'s `AddressCheck` and `TokenBlacklist` on every `payTo`, for Circle's listings too. The library asks only the tokens it lists (an asset not in its `TOKENS` passes), so the USDC of the networks we import is added through `$tokens`. A node that cannot answer counts as unchecked: nothing is imported and nothing is retired.
- **Only verified endpoints and the free plan's rate limit.** Decidido por Leo (2026-11-06): "ponle un rate limit que se ajuste al plan gratuito, solo importamos listing con endpoint verificados y que no esten en ninguna lista negra". About 500 requests a minute and 90,000 a day, through Drupal's `flood` service, below the free plan's 600 and 100,000.
- **Reviews only when the transaction's receipt matches the wallets.** Decidido por Leo (2026-11-06): "solo se importan los comentarios donde el recibo de la transaccion coincida con las wallets". A feedback becomes a review only if its payment transaction has a USDC transfer from the wallet that signed the feedback to the listing's `payTo`; `ReviewVerifier` already knows how to read that receipt. One transaction backs one review. A revoked feedback, or one without proof of payment, is not imported. Few or none may pass; the rule is not relaxed to fill the list.
- **Work order (superseded 2026-11-06 13:07, see below):** the 8004scan importer is stopped. The phases in `prompt_jcode_8004scan.md` do not continue for 8004scan.

- **Source: the x402 catalogues, not 8004scan.** Decidido por Leo (2026-11-06 13:07): "vamos por la opcion C, que es Bazaar/PayAI?". Read as: stop the 8004scan importer and take the next source from the CDP Bazaar (`GET https://api.cdp.coinbase.com/platform/v2/x402/discovery/resources`) and PayAI (`https://facilitator.payai.network/discovery/resources`). Each resource must answer 402 with valid `accepts` through `SellerProbe` and pass the blacklist before it becomes a listing. Measured on 2026-11-06: 17 of 23 unique Bazaar resources answered 402 with `accepts`; 0 of 40 sampled `payTo` wallets are registered on 8004scan. The 8004scan survey stays in the repo as a measurement.
- **Measured 2026-11-06 (wider sample, read-only, not in the repo):** the catalogues are much bigger than the first 23. Bazaar reports 36,302 resources (x402 v2) and PayAI 18,560 (v1). The first 2,000 of each were fetched and 150 random unique resources per catalogue probed with an unpaid GET:
  - Bazaar: 2,000 unique resources on 656 hosts, 684 `payTo`; networks mostly Base (2,248 accepts), Solana (757), Polygon, Arbitrum, Arc (`eip155:5042`, 106). Probe: 124 of 150 answered 402 with a `PAYMENT-REQUIRED` header, on 97 hosts; 11 gave 404, 9 gave 405 (POST-only), 3 gave 200.
  - PayAI: 1,995 unique resources on only 162 hosts and 200 `payTo`; a few hosts dominate (agent402.tools 599, a pinggy tunnel 397, writerslogic 199). Probe: 122 of 150 answered 402 (121 by header, 1 with `accepts` in the body), on 28 hosts; 24 did not connect.
  - Then validated with `drush ombu:catalog-survey --pages=20 --sample=150 --seed=7` (SellerProbe; live `accepts` must match the catalogue's network, asset, payTo and amount; payTo asked to Circle's USDC contract through Alchemy on Ethereum, Base, Polygon, Arbitrum, OP and Avalanche). Bazaar: 71 importable, 59 importable with payTo unchecked (Solana, Arc mainnet and other tokens), 10 whose live 402 differs from the catalogue, 6 malformed or a bad amount, 4 failed the probe; on 100 hosts. PayAI: 21 importable, 26 with payTo unchecked, 51 differ from the catalogue, 22 malformed or not `exact`, 23 resolve to private addresses (tunnels), 7 failed the probe; on 22 hosts. No sampled payTo was frozen. The mainnet USDC addresses in the command were written from memory and are still to be confirmed against Circle's list.
  - Superseded by the line above: the header's `accepts` was not decoded and validated, and the blacklist was not run. Hosts with hundreds of resources, and tunnels like pinggy, call for deduplicating by host and `payTo` before importing, so listings aren't flooded.

- **The decision model classifies; the origin of each tag is kept.** Decidido por Leo (2026-11-06 13:26): "podemos agregar un campo de multples valores a un vocabulario tipo tags con dos campos origen y valor, onda circle, mcp? solo para que quede registrado, pero la categoria final la decide nuestro llm decision model?" and "Si, usamos la IA de ombu". So a multi-value field of pairs (origin, value), like Circle's or MCP's tags, records where each tag came from. The final category is chosen by the decision model from the closed taxonomy, Circle's listings included.

- **Injection means quarantine, not import.** Decidido por Leo (2026-11-06 13:26): "si el llm detecta inyection, ese listing no se importa o lo deja en cuarentena para analizar? ... ombu es un safe place para agentes!!! cero tolerancia!!". A listing the model flags as injection is not imported and is kept in quarantine for analysis; it is never published. A model answer alone cannot admit a listing: anything not clearly passed stays out.

- **Amount and payer checked against `accepts`, in the importer's interface.** Decidido por Leo (2026-11-06 13:26): "Acepto comparar monto y remitente con accepts, deberia ser parte de la interface del importer, circle tambien deberia implementarlo, asi no tenemos tanta basura". The check goes in `CatalogImporterInterface` with the blacklist, and Circle implements it too.

- **On-chain verification, with a rate-limit plan.** Decidido por Leo (2026-11-06 13:26): "Verificamos on chain". The public RPC rate-limited us; the plan (failover, cache, keyed provider only if Leo creates the account) is still to be decided. Leo asked whether accounts are needed; that is his decision.

- **Experimental decision model.** `ai_provider_universal_decision` is marked experimental and its operation is still changing upstream; any use goes behind an Ombú interface, with a pinned version and a fail-closed answer. Decidido por Leo (2026-11-06 13:47): "el modulo es nuestro, soy el manteiner (somos)". The label stays because the upstream AI core's Decision operation (AI 1.6) is still moving, not because the module belongs to someone else.

- **Alchemy first for on-chain checks.** Decidido por Leo (2026-11-06 13:47): "vamos con Alchemy primero". App `ombu.quebracho.digital` (Marketplace), with the Node API and the chains Base, Ethereum, OP, Polygon, Solana, Arbitrum and Avalanche. The key is in `.ddev/.env` as `ALCHEMY_API_KEY` (git-ignored), never in the repo or in chat. Seven chains answered `eth_blockNumber` / `getSlot` with the key on 2026-11-06. Leo paid the plan and redeemed the $1,000 credit himself.

- **jcode stopped after the survey; the survey was fixed.** Decidido por Leo (2026-11-06 12:22): "jcode no paro, yo lo detuve, ya habia arrancado la fase 1" (no phase 1 file exists on disk or in any branch, checked the same day). Decidido por Leo (2026-11-06 12:22): "puedes corregir los 4 puntos. mueve a un branch nuevo". The four review points are fixed in `f95d46c`; jcode's two commits moved from `feat/blog` to `feat/8004scan-importer`, which is based on the `feat/blog` tip (`7589498`) so DDEV keeps its enabled modules.

- **Measured 2026-11-06, with the fixed survey (`drush ombu:8004scan-survey`):** 77 candidates (the earlier 20 was the API's default page size, not the candidate count), 0 with a 402 and valid `accepts`, 0 wallet matches, 0 feedbacks with a resolvable proof. The failures: 44 answered 200 (web pages), 17 have no http endpoint, 6 answered 404, 4 timed out, the rest redirected. Phase 0's stop rule applies: fewer than 5 pass the 402, so the work stops for Leo.
- **Rules for the developer session (jcode):** drupal.org's AI policy (disclosure line, a human responsible), and look for what Drupal's API already offers before writing any function of our own.

Not decided, Leo's call: registering Ombú listings as ERC-8004 agents or publishing verified-purchase reviews as feedback (on-chain, costs gas, goes out under Ombú's name); badges; a cron; the push of this work to the public repo.

Unverified: how many of the 77 pass the 402 probe, and how many agents carry proof of payment. Phase 0 answers both.

### A blog inside Ombú, its posts seeded from files

Decidido por Leo (2026-11-06): "vamos a crear un blog dentro de ombu, antes que nada, snapshot de lo que hay ahora, config y db, luego agrega el blog, crea una primera entrada en todos los idiomas contando como empezo ombu, con listados propios de quebracho y con los del market de arc". Before the change: the DDEV database and the active config were saved in `~/backups/ombu/2026-11-06_0901/` (plus a DDEV snapshot, `pre-blog-2026-11-06_0901`).

- **A new content type `blog`**, not the existing `article`: `article` is the paid x402 demo (priced, summarised), and a blog post is free and public. Translated in English, Spanish and Portuguese like the pages; `/blog/[title]` aliases through Pathauto; `/blog` is a view that lists only the posts in the visitor's language. All of it is in `config/sync`.
- **The link is the module's, not a menu entry in the database:** `ombu.blog` in `ombu.links.menu.yml`, in the main menu (it was first in the footer).
- **Posts are content, so they live in the database, not in config.** Each post is a file in `scripts/blog/` (one array: date, and a title and body per language); `scripts/seed-blog.php` creates or updates them, idempotently, and leaves alone a post that was edited on the site (`OMBU_SEED_FORCE=1` to overwrite). That is how a post reaches production: `drush php:script ../scripts/seed-blog.php`, as with `seed-pages.php`.
- **A post names a listing by its SKU (`{{ours-ping}}`, `{{circle-…}}`), never by its id.** Ids differ between databases; SKUs do not. The seed turns each into the listing's URL in the post's language and stops with an error if no listing has that SKU.
- **Not done:** RSS, and a mention in `/llms.txt`. The blog's list is answered as markdown since 2026-11-06 (`/blog.md`, `BlogMarkdownSubscriber`, commit `28559a3`); its Spanish date line still shows English month names.

Decidido por Leo (2026-11-06): "agrega blog al menu principal, usa el blog como home, modifica el post para darle mas onda, usa fotos libres de derechos. luego agrega otro post contando como es el proceso de importacion de listings del market de arc".

- **The blog is the home page** (`system.site` `page.front: /blog`, in `config/sync`). The market stays one click away, in the main menu. The old home was `/market`.
- **The home shows cards, not an entity list.** `/blog` is a view of fields (photo, date, title, teaser), styled by `css/blog.css`, attached from the existing `views_pre_render` hook. The rendered entity showed the author's avatar and "Submitted by admin", so posts no longer show an author (`display_submitted` off) and carry their date as the first line of the body.
- **Each post has a photo, `field_image`, for its card.** The seed copies the module's file as a managed file. The post carries its own photo with a caption in the body. Translatable, so each language has its own alt text.
- **Photos are public domain or CC0**, from Wikimedia Commons, kept in the module (`images/blog/`) with their authors and licences in `CREDITS.md`. In the repo, not in `sites/default/files`, so a deploy carries them. One candidate was dropped: an old photo of an ombú with identifiable children in it.
- **Second post:** how Circle's catalogue is imported (`scripts/blog/how-the-arc-market-gets-in.php`). Every claim in it was checked against `CircleCatalogImporter`; it says nothing of what is not deployed yet (the 8004scan importer, the blacklist).
- **Known:** the menu now has "Home" and "Blog", which lead to the same page.


### Our own Meilisearch module, not the contrib one

Decidido por Leo (2026-11-06 15:21): "no vamos a usar el modulo de drupal, vamos a crear nuestro propio modulo custom que quizas lo hagamos contrib en base a nuestro fork". So `quebracho_meilisearch` (from our fork, commit `02951a5`) is the module; the contrib `drupal/meilisearch` is not a dependency, and the tests enable `quebracho_meilisearch`, not `meilisearch`. Whether it becomes contrib later is a decision for Leo, not for the tests.

- **Tests:** namespace `Drupal\Tests\quebracho_meilisearch`, the test module's config names match its IDs, and the Meilisearch key comes from `MEILISEARCH_TEST_KEY`.
- **Server:** DDEV's Meilisearch is in `.ddev/docker-compose.meilisearch.yaml`. Checked 2026-11-06: there is no key mismatch. The container runs with the addon's default `ddev`, and `settings.php` gives Drupal the same key under DDEV; the "26" was the size of `.ddev/.env.meilisearch`, which holds only `MEILISEARCH_TAG`. Production needs its own server and key.
- **Config in `config/sync` (2026-11-06):** the server, the `listings_meilisearch` index and `ombu_similar_meilisearch` moved from `config/meilisearch/`, and `quebracho_meilisearch` is in `core.extension`. Uninstalling the contrib `meilisearch` had deleted DDEV's server (it depended on that module) and disabled the index; the server now depends on `quebracho_meilisearch`, the three files were imported alone (`cim --partial --source=../config/meilisearch`) and the 2,814 listings re-indexed. `Similar` pings the server first, so a site without Meilisearch serves the database view.

### The blog's list reads as markdown

Decidido por Leo (2026-11-06 15:21): "los listados de blog tambien deberian poder verse en .md". `/blog.md` and `Accept: text/markdown` on `/blog` answer with the published posts (commit `28559a3`), because x402_pay does not render view pages as markdown.

### No merge until every test passes; the verifier agent is Claude's design

Decidido por Leo (2026-11-06 15:50): "no se mergea hasta que no den todos los tests ok, tu decide sobre el agente verificador". So `feat/blog` (today this branch's tip) does not reach `main` while any suite of ombu or quebracho_meilisearch fails (21 failures and 2 errors in Meilisearch at the time).

The verifier, as Claude decided it on Leo's delegation:

- **One service, `ResourceVerifier`, for the importers and the paid product.** The importers run the same checks before a listing exists; the paid agent sells them. The checks: the live 402 and its `accepts` match the catalogue (`SellerProbe`); payTo not frozen by the token (`TokenBlacklist`); payTo not on OFAC's SDN list (Chainalysis' sanctions oracle, `0x40C5…C8fb`, `isSanctioned`, asked on Ethereum for every EVM address, since the list is the same everywhere: it answered on Ethereum, Polygon, Arbitrum, OP and Avalanche, not on Base, on 2026-11-06); payTo has received USDC before (Alchemy transfers, a count, informational); payTo or owner registered on ERC-8004 (8004scan, informational). Anything that cannot be asked is reported "unchecked", never as clean.
- **Paid by x402 in two parts, per URL checked.** $0.003 when the job is started (it returns an id; at most 20 URLs per job), $0.007 per URL when the report is fetched; a job not finished answers 202 and charges nothing; a job that failed charges nothing more. $0.01 per URL in all.
- **First the service with tests, then the paid route, then an MCP tool.** Built on `feat/catalog-importer`, in dev only; nothing is deployed without Leo's OK.

### quebracho_meilisearch gets its own repository

Decidido por Leo (2026-11-06): "puedes separar el repo del modulo quebracho_meilisearch? y lo comiteamos a un repo de github bajo quebracho? ... quizas en algun momento lo aportemos como contrib a drupal", and public when asked ("Público"). So the module is at https://github.com/quebracho-digital/quebracho_meilisearch (local clone `~/Proyects/quebracho_meilisearch`). Its history is the contrib module's up to our three proposals, then one commit with the rename; composer name `quebracho/quebracho_meilisearch`, and the README and `.info.yml` say it is a fork, not the official package. Its suite passes on its own (112 tests, 988 assertions, `scripts/test.sh`).

Not done yet: Ombú still carries its copy in `web/modules/custom/quebracho_meilisearch`; requiring it from the new repository by Composer, and the `.info.yml` name ("Quebracho Meilisearch"), follow after Sunday's deploy. Contributing it to drupal.org is Leo's call.

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

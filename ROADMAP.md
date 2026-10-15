# Roadmap — Ombú

**A mall where AI agents sell to people and to other agents**, paid in USDC
on Arc first and other chains after. Every listing is a Drupal Commerce
product with two doors: people check out, agents pay per call over x402.
People and agents search it the same way (facets), and both review what
they bought, with the purchase verified. Either way the money goes straight
to the seller's wallet, and the sale is settled only when the seller's
agent actually delivers.

Three tiers. Every item has a **done when** that can be checked. Inside 🏁 the
order is the priority: if time runs out, items are cut from the bottom, and
the cut line moves as we go.

- 🏁 **Tameion Agents Hackathon** (Arc × Canteen): submission closes
  **2026-10-17, 23:59 ET**. A working market with real sellers and buyers.
- 🎯 **Full marketplace**, after the hackathon (Circle Developer Grants).
- 🔭 **Later.**

## Where we left off — 2026-10-15

**Freeze for the submission** (Tameion closes 2026-10-17 23:59 ET; Leo is
in). Draft answers and video script kept outside the repo.
- **Reviews bought, not just paid** (`71b1ec6`, deployed): a tx backs a
  review only of the listing it bought (ours: the sale in `x402_pay_log`;
  Circle's: at least the price), one per tx by a unique key. Before, one
  ping let a buyer review all four of our listings (same cold wallet).
- **Buyer agent checks reputation and reviews** (`d752523`, deployed): free
  verified/rating in its search, checks an unrated seller's terms, reviews
  each purchase. Mainnet run on Cloudflare: *hire*, 0.03 USDC, 3 sales
  (log 26, 29, 30), 2 reviews by the agent. Leo's 4 past mainnet purchases
  reviewed by `scripts/review.mjs` (one ★1: the terms charged without a
  result on 10-14).
- **Long terms on a faster model**: over 20k characters the analysis runs
  on `$settings['ombu_long_terms_model']`. Waiting on Leo: a key (OpenRouter,
  amazee, Fireworks or Groq) in SER5's `deploy/.env` and the server.
- Twig debug was on in prod (kept from fixing the logo): off.

**Found**
- 🐛 Two settlements failed with `eth_sendRawTransaction: internal error`
  (log 27, 28): the buyer was not charged but the seller did the work (one
  a terms analysis). Retry the send once? Cause unknown (Arc RPC?).

## Before — 2026-10-14

**Live on Arc mainnet** (`bb27d98`, deployed to SER5)
- Our four listings charge on **Arc mainnet** (`eip155:5042`), paid to
  Leo's cold wallet `0x5b6A…73Bb` (`field_seller_address`). A cold address
  now wins over a seller key (`Sellers::payTo()`); the key only signs ping.
- Our facilitator settles mainnet (`own_networks: [eip155:5042]`, gas from
  Key `gas_wallet` = `0x2cC4…f9eF`, env `X402_GAS_KEY` in SER5's
  `deploy/.env`). Testnet still goes through Circle's API key.
- **First mainnet sales:** ping bought twice by `buy.mjs`
  (`.env.mainnet`, buyer `0xAa07…ebB1`), ~3 s each, settle ~1.7 s:
  tx `0x0d1109d4…f658` and `0x527ca946…24fa` on explorer.arc.io.
- **Gas is 0.0047 USDC a sale** (116,653 gas), not the 0.0017 estimated on
  testnet: ping's 0.0001 loses money on every sale. 1.97 USDC left, ~420
  sales. Seller fee (later) must cover it; ping could cost 0.01.
- Reviews: stars over the same field, plain-text form (no subject, no
  editor), a card in the column; the report link above the reviews
  (`0876306`).
- Repo public at github.com/quebracho-digital/ombu (pushed by Leo).

**Waiting on Leo**
0. 🐛 **A buyer charged without the result** (found 2026-10-14, mainnet):
   the terms analysis of DoraHacks' Terms of Use (88k chars) took over
   100 s; Cloudflare's tunnel answered 524 and the buyer left, but the
   handler finished and settled anyway (log id 25, tx `0xd5ac0f5f…62fa`,
   0.01 USDC). The privacy policy (30k chars, 56 s) went through: tx
   `0x921346c7…af44`. Fix: don't settle when the client is gone or the
   call outlived the proxy's limit (~95 s behind Cloudflare); long jobs
   are async (🎯 *Async jobs*).
1. ✅ **Arc Microgrants** (DoraHacks) submitted 2026-10-14 as "Ombu",
   under review; editable until judging.
2. ✅ `wallet_auth` is public on drupal.org (linked in the submission).
3. Legal, before Ombú leaves demo (see 10-13, item 5).

## Before — 2026-10-13

**Done today** (147 tests, uncommitted: review first)
- **Mainnet money without keys at risk (3g, A+B)** in `x402_pay`, on the
  branch `mainnet-wallets`. A settings form at `/admin/config/services/x402`:
  - **B, Circle API key**: the facilitator sends `Authorization: Bearer`
    instead of a seller proof, so a seller can be an **address alone**
    (field setting `pay_to`, `PriceEvent::setPayTo()`, ombu's
    `field_seller_address`): a cold wallet, no key on the site.
  - **A, keyless + sweep**: cron (or `drush x402:sweep [--dry-run]`) moves
    each seller key's balance, less the most its gas can cost, to a cold
    wallet. EIP-1559 transfers signed in PHP, equal to viem byte for byte.
    Tried on Arc testnet: markdown's 0.031 USDC swept, tx
    `0x0e1769ec…491e`, status 1, 48,938 gas.
  - **Arc mainnet** (`eip155:5042`) is a network now: checked on chain
    (RPC `rpc.mainnet.arc.io`, USDC `0x3600…`, domain `USDC`/`2`, 6
    decimals; gas is native USDC, 18 decimals).
- **Found:** Circle's keyless mode is a **trial with an allowance per
  `payTo` and network** (then `403 registration_required`). Mainnet needs B.
- **Circle Agent Marketplace's intake is a Google Form** that needs a
  Google login. Ombú lists without asking anyone: say so in the video.
- **Domain:** `quebrachos.digital` is quebrachOS's, not the product's. Move
  to `ombu.quebracho.digital` (and `quebracho.digital/ombu` redirecting
  there). Blocked on Leo: the DNS record and the tunnel change (below).
  `ombu.digital` is taken until 2026-10-16; a routine checks it 10-17.

**Later on 2026-10-13** (deployed to minisforum over ssh, not GitHub)
- `ombu.quebracho.digital` serves the site (CNAME, redirect, tunnel
  ingress, `additional_fqdns`). No managed challenge got in the way.
- Circle keys live in `.ddev/.env` (`CIRCLE_API_KEY_MAINNET`/`_TESTNET`),
  Key provider `env`. Never the `config` provider: it lands in exports.
- **Leak closed:** config sync was `sites/default/files/sync`, served to
  the internet (DDEV's nginx ignores `.htaccess`). Now `../config/sync`,
  git-ignored. The keys were never exported.
- x402 on minisforum: `api_key` mode with the **testnet** key; a testnet
  purchase settled (tx `0x1ddc8dfc…cf1f`).

**Evening 2026-10-13: our own facilitator** (`x402_pay` a6ef428)
- **Arc Microgrants** (dorahacks.io/hackathon/arc-microgrants): closes
  **2026-10-15 09:29 IST = 10-15 00:59 ART**, rolling review, needs a
  project *live on Arc mainnet* and a public repo. 500 USDC.
- Circle's LIVE key answers 403 everywhere, so `ChainFacilitator` settles
  the networks chosen in the settings itself: eth_call to verify, the
  buyer's `transferWithAuthorization` sent from a gas wallet to settle.
  Testnet: tx `0x35aefea9…c8a3`, 87,165 gas (~0.0017 USDC on mainnet).
- Fee: none for now (decided: free; a fee paid by sellers later, never
  custody of their sales).
- `ReviewVerifier` and `buy.mjs` know Arc mainnet (explorer.arc.io).
- minisforum: Key `gas_wallet` reads env `X402_GAS_KEY` (not set yet).
- **Betas tagged:** `x402_pay` 1.0.0-beta1 (new branch `1.0.x`, the empty
  `x402_pay_commerce` stub dropped) and `wallet_auth` 1.0.0-beta1. The
  release pages on drupal.org are Leo's (UI).

**Night 2026-10-13: the front door** (live on minisforum)
- `/market` filters update in place (AJAX + BEF auto-submit, now in the
  module's view: it had lived only in one site's active config).
- A header on `/market` (what Ombú is, *Try our sellers*, the guides), the
  site name as the mark, and four pages with menu links from
  `scripts/seed-pages.php`: `/how-it-works`, `/docs/agents`,
  `/docs/sellers`, `/about`. The recipe should ship them.
- Wallets made (keys never shown): gas `0x2cC4…f9eF` on minisforum
  (`X402_GAS_KEY`), buyer `0xAa07…ebB1` in `x402_pay/scripts/.env.mainnet`.
  Leo funds them from Binance on the Arc network (10-14).

**Late 2026-10-13: languages and markets** (live on minisforum)
- Phones: filters fold under one toggle; no sideways scroll.
- **Spanish** next to English (default, no prefix; `/es`), switcher in the
  top bar; market, guide and menu translated; core/contrib strings from
  localize.drupal.org, ours in `translations/es.po`.
- **Sellers translate their listings** (title, description, note) signed
  in with the wallet the listing pays, Circle's listings included; the rest
  is shared, so the sync keeps it right. The index has Search API's
  language-with-fallback: one card per listing per language.
- **Markets:** Circle's importer never overwrites or copies a listing that
  is not Circle's; it adds `circle` to `field_markets` ("Also on…").
  Built for more importers (Solana's or others, after the hackathon): each
  adds its own value, the badge lists them all.
- **For the submissions:** multilingual is a feature Circle's marketplace
  lacks: a seller listed there can complete and translate the listing here.
  Say it in both write-ups and the video.

**Night 2026-10-13: legal, abuse reports** (local only, not deployed)
- `scripts/seed-legal.php`: `/terms` and `/privacy` (en, es; Spanish
  prevails), created once as page nodes and never overwritten (edit them in
  Drupal). Refuses to run without `OMBU_LEGAL_NAME`, `OMBU_LEGAL_EMAIL`,
  `OMBU_LEGAL_ADDRESS`. Draft, **not reviewed by a lawyer**.
- Terms split who answers: our sellers (Quebracho) are sold by the
  operator; Circle's listings are an index; sellers and reviewers answer
  for what they publish. A withdrawn or dropped listing stays (retired,
  reviews open); only a takedown removes it.
- Core `contact`: `/contact/report` (anyone; each listing links to it with
  its URL) and `/contact/withdrawal` (withdrawal button, our sales only,
  refunded by hand to the paying wallet). Footer: terms, privacy, both forms.
- A Circle listing staff delete is not imported again (state
  `ombu.circle_blocked`, `blocked` count). Accounts: block, don't delete
  (a blocked wallet can't sign in; a deleted one signs up again).
- A demo notice on every page (`ListingHooks::pageTop`) until the legal
  side is settled. `ombu.module` is gone: its hooks live in
  `src/Hook/ListingHooks.php`, `ombu_rating()` is `Rating::of()`.

**Night 2026-10-13: production on SER5** (live)
- minisforum's iGPU shares RAM bandwidth with everything: a dense model
  (Lapacho's fact-check on Qwen3.8-27B) stalled Ombú's DB and PHP. Ombú
  moved to SER5 with `deploy/` (Docker Compose, no DDEV), on
  `192.168.1.66:8097`; minisforum's tunnel forwards `ombu.quebracho.digital`
  there over the LAN. The LLM stays on minisforum (sellers call it by LAN).
- Drush on prod: `cd /data/docker/ombu/src/ombu/deploy && docker compose exec
  -T web drush …`; deploy = `git push ssh://ser5/data/docker/ombu/src/<repo>`
  (`updateInstead`). Secrets in `deploy/.env`; the gas wallet goes there.
- Cron only on SER5. minisforum's DDEV stays in maintenance mode, as the
  rollback (restore `~/.cloudflared/config.yml.bak-2026-10-13-ser5`).
- The DB is in SER5's nightly backup (`servicios.toml`, `[ombu]`).

**Waiting on Leo** (for mainnet)
1. A gas wallet with 1–2 USDC on Arc mainnet: its key as `X402_GAS_KEY`
   in minisforum's `.ddev/.env`, then `ddev restart`.
2. A buyer wallet with ~1 USDC: `BUYER_PRIVATE_KEY` for a mainnet run of
   `buy.mjs` (a separate .env, so the testnet buyer stays).
3. Cold addresses for our sellers (`field_seller_address`).
4. Make `ombu` (and `wallet_auth`) public on GitHub: the grant asks for a
   public repo.
5. **Legal, before Ombú leaves demo:** operator (name, CUIT, address,
   email) to run `seed-legal.php`; a lawyer reviews terms and privacy;
   ask about CNV PSAV registration (our facilitator relays USDC
   transfers), AAIP database registration, an EU representative (GDPR art.
   27) and the withdrawal button's 24 h reference.

## Before — 2026-10-11

**Done today**
- The `x402_price` field (item 2): a node on `ombu.ddev.site` answers 402
  to agents on `/node/2` and `/x402/node/2`, browsers get the page with a
  `Link` header, and `buy.mjs` paid it 0.01 USDC on Arc testnet and got the
  markdown (tx `0x796ba101af3f92d311529b49fe5b33998d05a5f2a7b473fc5e10eb72f2466290`,
  status 1 on chain, `settled` in `x402_pay_log`).
- **Bug found by trying it, not by the tests:** the page cache stored the
  paid page on the second browser visit (the render cache skips view hooks)
  and then handed it to agents free. Fixed by deciding on the response;
  a functional test covers it and fails without the fix. Kernel tests
  cannot see the page cache: it ignores command-line requests.
- `x402_pay` was never enabled on the site: now it is. The site had no node
  type: `scripts/x402-demo-node.php` adds `article`, the seller key (env
  `SELLER_PRIVATE_KEY`, in the git-ignored `.ddev/.env`) and a paid node.

- **Afternoon: products, translations and the price event** (73 tests).
  Each `x402_price` field sells one view mode; several per bundle, never
  two for the same view mode, at most one on the canonical URL.
  `/x402/{type}/{id}/{view_mode}`, one `Link` each. Translatable field =
  price per translation. `PriceEvent` before every sale (change, free,
  deny) and the *Access x402 content free* permission. Delivery is only
  what the display configures: no byline, no title twice, no price.
  Paid on testnet: the summary of node 3 at 0.002 (tx
  `0x3855abe0…06bc`).
- **Two more bugs found by trying it on the site:** fields saved before
  `view_mode`/`canonical` existed had neither, so the canonical URL was
  **free for agents** (update 10002 fills them, with a test that
  reproduces it); and the form's conflict message was escaped twice
  (caught by a new functional test that drives the settings form).
- `x402_pay` published on drupal.org: `git.drupal.org:project/x402_pay`.
- **Where a paid request's time goes** (3 purchases of node 3's summary,
  `buy.mjs` + the gate's `Server-Timing`): total 4.4–8.2 s. **Settle
  3.6 s every time (80 %)**, verify 0.6 s (once 4.5 s), our handler
  11–21 ms, sign 5 ms, asking for the 402 100–270 ms, the rest ~70 ms. The
  plain page: 85–220 ms. Settle is probably Circle waiting for the chain
  (it answered in one call, never pending): an inference, not checked.

- **Item 2 done**: the catalogue is `/x402/discovery` (see item 2).
- **Item 3 done** (97 tests, x402_pay + ombu): our four listings sell over
  x402, each to its own wallet, each delivering its service. In x402_pay:
  `PriceEvent` chooses the seller, the mime type and the payment timeout;
  `DeliveryEvent` answers instead of the markdown; the catalogue quotes
  like the sale. `scripts/buy-ours.sh` read `/market.json` and bought all
  four on Arc testnet; each seller's balance rose on chain (ping +0.0001,
  markdown +0.001, terms +0.01, fact-check +0.02). The markdown seller
  asked for a private address answered 422, `not_settled`, +0 on chain.
- **Found on the way:** the buyer's signature lived 60 s, but a model
  seller answers in 25–45 s and settling comes after: a longer document
  would have made the sale fail after the work was done. Model sellers now
  give 600 s. And `/x402/discovery` listed `payTo` from the field's key,
  not the seller the sale would pay: now both ask `Delivery::quote()`.
- Ping at 0.0001 USDC settled: Circle's minimum is at or below that.
- **Review of item 3:** a listing whose seller key is unusable fell back
  to the field's key, so a key ever set on the field would have been paid
  for another seller's sale. Now it is not for sale (403, and no `accepts`
  in `/market.json`); a test fails without the fix.
- `x402_pay` passes phpcs (Drupal + DrupalPractice): every docblock says
  something, none left empty by phpcbf.
- **Public: `https://ombu.quebrachos.digital`** (minisforum, DDEV behind
  the Cloudflare tunnel; origin TLS verified against minisforum's mkcert
  CA; the hostname lives only in minisforum's `.ddev/config.public.yaml`).
  `quebrachos.digital` has no managed challenge, so no WAF rule was needed
  (`fishman.work` has one: agents get 403). `buy-ours.sh` against it
  bought all four on Arc testnet, each balance up on chain: terms
  `0x6cb949c0…f271` (27.5 s, the model 23.4 s), ping `0x5c468057…d70c`,
  markdown `0x6d625710…418d`, fact-check `0x8609fd52…febd6`. minisforum's
  copy is rsynced from the laptop: it does not follow git yet.
- **The catalogue, kept and faceted (3c)** (109 tests): providers are terms
  (site, docs, OpenAPI); each listing keeps the operation of its
  provider's OpenAPI that documents it (807 of 2,786; one fetch per
  provider per run, 36 specs, aisa's 52 MB over the 5 MB cap), shown on
  its page and searched. Payment rail (plain x402 / Circle Gateway) and
  SIWX imported. A listing updates when a hash of what Circle says
  changes, not only `lastUpdated`. What Circle drops is **retired**: page
  and reviews stay, buying and x402 do not, search leaves it out unless
  asked. Re-import every 6 h. `/market` has facets with counts (category,
  network by name, provider, payment, tags, seller); `/market.json` takes
  the same filters by name (comma lists, `retired=1`, `openapi=1`) and
  returns the counts. **Bug fixed:** tags went to the `tags` vocabulary,
  not the field's `listing_tag` (22,910 references moved).
- **One search for people and agents**: `/market` and `/market.json` take
  the same query string (facets by name; several values, any matches) and
  point to each other. The JSON lists each facet value with its count and
  the URL that adds it: an agent narrows by following links. 113 tests.
- **Agents find it**: `/llms.txt` (contrib `llms_txt`, text in
  `ombu/llms.txt.md`) and `/openapi.json` (the search with each filter's
  current values; our four sellers as paid operations, which is also what
  Circle's intake asks for), announced by `Link` headers on every page.
  114 tests.

**Next (in order)**
1. 2b, people paying in the browser: needs the MetaMask check below.
2. 3b, publish our listings to Circle's Agent Marketplace (its review is by
   hand: submit early).
3. 3c–3f, then 4, the buyer agent.

**Known gaps**
- The terms analyzer describes its findings in Spanish (its categories
  are written so): for an English-speaking jury, translate or let the
  buyer pick the language.
- The fact-check judges with the model alone (no Tavily key): honest in
  the answer (`"evidence": "model_only"`) and in the listing, but weaker
  than "against sources". A search key fixes it.
- **Markdownify (3f) can leak paid content:** its middleware turns
  `Accept: text/markdown` into `?_format=markdown` before the page cache,
  and it serves `.md` paths. Not tried. Install it, try both on a paid
  node, and block them for paid entities before 3f ships.
- The first drupal.org release of `x402_pay` needs a `1.0.x` branch.

## Before — 2026-10-09

**Done today**
- Two real x402 payments settled on Arc testnet through Circle's keyless
  facilitator (`x402_pay/scripts/arc-pay-check.mjs`; testnet keys in
  `x402_pay/scripts/.env`, git-ignored; buyer has ~19.98 USDC).
- `x402_pay` core with TDD: signing equal to viem byte for byte, v2
  headers, Circle client, the gate (settle only on 2xx), payment log.
  34 tests green: `ddev test` from `ombu/`.
- Ombú site: Drupal 11 + Commerce under DDEV (`https://ombu.ddev.site`,
  admin/admin), `ombu` module with listings, reviews, the Circle catalogue
  (2,791 imported, `drush ombu:import-circle`), search at `/market`,
  `/market.json` in Circle's Discovery shape, our 4 verified listings
  (`scripts/seed-listings.php`).
- Repos private under `quebracho-digital`: `ombu`, `x402_pay`. Names
  `ombu` and `wallet_auth` are free on drupal.org.

**Waiting on Leo**
1. **MetaMask check**: open
   `https://ombu.ddev.site/modules/custom/x402_pay/scripts/wallet-check.html`,
   connect, sign 0.01 USDC, then settle it:
   `node --env-file=.env arc-pay-check.mjs <payment>` in
   `x402_pay/scripts`. A 200 with an arcscan link confirms the people door.
2. **drupal.org**: create the projects `x402_pay` and `wallet_auth` (a
   minimal commit reserves them), and open (or check) the issue in the
   `x402` project queue to start its 14-day clock.

**Next (in order)**: done on 10-11, see above.

**Facts worth remembering**
- Circle's facilitator: keyless needs a seller proof signed by the `payTo`
  key; an API key binds every `payTo` it settles to our account; a 403 is
  the trial used up **or an amount under Circle's minimum** (unknown:
  Ping at $0.0001 may hit it).
- Circle's catalogue: 2,162 listings accept Arc mainnet but only 106 take
  plain x402; 2,056 are Circle Gateway (batched) only. None on Arc testnet.
- x402 has a Sign-In-With-X extension: the base for `wallet_auth`.
- `old/solana/x402` holds an earlier x402 field (nodes, global/individual
  mode), a Solana Pay Commerce gateway and an Anchor vault (`old/phay`).

## Decisions

| Topic | Decision |
|---|---|
| Who sells | AI agents, each with its own wallet. Ours first (terms, fact-check, page → markdown); other teams list theirs |
| Who buys | People through Commerce checkout, agents per call over x402. One catalogue, two doors |
| Custody | None. Each listing carries its seller's `payTo`; the facilitator pays the seller directly. No platform fee until the full marketplace |
| Buyer protection | For our sellers, the market is the resource server: it verifies the payment, calls the seller, and **settles only on a 2xx**. A seller that fails is not paid. Shown as *verified seller* |
| Third-party sellers | Settling needs the `payTo` key (keyless) or binds `payTo` to our Circle account (API key), so the market cannot settle for them. **Hackathon (A):** they run their own x402 endpoint; the market lists it and relays the buyer's payment header with the SSRF guard and a timeout; they settle. **Full marketplace (C):** each seller's `payTo` is an ERC-1271 smart account that accepts the market's signature for settling and lets only the seller withdraw, so every sale is settled on delivery. Listing costs a small x402 fee (anti-spam, and counts as traction), then waits for approval by hand |
| Circle Agent Marketplace | Every listing can be published there too. There is no submission API (manual review through a form, self-serve "coming"), so Drupal produces what the form asks for (the 402 endpoint, an OpenAPI spec per listing, the payout wallet), opens the form prefilled, and marks the listing *live on Circle* when the Discovery API returns it. Swap the form for the API when Circle ships it |
| Lean on Drupal | Write only what Drupal lacks: blockchain payments (x402, the Commerce gateway), wallet authentication, purchase verification. Everything else is contrib: Commerce (catalogue, checkout), Search API + Facets (search), Comment (reviews), Markdownify (pages as markdown for agents), LLMs.txt, MCP (agent tools), ECA, AI Agents |
| Identity | **A wallet, for people and agents alike.** One account can hold several wallets on several chains. EVM first (one address covers Arc, Base, Polygon: Sign-In With Ethereum), Solana later. People sign in their browser, agents in code. No email accounts |
| Reviews | **Only from verified buyers**, people or agents. A review carries the purchase: a tx hash for vanilla x402 (checked on chain: from the reviewer's wallet, to the listing's `payTo`, the right amount), or the payment nonce for Circle Gateway (batched: no per-purchase tx; checked through Gateway's transfer lookup). Works for purchases made outside the mall too |
| Agent interface | The same catalogue and facets as people get: `/market.json` (Circle Discovery shape plus facet counts), every page as markdown, `llms.txt`, and MCP tools (search, get, review) |
| Modules | `x402_pay` (receiving on Arc), `x402_pay_commerce` (gateway), `ombu` in this repo (listings, reviews, the Circle import, the agent door), a wallet-auth module (several wallets per account, several chains). The recipe builds the demo site. Repos under `quebracho-digital`: `ombu` (this one, the hackathon entry), `x402_pay`, `wallet_auth`: one per drupal.org project, not one per module |
| Name | Module: `x402_pay` (drupal.org `x402` maintainers never answered). The mall: **Ombú** (no chain in the name; "on Arc" as the tagline) |
| Paying | Our buyer agent is a script with Circle's x402 client and a testnet key; its budget is enforced in the script. Paying from Drupal waits for the full marketplace |
| The x402 field | `x402_pay` ships a field type (`x402_price`) that makes **any fieldable entity** payable over x402 by adding the field. Basic settings: on/off, price, network, seller key (`payTo` is derived from the key, never typed, so the money cannot go to a wallet that does not sign), per-entity override allowed or not. Advanced (collapsed): charge on the canonical URL, agent detection (`PAYMENT-SIGNATURE`, `Accept`, User-Agent list with regex), price per visitor type (training crawler, query agent, person), what is delivered (markdown, JSON, a view mode), free preview for people, how long a person's access lasts, payment timeout |
| Where it charges | **Both**: the entity's own URL for whoever counts as an agent (browsers still see the normal page), and a separate route `/x402/{entity_type}/{id}` announced with a `Link` header |
| Products | Each `x402_price` field sells one view mode at one price; a bundle can have several (full text, summary). Never two fields for the same view mode (one thing, two prices); at most one charges on the canonical URL (one URL, one price: x402's `accepts` lists ways to pay for one resource, not a menu). The rest at `/x402/{entity_type}/{id}/{view_mode}` |
| Price at request time | The field says what is sold; `PriceEvent` says what it costs now, with entity (in the request's language), view mode, request and account: change the price, make it free, deny. Translations price through a translatable field, no code. Per role, per visitor type, per anything: the event, and ECA on top of it later |
| Paying agents have accounts | An agent is not anonymous by nature: it signs in with its wallet (`wallet_auth`, 3d) and its account's roles count in the price event. To be priced by account it must identify before the 402 (x402's Sign-In-With-X); after paying it is known anyway, since the EIP-3009 signature is the payer's, so purchases attach to its wallet's account |
| Markdown | Ours: three lines over `league/html-to-markdown`, the same library Markdownify uses. x402_pay does not depend on Markdownify; if present, its converter may be used. What is delivered is what the display configures, never the entity's template |
| Catalogue | A View of every entity with the field, payable list for people and JSON export for agents, editable like any View. With Search API installed, an optional index-based version that spans several entity types in one catalogue |
| People | Pay with x402 in the browser too: one wallet signature, no gas. A purchase is recorded on the buyer's account (signed in with the wallet) and reopens the content; anonymous buyers get a signed cookie. The **people door of the hackathon**, instead of Commerce checkout |
| Commerce | An Arc USDC gateway (`x402_pay_commerce`) is still delivered, reusing the same browser signature, for carts of several items |
| Rules | ECA, not Rules (barely alive in D11) and not a home-made regex language: a "before charging" event (entity, request, visitor type, display) that can change the price, make it free or deny; `x402_pay_eca` exposes it to ECA |
| Renaming | Public identifiers use the protocol's name, not the module's: field type `x402_price`, routes `/x402/…`, `drush x402:…`. Internals share one prefix, so `x402_pay` → `x402` is one scripted `sed` until the first drupal.org release. The `x402` takeover runs in parallel (project queue, 14 days, then the ownership queue): it cannot land before 10-17 |
| Secrets | Wallet credentials via the `key` module from an environment variable, never in exportable config |
| Licence | `Apache-2.0 OR GPL-2.0-or-later` (drupal.org requires the GPL) |
| Hosting | Demo on minisforum through the Cloudflare tunnel, **Arc testnet only**. The market's hostname needs a WAF skip rule: agents cannot pass a managed challenge |

## 🏁 Hackathon

0. [ ] **Day-1 checks** (10-09/10). These change how everything is written.
   - A script pays a 402 endpoint on Arc testnet through Circle's facilitator.
   - A browser wallet signs the same EIP-3009 payment on Arc testnet (the
     people door depends on it).
   - **Done when:** both payments show on the Arc testnet explorer.
   - ✅ **Script, 2026-10-09** (`x402_pay/scripts/arc-pay-check.mjs`):
     0.01 USDC settled through the keyless trial in one `/settle` call, no
     pending, tx `0x2189f224…674d`. **Finding:** the keyless seller proof
     must be signed by the `payTo` key, and an API key binds every `payTo`
     it settles to our Circle account. The market cannot settle for
     third-party wallets: see *Third-party sellers*.
   - [ ] Browser wallet.
1. [x] **Terms analyzer, first slice** (`ai_provider_universal` 59758eb).
   - **Done:** Mercado Libre AR terms, 8/9 categories, 0 invented quotes.
2. [ ] **`x402_pay`, receiving** (10-10/11).
   - ✅ **Core, 2026-10-09** (`x402_pay` 4b0f112, 34 tests): EIP-712 and
     signing equal to viem byte for byte, the v2 headers, Circle's client
     (seller proof, polling a pending settlement), and the gate: verify →
     handler → settle only on 2xx → body only once settled; every outcome
     in `x402_pay_log`.
   - ✅ **The `x402_price` field, 2026-10-11** (60 tests): basic settings,
     `payTo` from the key, exact dollar → atomic conversion, the
     *Set x402 prices* permission, `/x402/{entity_type}/{id}`, the canonical
     URL with agent detection (browsers still see the page), delivery as
     markdown. Paid on Arc testnet by `x402_pay/scripts/buy.mjs`: tx
     `0x796ba101…6290`, `settled` in the log. Markdown only; JSON waits.
   - ✅ **The catalogue, 2026-10-11** (79 tests): `/x402/discovery`, every
     product of every entity type in Circle's Discovery shape, cached by
     list tags. **Not a View for agents**: a View covers one entity type
     and JSON from Views needs `rest`. People list with Views directly (the
     field gives a *Charge for this* filter and the price formatter); in
     Ombú that list is `/market` once listings have the field (item 3).
   - **Done when:** a node with the field answers 402 to an agent on both
     URLs and a browser still sees the page; a viem script pays it on Arc
     testnet and gets the markdown; the tx is on arcscan and in the log; a
     handler failing with 502 is not settled; the View lists it.
2b. [ ] **People pay in the browser** (10-11/12). A *Pay $0.01* button, one
   MetaMask signature, the content shows; the purchase is recorded and
   reopens it (account or cookie). Depends on the browser-wallet day-1 check.
   - **Done when:** a person pays a node on Arc testnet with MetaMask,
     reloads, and still sees it without paying again.
3. [x] **Market, agent door** (10-12; done 10-11). Listings get the `x402_price`
   field; Ombú answers the paid call with the service itself through the
   field's delivery hook. Listing = Commerce product with
   price, `payTo`, seller endpoint and description. `GET /market.json` is
   the catalogue agents read; `POST /market/{listing}` is the x402 door.
   Our three sellers listed, each with its own wallet.
   - ✅ **2026-10-11**: the door is `/x402/commerce_product/{id}/full`
     (x402_pay's route), not `/market/{listing}`: one door for every paid
     entity. Four sellers, not three (Ping too). Each listing names its key
     (`field_seller_key`) and its service (`field_service`); the field's
     price is the listing's. `scripts/buy-ours.sh` met the done-when.
   - **Done when:** a script reads the catalogue, buys each of the three,
     and each seller's wallet receives its USDC; a seller made to fail gets
     nothing.
3b. [ ] **Publish to Circle's Agent Marketplace** (10-12). Per listing:
   `GET /market/{listing}/openapi.json`; a *Publish to Circle* action that
   opens the intake form prefilled; a cron that checks the Discovery API and
   marks the listing *live on Circle*. Submit our three sellers the same
   day: the review is by hand and its time is unknown.
   - **Done when:** the three are submitted; each turns *live* on its own
     when Circle approves it. Open: does the catalogue list testnet
     services, and do its health checks pass the WAF skip rule?
3c. [~] **Faceted search** (10-12). Done 10-11 except rating and price range. Facets on category, chain, price range,
   payment rail (vanilla x402 or Circle Gateway: only vanilla is payable
   from a plain wallet), provider, rating, verified seller. The same facets
   as `/market.json` parameters, with counts in the response.
   - **Done when:** a person and a script narrow the 2.8k listings to the
     same result with the same filters.
3d. [ ] **Wallet sign-in** (10-13). Sign-In With Ethereum for people and
   agents; several wallets per account.
   - **Done when:** a person signs in with MetaMask, an agent with a key in
     a script, and both show as accounts.
   - ✅ **Agents, 2026-10-12** (`wallet_auth` b9d106b, 12 tests):
     `/wallet/nonce` → SIWE message → `POST /wallet/login`; the account is
     named by the address. `wallet_auth/scripts/sign-in.mjs` signed in to
     ombu.ddev.site. Accounts link more wallets at `POST /wallet/link`
     (CSRF token; one account per wallet). Ombú's registration is now
     *visitors*, so a new wallet opens an account.
   - [ ] **People**: *Sign in with a wallet* on `/user/login` with MetaMask
     (Leo). Open: `/user/register` with email is open too now; hide it?
3e. [x] **Verified reviews** (10-13; done 10-12). People through the form, agents
   through a signed `POST`; accepted only with a purchase the market can
   verify (tx on chain, or Gateway nonce).
   - **Done when:** a review with our day-1 tx is accepted, one with a tx to
     another wallet is refused, and the average counts only verified ones.
   - ✅ **2026-10-12**: a review's tx must hold a USDC Transfer, status 1,
     from one of the reviewer's wallets (wallet_auth) to whoever the listing
     pays (our seller's key, or a Circle listing's `payTo`); one tx, one
     review. A field constraint, so the form and the agents' `POST
     /market/listing/{id}/reviews` (SIWE session + CSRF) check the same.
     Arc testnet, Base and Base Sepolia (public RPCs); Gateway payments
     cannot be checked yet. The average counts only reviews with the paying
     wallet, which only the check sets. On ombu.ddev.site the buyer agent
     reviewed Markdown with its tx `0x4e753bed…cbcf` (201, ★5), the same
     buyer's tx to the terms seller was refused (422), and the first tx
     again too (422). In `/openapi.json` and `/llms.txt`.
3f. [x] **Pages as markdown + `llms.txt`** (10-13). `llms.txt` done; markdown
   moves to `x402_pay` (one gate, see 🎯 *Markdown for every page*).
   - **Done when:** `curl -H 'Accept: text/markdown'` on a listing returns
     markdown.
4. [x] **Buyer agent** (10-13; done 10-12). Given a task ("should we hire vendor X?") and
   a budget, it reads the catalogue, buys what it needs (the terms of X, a
   fact-check of X's claims), and decides *hire / reject / ask a human*,
   with reasons and the tx hashes it spent.
   - **Done when:** it has run on Quebracho's real vendors (Cloudflare,
     Brevo, Telefónica, Circle) within budget, and a run that would go over
     budget stops before paying.
   - ✅ **2026-10-12** (`scripts/agent.mjs`, ombu `09880a2`, `2be0d1c`):
     reads `/openapi.json`, each paid service is a tool, an LLM on
     minisforum picks what to buy; the budget is checked before signing.
     Each reason cites the purchase it rests on, uncited ones are flagged.
     Budget 0.05 USDC each: Brevo *ask_human* (0.022: French law, Paris
     courts, liability capped at 6 months), Cloudflare *hire* (0.025),
     Telefónica *ask_human* (0.023: termination without notice for
     "incorrect use"), Circle *hire* (0.044, with a fact-check). Brevo at
     0.005: the 0.01 analysis refused unpaid, *ask_human*.
     **Found by it:** fetch gives up at 300 s and a seller could settle for
     a buyer that left (x402.mjs now waits `maxTimeoutSeconds`); the 27B
     took 5+ min per terms page (sellers on Qwen3.6-35B-A3B, ~5x faster);
     the markdown seller dropped the footer, where the terms are linked
     (it now returns those links). Open: one settlement failed with
     `valid_before` ~6 min into a 600 s window; the buyer was not charged,
     cause unknown.
3g. [~] **Mainnet, with no money at risk on the server** (10-13). A Circle
   API key settles for sellers that are only an address (cold wallets);
   keyless sellers are swept to a cold wallet on cron. See 2026-10-13.
   - **Done when:** our three sellers sell on Arc mainnet to cold wallets,
     and the listings are submitted to Circle (3b) with mainnet endpoints.
5. [ ] **Third-party sellers** (10-14). `POST /market/listings` with name,
   price, `payTo` and endpoint, paid over x402; lands unpublished for
   approval. Posted in the Arc Discord.
   - **Done when:** at least one listing and one sale that are not ours.
6. [ ] **Commerce gateway: Arc USDC** (10-15). `x402_pay_commerce`: checkout
   signs with the browser wallet (the same code as 2b) and the order is paid
   on Arc. Delivered even if the demo does not use it.
   - **Done when:** an order with two items is paid on Arc testnet and goes
     to *completed* with the tx hash on the payment.
6b. [ ] **MCP tools** (10-16): search, get, review, through the contrib
   MCP module. Last before the cut: likely to fit, first to drop.

   ✂️ *cut line: move it as we go*

7. [ ] **Recipe** (10-16). `drush recipe` on a clean Drupal gives the market
   with our three sellers.
8. [ ] **Submission** (10-16/17, cannot be cut). Public repo, video under 3
   minutes, the Google Form, and traction from the payment log: sales,
   USDC moved, sellers that are not ours. Quebracho is the author's own
   business, and the submission says so.

## 🎯 Full marketplace

- [x] **Markdown for every page, through one gate** (done 2026-10-13 in `x402_pay`;
  replaces Markdownify
  in 3f). `x402_pay` answers `Accept: text/markdown` for any entity: 402
  when it has a price, the markdown free when it has none (or `PriceEvent`
  makes it free). Its converter is the one Markdownify uses, so nothing is
  added. **Coexisting:** the gate decides by *entity*, not by route or
  format: any route that carries a priced entity (canonical, `.md`,
  `?_format=`, JSON:API, REST) goes through it, so another module serving
  markdown or JSON cannot hand out what agents pay for. If another module
  is installed, it may format; only `x402_pay` decides who pays. **Access:**
  the gate runs after routing's access check (priority 30 < 32), so what
  someone cannot see is never offered for sale, and paying grants nothing
  beyond what view access allows. Responses vary by `Accept`
  (`Vary: Accept`, never in the page cache), Cloudflare included.
  - **Done when:** a test asks a priced node for markdown by `Accept`,
    `.md` and `?_format=markdown`, with the caches warm, and gets 402 every
    time; a free node returns markdown; an unpublished one, 403.
- [~] **Events where others need to react** (ECA and other modules,
  instead of patches). Only where Drupal announces nothing: entity
  operations (a takedown = deleting a listing, a report or withdrawal = a
  `contact_message`, retired = `field_retired` changing, a verified review =
  a comment with `field_wallet`) are already hooks and ECA events.
  - [x] `x402_pay`: `PaymentEvent`, every outcome in the log (settled, not
    settled, failed).
  - [x] `wallet_auth`: `WalletEvent` (signed in, linked, refused because
    the account is blocked), with the address.
  - [ ] When they exist: a refund sent (the withdrawal button, by hand
    today), a markdown alter before delivery (if a module needs it).
- [ ] **Discount coupons, for people and for agents** (Leo, 2026-10-13;
  after the hackathon). The first use: Lapacho's fact-check plugin calling
  Ombú's fact-check (the web-backed one, `ai_provider_universal_factcheck`)
  with a coupon instead of paying full price per claim; then promotions
  for any seller. People already have coupons through Commerce
  (`commerce_promotion`). Agents get the same coupons over x402: a code in a
  request header, checked by an ombu subscriber to `PriceEvent` against
  those promotions, which lowers the price or makes it free (`PriceEvent`
  already can). One coupon entity for both doors, no second system.
  - **Limits, with what Drupal has (Leo, 2026-10-13):** how often a coupon
    can be used, in total and per customer, is `commerce_promotion`'s own
    usage limits, set per promotion in its UI; a wallet counts as the
    customer through `wallet_auth`'s account. Abuse (an agent retrying
    codes for free) is core's Flood API, the throttle behind failed logins
    and the contact form: failed codes per wallet and per IP, thresholds in
    config; the contrib *Flood Control* module shows and clears them.
  - **Open:** the header's name; whether a 100 % coupon still goes through
    x402 (a zero-price settle) or skips it.
  - **Done when:** an agent sending a valid code pays the discounted price
    on Arc (the wallet's balance shows it), an expired or used-up code pays
    full price, and a person's checkout takes the same code.
- [ ] **Terms and privacy generator for each store** (idea, 2026-10-13;
  after the hackathon): the inverse of the terms analyzer. Drupal already
  knows much of what a policy must say: enabled modules (analytics,
  contact storage, payment gateways = processors), the fields on users,
  profiles and orders (what data is collected), the store's name, address,
  currencies and countries, cookies set. A questionnaire fills the rest
  (who sells, consumers, EU users, retention). Clauses come from a library
  written or reviewed by a lawyer, per jurisdiction (AR, EU, general); the
  model only chooses and adapts them, never invents law. The analyzer
  checks the draft (19 categories of `terms-eval/taxonomy.json`: protective
  ones present, severity "alta" flagged) and a deterministic checklist the
  mandatory legends (AAIP, withdrawal button).
  - **A base for the store's lawyer**, not the final text: everything
    important the lawyer needs, gathered from Drupal and the questionnaire.
    Ombú's own general terms already protect Ombú.
  - **Liability stays with the store:** it is a drafting tool, not legal
    advice, and says so; every draft lists what it does **not** cover; the
    store confirms its answers and the draft before publishing, and the
    answers are kept with the version published. Ombú's terms say each
    store's terms are the store's. Ask a lawyer whether it touches the
    practice of law in Argentina, and about insurance.
  - Not trained on `terms-eval` (an evaluation set, never for training).

- [ ] **Abuse reports and takedowns, the full version**: reports as
  records with a state (new, reviewing, removed, dismissed) instead of
  emails; a report link on reviews, replies and seller content too; notice
  to the person affected, with a way to answer; takedown of a seller's
  listings and wallets together; a public count of takedowns. Today:
  `/contact/report` by email, staff delete by hand, deleted Circle
  listings stay out of the import.
- [ ] **Faster payments**, in this order (measured 10-11: settle 3.6 s and
  verify 0.6 s of a ~4.4 s purchase; ours is ~0.1 s):
  1. **Verify locally**: recover the EIP-3009 signer, check balance and
     nonce over RPC; skip Circle's verify. No new risk: settle checks it
     all again. Saves ~0.6 s and verify's outliers.
  2. **Deliver before settling, for accounts with credit**: settle in the
     background. Needs `wallet_auth`: the payment's `from` must be one of
     the account's wallets, and unpaid debt per account is capped (a
     failed settle stops its credit; clean history raises the cap).
     Changes "body only once settled" for those accounts. ~4.4 s → ~0.8 s;
     with 1, ~0.2 s.
  3. **Prepaid**: only through Circle Gateway (the buyer deposits at
     Circle, payments batch offchain), never a balance of ours: custody
     stays none.
  - An agent that knows the price (from `/market.json`) can already send
    the payment on its first request and skip the 402: x402 allows it.

- [ ] **Our own facilitator** (`x402_pay`): verify and settle on chain
  ourselves, with no Circle account. `transferWithAuthorization` can be
  sent by anyone, so the site needs only a gas wallet holding cents; every
  `payTo` stays a cold wallet. The decentralised default for anyone who
  installs `x402_pay`; Circle (A+B, 3g) becomes one option among others.
  Builds on `Transfer` (EIP-1559 signing) and "verify locally" above.
- [ ] **Purchases on the account, every edge case**: an anonymous buyer
  without the cookie is recognised by the wallet when they pay again (no
  double charge), and their earlier purchases attach to the account when
  they sign in.
- [ ] **`x402_pay_eca`**: the "before charging" event in ECA.
- [ ] **Price per visitor type and per display**, if not finished in 2.

- [ ] **Seller accounts**: an agent and its operator sign up, manage their
  listings and see their sales. Who is legally the seller to a person.
  - ✅ **Started 2026-10-12** (`361f582`): the seller is whoever signs in
    with a wallet the listing pays; they edit a note, images, videos and
    files (nothing the catalogue sync writes) and reply to reviews (only
    they can; replies never count). Missing: their sales, YouTube links.
  - ✅ **My listings, 2026-10-14**: `/my-listings`, a View of the
    listings that pay one of the account's wallets.
- [ ] **Unlinking wallets: the process and what it costs** (asked
  2026-10-14). Today only support unlinks (`unlink wallets`, admin only);
  `unlink own wallets` exists but no one has it. Before granting it, decide:
  - What the account loses: seller rights over the listings that wallet is
    paid to, reviews it can still write (its purchases), its past reviews
    and replies (kept, but whose are they now?).
  - What the wallet gets back: it can sign in again, open a new account or
    be linked to another one, taking the listings with it. Is that a
    transfer of the business, and who approves it?
  - Abuse: a stolen session unlinking the owner's wallets; an account left
    with no wallet and no password (locked out for good).
  - Support's side: how they check who asks (sign with the wallet? with
    another wallet of the account?), and an audit trail (`WalletEvent`
    `unlinked` is there to log it).
  - **Done when:** the rules are written in the FAQ and the terms, and the
    form enforces them.
- [ ] **Platform fee**: a split at settlement (contract or two transfers).
- [ ] **Reputation** from the payment log: delivered, failed, refused.
- [ ] **An API for other apps** (asked 2026-10-14): mobile apps and other
  websites build on Ombú, doing whatever a person does on the site. Not for
  agents: they already read the markdown and `/market.json`. Core's JSON:API
  gives the reads (listings, reviews, categories, translations) almost for
  free, and `x402_pay`'s gate already covers it, so priced content is still
  paid for. Missing: search with the market's filters (Search API's
  endpoint), the writes (review, report, withdrawal, the seller's note,
  images, translations and replies) with the same rules as the forms, a
  sign-in for apps (the wallet's, ending in a token: `simple_oauth` or
  similar), and CORS for other websites.
  - **Done when:** every form on the site has its call, documented, and a
    test makes each one as an app would, signed in with a wallet.
- [ ] **Recommendations on a listing's page** (asked 2026-10-14): listings
  related to the one being seen, chosen by what they do, not only by
  category or tag: embeddings of title, description and schemas (the AI
  module's AI Search: a Search API backend on a vector database), plus what buyers of this
  one also bought, from the payment log, once there are sales. The same
  list for agents, in the listing's markdown and its endpoint.
- [ ] **Async jobs**: listings that take minutes, paid upfront into escrow
  and released on delivery; refunds and disputes.
- [ ] **Paying from Drupal**: site agents buy within hard rules (caps per
  window, allowed sellers, a human queue above a threshold), from a Circle
  Agent Wallet, pushing the rules to the wallet when the chain enforces them.
- [ ] **More products**: fact-check as a page for people, Fabric patterns as
  digital goods, Lapacho calling the market for documents not in its
  catalogue.
- [ ] **Courses, with Drupal LMS** (asked 2026-10-14). Anyone writes a
  course and lists it in the market, for people and for agents; a course is
  one more listing (facets, reviews, wallet sign-in). First our own, free:
  how to use Ombú, how to list a service, how an agent buys. Then anyone's,
  sold, the money straight to the author's wallet and the reviews only from
  who paid. Module: the contrib `lms` (Opigno is out, decided 2026-10-14).
  - **What is sold:** a lesson, a whole course, or a whole certification
    (several courses). Bundles and access that lasts go through Commerce,
    with traditional and crypto gateways (the Commerce Arc USDC gateway,
    item 6, plus a card one); `x402_pay` stays for one lesson per call.
  - **Certificates on chain:** whoever completes a course or a
    certification, person or agent, gets an NFT certificate in their wallet
    (the one they signed in with), checkable by anyone. Open: the contract
    (soulbound or transferable), who pays the mint's gas, and what
    "completed" means for an agent (passing the activities, as a person).
  - **Agents and interactive lessons:** lessons as markdown work for text;
    lessons with JavaScript or H5P don't. Open: a text version per activity
    (H5P content is JSON, so questions and answers can be extracted), an API
    for answering activities, or those lessons marked *people only*.
  - **Done when:** a free course of ours is in the market, an agent takes
    it as markdown, passes it and gets the NFT certificate in its wallet;
    a course by someone else is sold on Arc, the author's wallet gets the
    USDC, and the buyer reviews it.
- [ ] **Importers set up from the admin, not in code** (asked 2026-10-14).
  Today `CircleCatalogImporter` has Circle's URL, its shape and "take it
  all" hardcoded. Instead, each source is configured: the catalogue URL,
  its market value (`field_markets`), how often, and filters that pick what
  comes in: category, tags, network, price range, provider, a regex on the
  name or the endpoint, any field of the item. A store can import only the
  Circle listings in its niche; Solana's or another Discovery catalogue is
  one more source, not one more class. What stays in code: upsert by
  endpoint + method, the hash, never touching a listing that is not the
  source's, the blocked list.
  - **The filters as a View**: `views_json_source` (contrib) makes a remote
    JSON a View, so the filters are Views filters (core's string filter
    already has a regex operator) and adding a parameter is adding a filter
    in the UI, no code. The importer runs the View and upserts its rows.
    Open: does `views_json_source` filter before or after paging, and on
    2.8k items is it fast enough? Fallback: Feeds + Feeds Tamper (sources,
    JSONPath mapping and skip rules, all config).
  - **Done when:** two sources configured in the UI (all of Circle, and
    Circle with only one category and a name regex) import what each
    filter says, with no code change, and today's tests still pass.
- [ ] **Solana** (`x402_pay_solana`, Solana wallet sign-in), then mainnet.
- [ ] **drupal.org releases**: `x402_pay` and the terms submodule.

## 🔭 Later

- [ ] Move from minisforum to a VPS.
- [ ] Terms analyzer: severity per finding, versioned catalogue with "what
  changed since you accepted".
- [ ] Paying without revealing who paid (Privacy Pass or similar).

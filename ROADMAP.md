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

## Where we left off — 2026-10-11

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
3e. [ ] **Verified reviews** (10-13). People through the form, agents
   through a signed `POST`; accepted only with a purchase the market can
   verify (tx on chain, or Gateway nonce).
   - **Done when:** a review with our day-1 tx is accepted, one with a tx to
     another wallet is refused, and the average counts only verified ones.
3f. [ ] **Pages as markdown + `llms.txt`** (10-13). Contrib only.
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

- [ ] **Purchases on the account, every edge case**: an anonymous buyer
  without the cookie is recognised by the wallet when they pay again (no
  double charge), and their earlier purchases attach to the account when
  they sign in.
- [ ] **`x402_pay_eca`**: the "before charging" event in ECA.
- [ ] **Price per visitor type and per display**, if not finished in 2.

- [ ] **Seller accounts**: an agent and its operator sign up, manage their
  listings and see their sales. Who is legally the seller to a person.
- [ ] **Platform fee**: a split at settlement (contract or two transfers).
- [ ] **Reputation** from the payment log: delivered, failed, refused.
- [ ] **Async jobs**: listings that take minutes, paid upfront into escrow
  and released on delivery; refunds and disputes.
- [ ] **Paying from Drupal**: site agents buy within hard rules (caps per
  window, allowed sellers, a human queue above a threshold), from a Circle
  Agent Wallet, pushing the rules to the wallet when the chain enforces them.
- [ ] **More products**: fact-check as a page for people, Fabric patterns as
  digital goods, Lapacho calling the market for documents not in its
  catalogue.
- [ ] **Solana** (`x402_pay_solana`, Solana wallet sign-in), then mainnet.
- [ ] **drupal.org releases**: `x402_pay` and the terms submodule.

## 🔭 Later

- [ ] Move from minisforum to a VPS.
- [ ] Terms analyzer: severity per finding, versioned catalogue with "what
  changed since you accepted".
- [ ] Paying without revealing who paid (Privacy Pass or similar).

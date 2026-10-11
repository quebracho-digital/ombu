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

**Next (in order)**
1. The catalogue View (rest of item 2).
2. 2b, people paying in the browser: needs the MetaMask check below.
3. Item 3: listings get the field. Their delivery is the seller's service,
   not a view mode: the view mode is the default delivery, a hook will
   replace it.

**Known gaps**
- **Markdownify (3f) can leak paid content:** its middleware turns
  `Accept: text/markdown` into `?_format=markdown` before the page cache,
  and it serves `.md` paths. Not tried. Install it, try both on a paid
  node, and block them for paid entities before 3f ships.
- phpcs fails on `x402_pay`, including the 10-09 code: fix before the
  first drupal.org release (which also needs a `1.0.x` branch).

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
   - [ ] **The catalogue View** (and the Search API version when present).
   - **Done when:** a node with the field answers 402 to an agent on both
     URLs and a browser still sees the page; a viem script pays it on Arc
     testnet and gets the markdown; the tx is on arcscan and in the log; a
     handler failing with 502 is not settled; the View lists it.
2b. [ ] **People pay in the browser** (10-11/12). A *Pay $0.01* button, one
   MetaMask signature, the content shows; the purchase is recorded and
   reopens it (account or cookie). Depends on the browser-wallet day-1 check.
   - **Done when:** a person pays a node on Arc testnet with MetaMask,
     reloads, and still sees it without paying again.
3. [ ] **Market, agent door** (10-12). Listings get the `x402_price`
   field; Ombú answers the paid call with the service itself through the
   field's delivery hook. Listing = Commerce product with
   price, `payTo`, seller endpoint and description. `GET /market.json` is
   the catalogue agents read; `POST /market/{listing}` is the x402 door.
   Our three sellers listed, each with its own wallet.
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
3c. [ ] **Faceted search** (10-12). Facets on category, chain, price range,
   payment rail (vanilla x402 or Circle Gateway: only vanilla is payable
   from a plain wallet), provider, rating, verified seller. The same facets
   as `/market.json` parameters, with counts in the response.
   - **Done when:** a person and a script narrow the 2.8k listings to the
     same result with the same filters.
3d. [ ] **Wallet sign-in** (10-13). Sign-In With Ethereum for people and
   agents; several wallets per account.
   - **Done when:** a person signs in with MetaMask, an agent with a key in
     a script, and both show as accounts.
3e. [ ] **Verified reviews** (10-13). People through the form, agents
   through a signed `POST`; accepted only with a purchase the market can
   verify (tx on chain, or Gateway nonce).
   - **Done when:** a review with our day-1 tx is accepted, one with a tx to
     another wallet is refused, and the average counts only verified ones.
3f. [ ] **Pages as markdown + `llms.txt`** (10-13). Contrib only.
   - **Done when:** `curl -H 'Accept: text/markdown'` on a listing returns
     markdown.
4. [ ] **Buyer agent** (10-13). Given a task ("should we hire vendor X?") and
   a budget, it reads the catalogue, buys what it needs (the terms of X, a
   fact-check of X's claims), and decides *hire / reject / ask a human*,
   with reasons and the tx hashes it spent.
   - **Done when:** it has run on Quebracho's real vendors (Cloudflare,
     Brevo, Telefónica, Circle) within budget, and a run that would go over
     budget stops before paying.
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

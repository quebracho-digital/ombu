# Roadmap

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
| Modules | `x402_pay` (receiving on Arc), `x402_pay_commerce` (gateway), `x402_pay_market` (listings, reviews, the Circle import, the agent door), a wallet-auth module (several wallets per account, several chains). The recipe builds the demo site. Repo: [`x402_pay`](../x402_pay) |
| Name | Module: `x402_pay` (drupal.org `x402` maintainers never answered). The mall: **to decide**, generic enough for other chains |
| Paying | Our buyer agent is a script with Circle's x402 client and a testnet key; its budget is enforced in the script. Paying from Drupal waits for the full marketplace |
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
2. [ ] **`x402_pay`, receiving** (10-11). Price and `payTo` come from the
   caller, not from config, so the market can set them per listing.
   - **Done when:** unpaid POST → 402; paid → 200, logged with its tx hash;
     a 502 is not settled.
3. [ ] **Market, agent door** (10-12). Listing = Commerce product with
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
6. [ ] **People door** (10-15). `x402_pay_commerce`: checkout signs with the
   browser wallet, the order goes to the seller's agent, and the result
   shows on the order page.
   - **Done when:** a person buys a terms analysis and reads it on the order.

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

# Roadmap

Drupal modules that let a site **charge** for an API with x402 and let its AI
agents **pay** x402 services within hard spending rules, plus the first paid
service: a terms-of-service analyzer that quotes every clause it reports.

Three tiers. Every item has a **done when** that can be checked. Inside 🏁 the
order is the priority: if time runs out, items are cut from the bottom, and
the cut line moves as we go.

- 🏁 **Tameion Agents Hackathon** (Arc × Canteen): submission closes
  **2026-10-17, 23:59 ET**.
- 🎯 **Circle Developer Grants milestones**, after the hackathon.
- 🔭 **Later.**

## Decisions

| Topic | Decision |
|---|---|
| Who pays | AI agents and developers, per call over x402, with no account |
| Modules | `x402` (base) plus one submodule per chain family: `x402_evm` (Arc, Base, Polygon: the same EIP-3009 scheme), `x402_solana` later. Third-party facilitators; for receiving, only an address per network |
| Paying from Drupal | Yes, from a Circle Agent Wallet. **Two layers of rules:** Drupal always checks before signing (per-hour/day/week/month caps, allowed recipients, a human-approval queue above a threshold), and pushes the same rules to the wallet when the chain enforces them natively. Each chain plugin declares which rules it enforces; Drupal enforces the rest |
| Secrets | Wallet credentials via the `key` module from an environment variable, never in exportable config |
| Analyzer | `ai_provider_universal_terms`, a submodule of [ai_provider_universal](https://github.com/leofishman/ai_provider_universal), next to its fact-check. x402 stays a separate project that integrates easily |
| Charging | Settle a payment **only on a 2xx response**: a failed analysis (502) costs nothing |
| Licence | `Apache-2.0 OR GPL-2.0-or-later` (drupal.org requires the GPL) |
| Name | drupal.org `x402` exists but is empty (since 2025-11); co-maintenance requested on 2026-10-07. Without an answer by 2026-10-10, `x402_pay` |
| Hosting | Demo on minisforum through the Cloudflare tunnel, **Arc testnet only**. A VPS once it is serious |

## 🏁 Hackathon

0. [ ] **Day-1 checks.** These change how `x402_evm` is written.
   - A script pays a 402 endpoint on Arc testnet through Circle's facilitator.
   - Does the Agent Wallet have an HTTP API, or only the Circle CLI?
   - Are spending policies enforced on testnet? Is there an hourly window?
   - **Done when:** one paid request returns 200 and its transaction shows on
     the Arc testnet explorer; the three answers are written here.
1. [x] **Terms analyzer, first slice** (`ai_provider_universal` 59758eb).
   `POST /api/terms/analyze` with `{"text"}` or `{"url"}`, measured prompt,
   invented quotes dropped, cache by document hash.
   - **Done:** Mercado Libre AR terms, 8/9 categories, 0 invented quotes;
     0.9 s when cached.
2. [ ] **Demo site.** A Composer project (`ai`, `ai_agents`, `eca`, `key`,
   `ai_provider_universal`) on minisforum, behind the Cloudflare tunnel.
   - **Done when:** the analyzer answers at a public URL.
3. [ ] **`x402` + `x402_evm`, receiving.** Price per route in config; the 402
   response carries the payment requirements; verify and settle through the
   facilitator; settle only on 2xx; a payment log (route, amount, payer,
   tx hash).
   - **Done when:** an unpaid POST gets 402; a paid one gets 200 and is
     logged with its tx hash; a request that fails with 502 is not settled.
4. [ ] **The analyzer behind x402, with a warm cache.** Pre-analyze the terms
   of the vendors Quebracho really uses (Cloudflare, Brevo, Telefónica,
   Circle), so the demo answers at once.
   - **Done when:** the four are cached and each paid lookup answers in under
     2 s.
5. [ ] **Offer the endpoint to other teams.** Post price, URL and an example
   in the Arc Discord: vendor and compliance agents need it.
   - **Done when:** it is posted and at least one payment comes from a wallet
     that is not ours.
6. [ ] **`x402_evm`, paying.** Drupal pays x402 services from the Agent
   Wallet. Rules checked in Drupal before signing (caps per window, allowed
   recipients), a queue for a human above a threshold, and the same rules
   pushed to the wallet policy when Circle enforces them.
   - **Done when:** a payment over a cap is refused before signing, one
     above the threshold waits in the queue until approved, one under both
     is paid, and all three are logged.
7. [ ] **Vendor and compliance agent** (`ai_agents`). Given a vendor (name,
   terms URL, address, amount), it buys the terms analysis, decides *pay /
   reject / ask a human* with its reasons, pays within the rules, and
   records the decision.
   - **Done when:** it has run on Quebracho's four real vendors and the four
     decisions, with reasons and tx hashes, are in the log.

   ✂️ *cut line: move it as we go*

8. [ ] **Submission** (cannot be cut). Public repo, video under 3 minutes,
   the Google Form, and the traction answers: real usage and USDC moved,
   counted from the payment log. Quebracho is the author's own business,
   and the submission says so.

## 🎯 Grant milestones

- [ ] **`x402_solana`**: the same receive/pay contract on Solana; rules
  Solana does not enforce stay in Drupal.
- [ ] **Drupal Commerce payment gateway**: USDC checkout over x402.
- [ ] **Commerce recipe with agents**: orders, treasury, vendors and
  compliance (using the terms analyzer), on top of the gateway.
- [ ] **Fact-check behind x402**: the `ai_provider_universal_factcheck`
  verifier as a paid endpoint.
- [ ] **drupal.org releases**: `x402` (or `x402_pay`) and the terms submodule.
- [ ] **Lapacho plugin**: the Lapacho clipboard/keyboard calls the paid API
  for documents that are not in its offline catalogue.

## 🔭 Later

- [ ] Move from minisforum to a VPS; mainnet.
- [ ] Terms analyzer: settings form, severity per finding (once measured),
  the versioned catalogue with "what changed since you accepted", lookups
  by hash prefix.
- [ ] Paying without revealing who paid (Privacy Pass or similar).
- [ ] More chains, each declaring which rules it enforces.

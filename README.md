# quebracho-agents

A market where AI agents sell to people and to other agents, paid in USDC
on Arc: people check out through Drupal Commerce, agents pay per call over
x402, and every sale is settled only when the seller's agent delivers.

Built by [Quebracho Digital](https://quebracho.digital). Plan and status:
[ROADMAP.md](ROADMAP.md).

## Pieces

This repo is the demo site and the plan; the modules live in their own
repos so each can be used alone.

- **`x402_pay`** (own repo, coming): x402 receiving on Arc, the Commerce
  gateway and the market.
- **[`ai_provider_universal_terms`](https://github.com/leofishman/ai_provider_universal)**:
  the terms and privacy analyzer, the first seller.

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).

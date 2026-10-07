# quebracho-agents

Drupal sites that charge AI agents over x402, and Drupal AI agents that pay
x402 services within hard spending rules. The first paid service reads a
vendor's terms of service before an agent pays it, quoting every clause it
reports.

Built by [Quebracho Digital](https://quebracho.digital). Plan and status:
[ROADMAP.md](ROADMAP.md).

## Pieces

This repo is the demo site and the plan; the modules live in their own
repos so each can be used alone.

- **`x402`** (own repo, coming): HTTP 402 payments for Drupal, receiving
  and paying, one submodule per chain family (`x402_evm` first: Arc, Base,
  Polygon).
- **[`ai_provider_universal_terms`](https://github.com/leofishman/ai_provider_universal)**:
  the terms and privacy analyzer, `POST /api/terms/analyze`.

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).

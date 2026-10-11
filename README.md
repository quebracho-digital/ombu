# Ombú

The agent mall: AI agents sell to people and to other agents, paid in USDC
on Arc (other chains next). People and agents search it with the same
facets, sign in with their wallets, and review only what they verifiably
bought. Ombú lists its own verified sellers next to the whole Circle Agent
Marketplace catalogue.

Built by [Quebracho Digital](https://quebracho.digital) on Drupal. Plan and
status: [ROADMAP.md](ROADMAP.md).

## Pieces

- **This repo**: the site (Drupal 11 + Commerce, DDEV), the `ombu` module
  (`web/modules/custom/ombu`: listings, reviews, the Circle catalogue
  import, `/market` and `/market.json`), the seed script and the plan.
- **[`x402_pay`](https://github.com/quebracho-digital/x402_pay)**: x402
  payments on Arc and the Drupal Commerce gateway.
- **`wallet_auth`** (coming): sign in with one or more wallets, on one or
  more chains.
- **[`ai_provider_universal`](https://github.com/leofishman/ai_provider_universal)**:
  the terms analyzer and the fact-checker, two of the sellers, and the
  guarded page fetch the markdown seller uses. Mounted from its repo.

## Our sellers

Four listings Ombú runs itself, each paid to **its own wallet**. Agents
`POST` JSON to `/x402/commerce_product/{id}/full`; `/market.json` and
`/x402/discovery` give the URL, price and `payTo`.

| Service | Input | Price |
|---|---|---|
| Ping: a timestamp signed by the seller | `{}` | 0.0001 |
| Web page to markdown | `{"url": …}` (public addresses only) | 0.001 |
| Terms and privacy analysis | `{"url": …}` or `{"text": …}` | 0.01 |
| Fact-check a claim (model only for now: `"evidence": "model_only"`) | `{"claim": …}` | 0.02 |

Input it cannot use (400), a page it may not fetch (422), a failing model
(502) or one not set up (503) cost the buyer nothing: x402_pay settles only
on a 2xx. The model sellers give the buyer 10 minutes to pay instead of 60
s, since settling comes after their answer.

`scripts/buy-ours.sh` is a buyer agent's round: it reads `/market.json` and
buys each of them on Arc testnet, printing every seller's balance on chain
before and after.

## Run it

```sh
git clone https://github.com/quebracho-digital/x402_pay ../x402_pay
git clone https://github.com/leofishman/ai_provider_universal ../ai_provider_universal
# .ddev/.env (git-ignored): one testnet key per seller, and the LLM key.
#   SELLER_KEY_PING=0x…  SELLER_KEY_TERMS=0x…  SELLER_KEY_FACTCHECK=0x…
#   SELLER_KEY_MARKDOWN=0x…  LLAMA_API_KEY=…
ddev start && ddev composer install
ddev drush si standard --site-name=Ombú -y
ddev drush en ombu search_api search_api_db ai_provider_universal_terms ai_provider_universal_factcheck -y
ddev drush php:script ../scripts/sellers-llm.php    # the model behind two sellers
ddev drush php:script ../scripts/seed-listings.php  # our four listings
ddev drush ombu:import-circle && ddev drush sapi-i
ddev test                                           # x402_pay and ombu
```

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).

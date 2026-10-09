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
- **[`ai_provider_universal_terms`](https://github.com/leofishman/ai_provider_universal)**:
  the terms and privacy analyzer, one of the sellers.

## Run it

```sh
git clone https://github.com/quebracho-digital/x402_pay ../x402_pay
ddev start && ddev composer install
ddev drush si standard --site-name=Ombú -y
ddev drush en ombu search_api search_api_db key -y
ddev exec SELLER_PAY_TO=0xYourWallet drush php:script ../scripts/seed-listings.php
ddev drush ombu:import-circle && ddev drush sapi-i
```

## Licence

`Apache-2.0 OR GPL-2.0-or-later`, at your choice: [LICENSE-APACHE](LICENSE-APACHE),
[LICENSE-GPL](LICENSE-GPL).

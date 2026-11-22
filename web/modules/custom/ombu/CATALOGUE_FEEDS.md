# The catalogue feeds

Ombú takes listings from three x402 catalogues: Circle's Agent Marketplace,
Coinbase's CDP Bazaar and PayAI. Each one is a [Feeds](https://www.drupal.org/project/feeds)
feed type with Ombú's own fetcher, parser and processor. Feeds runs the
import (pages, batch, cron queue, lock, the clean stage, events); Ombú's
plugins hold the rules (what a listing is, what is checked, what retires).

The importers that came before (`CircleCatalogImporter`,
`DiscoveryCatalogImporter`, their drush commands and tests) are still in the
module, untouched: they are the reference the feeds are compared with, and
Circle's cron still runs its importer, not its feed. They go once the feeds
replace them (see *Switching over*).

## What is where

| Piece | Class | What it does |
|---|---|---|
| Feed types | `config/optional/feeds.feed_type.ombu_{bazaar,payai,circle}.yml` | One per catalogue: the address, the plugins, the processor's settings. Not scheduled (`import_period: -1`). |
| Feeds | `Catalog\CatalogFeeds::feed()` | One per catalogue, created the first time it is asked for (update 10039 creates the three). Feeds are content, not config. |
| Fetcher `ombu_catalog` | `Feeds\Fetcher\CatalogFetcher` | `GET {url}?limit&offset`, one page per step (100), https only, 10 MB per page at most, an optional key from the Key module in a header. An empty or broken answer is an error. |
| Parsers `ombu_discovery`, `ombu_circle` | `Feeds\Parser\DiscoveryParser`, `CircleParser` (base `CatalogParserBase`) | An entry as Ombú's shape (`normalize()`); what cannot be a listing is counted (`unsupported`, `skipped`); a resource listed twice in a run is read once; `max` stops the run. |
| Processors `ombu_discovery_listing`, `ombu_circle_listing` | `Feeds\Processor\DiscoveryListingProcessor`, `CircleListingProcessor` (base `ListingProcessorBase`) | The checks and the listing: verification, wallets, budget, coherence (Discovery); ours, OpenAPI, providers, logos (Circle); the clean list, the guards, retiring. |
| The run | `Catalog\CatalogRun` | A Feeds state that lives one run: counts, reasons, what was read, whether the run is whole. |
| Start of a run | `EventSubscriber\CatalogFeedSubscriber` | On `feeds.init_import` (fetch): takes the run's options, refuses a second import of the same catalogue. |
| Settings schema | `Hook\CatalogFeedHooks`, `config/schema/ombu.schema.yml` | Feeds types every processor's settings as its entity processor's; this types them by processor, so ours keep theirs. |
| Drush | `Drush\Commands\CatalogFeedCommands` | `ombu:feed-import`, `ombu:feed-report`. |

## Running an import

By drush, the whole run in one request, with the counts printed:

```sh
ddev drush ombu:feed-import circle
ddev drush ombu:feed-import bazaar --max=300
ddev drush ombu:feed-import payai --only-tags=ai,inference --max-age-days=30
ddev drush ombu:feed-report            # the last run of each, however it was started
```

The options are for that run only; the feed type's processor holds the
defaults (`budget` 200, `min_transfers` 1, `only_tags`, `price_match`,
`max_age_days`), editable at `/admin/structure/feeds`. Bazaar and PayAI need
`ALCHEMY_API_KEY`, as the importers did: without it the run fails before
reading a page.

Feeds' own ways run the same plugins and leave the same report: the feed's
page (*Import*, as a batch), `drush feeds:import <fid>`, and cron. For cron,
set the feed type's *Import period* (Circle: 6 hours, as its importer's cron
today); Feeds then queues the feed and imports it page by page in its
queue. Run cron from the system, not `automated_cron`.

## The rules the feeds keep

Everything the importers checked, and where it lives now (the table in
DECISIONS.md, *The catalogue importers are built on Feeds*, maps each one to
its test):

- **One import of a catalogue at a time**: Feeds locks the feed; the run
  also waits for the importer of the same catalogue
  (`ombu.{source}_import`) and for any other feed of it.
- **Only a whole run retires**: the last page was read, no `max`, no error,
  every parsed item processed. A partial run (`--max`) retires nothing and
  does not move `ombu.{source}_last`.
- **The guards**: an empty or broken answer stops the run (nothing retired);
  a catalogue that reads nothing, or less than half of the last whole run
  (`ombu.{source}_read`), retires nothing and says so in `retire_skipped`.
- **Retired, never deleted**: a listing the catalogue drops stays published
  and open to reviews, not for sale; it comes back if the catalogue lists it
  again. A listing staff delete stays deleted (`ombu.{source}_blocked`).
- **One source per processor**: it never touches another source's listings.
- **Discovery**: only what Ombú verified itself is listed (live 402, freeze
  list, sanctions, USDC received), within a budget per run; an unchanged
  entry is asked again after a week (`MAX_AGE`); a listing that names another
  wallet is retired and noted for staff (`drush ombu:wallet-changes`, the
  dashboard), who may accept it (`DiscoveryListingProcessor::acceptWallet()`).
- **Circle**: one OpenAPI fetch per provider per run, through the guarded
  fetch; our listings that Circle lists are marked `circle`, never
  overwritten, and unmarked when it stops listing them; providers get their
  site's icon.

## What changed from the importers

- **Circle has the guards now.** Its importer read `items ?? []`: a 200
  without items was an empty catalogue and retired every Circle listing,
  and nothing stopped a catalogue that shrank by half. The feed stops on an
  empty or broken answer, and a shrunk catalogue retires and unmarks
  nothing. `CircleCatalogFeedTest` shows both, next to the importer doing
  the opposite on the same answer.
- **Circle reads a resource listed twice once**, as Discovery did.
- **An error in one item no longer aborts the run**: it is counted
  (`failed`), logged, and the run retires nothing.
- **Feeds' entity events** (`feeds.process_entity_prevalidate`, `presave`,
  `postsave`) run on every listing saved, with the catalogue item.
- **The catalogue address is configuration** of the feed type (https only),
  not a service argument; a key for a catalogue that wants one comes from
  the Key module.
- **The last report is kept** (`ombu:feed-report`), whoever ran the import.
- Logo file names take a plain host name only.

Not used on purpose: Feeds' field mapping and tampers. A listing is written
from what Ombú verified (the live 402, the provider's spec), not copied
field by field from the catalogue, and mapping raw catalogue fields would
skip those checks. A site that wants to change a listing as it is saved
listens to the entity events above.

## Comparing a feed with its importer

The tests run the same fixtures through both and compare every run's
counts and every listing's fields:

```sh
ddev test --filter 'CatalogFeedTest|CatalogImporterTest|CatalogParserTest'
```

`testTheFeedAndTheImporterLeaveTheSame` in `DiscoveryCatalogFeedTest` and
`CircleCatalogFeedTest` is the comparison. Against the real catalogues, in
dev only, a partial run of each one after the other should leave the second
with everything `unchanged` (same hash, same values), in either order:

```sh
ddev drush ombu:import-circle --max=40 && ddev drush ombu:feed-import circle --max=40
ddev drush ombu:import-catalog bazaar --max=15 && ddev drush ombu:feed-import bazaar --max=15
```

## Adding a catalogue

A catalogue that answers the x402 discovery shape (`items[]` with
`resource` and `accepts`, paged with `limit` and `offset`) is a new feed
type, no code:

1. `/admin/structure/feeds/add`: fetcher *x402 discovery catalogue* (its
   https address; a header and a key if it wants one), parser *x402
   discovery*, processor *Verified x402 listing* with a new `source` (it
   becomes the listings' source and market).
2. Add the source to the `allowed_values` of `field_source` and
   `field_markets` (as update hooks do for the others).
3. `ddev drush ombu:feed-import <source> --max=50`, then a whole run.

Then add its blocked-key handling in `ListingHooks::delete()` (today it
blocks `circle`, `bazaar` and `payai`), and, for the dashboard, its label in
`Dashboard\Vitals::imports()` and `WalletChanges::SOURCES`. A catalogue with
another shape needs a parser of its own (extend `CatalogParserBase`,
implement `normalize()`); different rules need a processor of its own
(extend `ListingProcessorBase`).

## Switching over

Not done yet; it is Leo's call (see the todo):

1. Circle: set the feed type's import period to 6 hours, remove the
   importer's cron from `ListingHooks::cron()`.
2. Point `WalletChanges::accept()` and `SellForm` (which writes through
   `CircleCatalogImporter::upsert()`) at the processors.
3. Remove the importers, their commands and tests; keep the feeds' tests.

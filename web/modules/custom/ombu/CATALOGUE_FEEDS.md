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
- **Circle's v1 entries are read too.** The feed reads the v2 endpoint
  (`amount`); v1 answers `maxAmountRequired` instead. Both paths read either
  field now, with `amount` as what the price comes from
  (`testTheV1AmountIsThePrice`), so a feed pointed at v1 does not import
  nothing.
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

## Review of 2026-11-22: known gaps and how to close them

A Drupal review read the feeds against the real Feeds 3.2.0 code. The first three highs and M3 were checked again by hand against the code; A2 was not run as a test. A security audit with the `security-audit-quebracho` method (one pass, each finding reproduced with a minimal test and checked by a second agent) came back afterwards; its findings are in the next section. No finding is critical or high. A1, A3, M3, M4 and M5 are fixed, and so are the audit's seven findings (see *Fixed on 2026-11-26*); M2, M6, M7 to M9 and the later list are not.

Fix before the feeds replace the importers (cheap, and each one can break a run or let a third party's data through):

| Id | Problem | Fix | Test to add |
|---|---|---|---|
| A1 | `DiscoveryParser::normalize()` runs without a guard. An entry with `"accepts": "x"` throws a `TypeError`; Feeds catches only `\Exception`, so the feed can stay locked on every later run. Circle checks `is_array`, Discovery does not. | Wrap `normalize()` in `try { } catch (\Throwable)` inside `CatalogParserBase::parse()` and count the entry as unusable. Check the type of `accepts`, `extensions`, `serviceName`, `method` and `description` in `DiscoveryParser`. | Entries with `accepts` as a string and `extensions` malformed |
| A3 | A short page, or a catalogue with no `pagination.total`, is taken as the whole catalogue, and that allows retiring everything not read. The pointer also moves by `page_size` even when the server returned fewer items. | Move the pointer by `count($items)`. Finish only on an empty page, or when `offset + count >= total` and `total` is known. Without a `total`, go on while pages come full, with a cap on pages; at the cap the run is not whole. | A server that clips `limit`; an answer with no `pagination` |
| M3 | The Circle parser takes any string as `resource` (no `https://`) and does not check `amount`. | Same `https` rule as Discovery. Accept only digits as `amount`, and a known network and asset; anything else is `unsupported`. | `http://`, `file:` and `amount` as `"abc"` or negative |
| M4 | The fetcher follows up to 3 redirects, and the configurable header (an API key) can leave for another host. | `allow_redirects => FALSE`: a discovery catalogue has no reason to redirect. If one does, an `on_redirect` callback that accepts only the same host. | A redirect to another host |
| M5 | `max_bytes` is applied after Guzzle downloaded the whole body. | `stream => TRUE` and a bounded read, plus an `on_headers` that refuses by `Content-Length`. | A body above the limit |

Fix soon:

- **M2, one failing item blocks retirement.** Keep the rule: a run with an error never retires, because it fails safe. Remove the cause instead: the validation of A1 and M3 turns the entries that fail today into `unsupported`. Add one line to the run report naming the failing entry, so a blocked retirement is visible.
- **M1, offset paging shifts when the catalogue changes during a run.** Retire a listing only after it is missing from two whole runs in a row (a counter in state). It also covers what is left of A3.
- **M6, an exception while drawing logos runs `finishImport()` twice and leaves the feed locked.** Wrap the work after a whole run in `try/catch` inside `postProcess()`, log it and go on. Better: move `logos()` to its own cron step.
- **A2, "Delete items" of Feeds.** The review feared it would delete the listings of a source. By reading, it does not: `ListingProcessorBase` extends `ProcessorBase`, does not implement `ClearableInterface` and has no `clear()`, and Feeds skips plugins that are not clearable. Write the test that runs it and shows that the listings stay.
- **M7, M8, M9.** Inject the services instead of the container. Collect the wallet notes in memory and write them once in `postProcess()` (today each item rewrites the whole array). Keep the OpenAPI specs in `cache.default` with a short TTL, not in the plugin instance.

Later, with the importers retired:

- L1 log a failed or skipped retirement as `warning` or `error`, not `info`. L2 a drush option to accept a catalogue that really shrank (`ombu.{source}_read` never moves while the guard holds). L3 take the run's errors from the report, not from comparing the messenger before and after. L4 normalise dedupe keys (host in lower case, no fragment, no trailing slash); do it after the importers go, because the keys are shared with them. L5 cap the keys of `reasons`. L6 hash in the parser and carry only the hash. L7 write the provider term description as `plain_text`. L8 an atomic lock per source if a second feed of the same source is ever created. Add `feeds` to the dependencies of `config/optional/*`, a `^https://` constraint to the fetcher's `url` in the schema, and say in the docs that update 10039 does not reverse.

Wallet screening, a decision for the owner:

- The OFAC check in `ResourceVerifier` covers EVM addresses only (Chainalysis oracle). A Solana `payTo` has no OFAC check, before and after this change. The fix is to screen against the SDN list file for the chains the oracle does not cover; check first that the list carries Solana addresses.
- Neither the Circle importer nor the Circle feed screens a wallet when it imports. The cheap fix is to run the local allow and deny lists (`ScreeningLists`) for every source. Running the full verifier for Circle too costs network calls per listing.

## Security audit of 2026-11-22: seven findings and their fixes

The audit assumed the seller who writes the catalogue is hostile. It found no XSS and no SSRF that a seller can reach, and nothing that makes Ombú pay a wallet that is not the one verified: Ombú does not pay the listings it imports, and the x402 payment comes from the endpoint's own 402. Most findings already existed in the importers; two are new with the feeds (the Feeds lock that stays taken after a fatal error, and the retirement guard now applied to Circle too). It was one pass, which by the method finds about half of what a full run would. The two I read in the code myself are marked.

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | Medium | **`$ref` fan-out in Circle's OpenAPI** (`OpenApi::inline()`, read by hand). It limits depth (6) but not nodes, so a schema with K properties that each point back to it grows as K^6. A spec of about 700 bytes exhausted a 1 GB memory limit; one of 463 bytes left a 5 MB `field_openapi`. A fatal is not caught, so the Circle import never ends and the lock stays taken. YAML anchors make it worse. | Cap nodes or bytes in `inline()` (past the cap, return the `$ref` unexpanded). Do not download the spec again when the entry did not change. |
| 2 | Medium | **A poisoned Bazaar or PayAI entry locks the feed** (`DiscoveryParser.php:38`; same as A1 of the review). Feeds catches only `\Exception`, so `finishImport()` is not called and the lock stays (12 hours by default, freed with `drush feeds:unlock`). | `is_array` as Circle does, and `normalize()` inside `catch (\Throwable)`, counted as `unsupported`. |
| 3 | Medium | **Circle: an entry with another seller's URL and method rewrites that listing.** The first entry wins the dedupe, and the processor writes without checking. The victim's listing kept its id and its reviews but got the attacker's `payTo` and title. The importer let the last entry win. | If an entry carries a `payTo` different from the existing Circle listing, do not update: note it for staff, as `wallet_changed` does for Discovery. |
| 4 | Medium | **Circle: any seller rewrites another seller's provider term** (`CircleListingProcessor::provider()` and `ListingProcessorBase::term()`, read by hand). The term is found by name in a vocabulary shared with Bazaar, PayAI and Ombú's own sales, and its site, docs, OpenAPI URL and description are overwritten. The victim's "full OpenAPI" link then points to the attacker, and the logo fetched from that site stays. | Identify the term by source and host, or let Circle fill only empty fields, as Discovery does. |
| 5 | Medium | **Bazaar and PayAI: an earlier duplicate entry retires another seller's verified listing** (`DiscoveryListingProcessor.php:146-147`, `:190`). Result seen: `rejected 1, retired 1`. A cheaper variant uses an `accepts` on a token Ombú does not verify: `unsupported`, and the victim is retired with no request made. The importer did the same. | Dedupe after processing, or keep an entry that is not the listing's owner from taking it off the kept list. |
| 6 | Low | **Circle: one entry that fails processing stops all retirement for the source** (`amount: "abc"`, a `network` that is not a string). It fails safe, and it is the M2 of the review. | Validate in `CircleParser` (https, digits in `amount`, string fields) and count the entry as `skipped`. |
| 7 | Low | **The "less than half" guard works as a ratchet.** `ombu.{source}_read` moves only on runs that could retire: after a run of 8 entries, three runs of 2, 1 and 1 read less than half and retired nothing. It is L2 of the review. | Compare against a median or against accepted entries, and give staff a command to reset the baseline. |

What the audit checked and found sound: only the admin can edit a feed (no role has Feeds permissions in `config/sync`) and the fetcher ignores the feed's own `source`; "Delete items" does not delete listings (the processor is not `ClearableInterface`, which closes A2 of the review by reading, still without its test); `SellerProbe` goes only to a public IPv4, pins the resolved IP, follows no redirects and reads 64 KB; the OpenAPI goes through `PageFetcher`, which checks the IP at each hop and cuts at 5 MB; logos go to a fixed Google host with a sanitised file name; title and body are `plain_text`, schemas render in `<pre>` as plain text, and the docs link is built only for `^https?://`; in Bazaar and PayAI a new wallet is rejected and the listing retired until staff accept it, and a `payTo` that is not EVM stays `unchecked` and is not imported, so it fails closed.

Not covered: whether CDP Bazaar, PayAI and Circle validate their entries, which decides how likely findings 2 to 5 are; the production `memory_limit` and how the Feeds cron queue behaves after a fatal; the rest of the module (purchases, MCP, review verification). Circle's feed makes no wallet check at all, like the importer, so its listings stay `field_verified = FALSE`.

### Order of work

1. Findings 2, 1 and 6 with A3, M3, M4 and M5 of the review: they break or stall a run and take little code. **Done.**
2. Findings 3, 4 and 5: one seller changes another seller's listing. Add a test for each with the same entries the audit used. **Done.**
3. Finding 7 and the rest of the review's list. **Finding 7 done; the rest waits.**

### Fixed on 2026-11-26

| # | What changed |
|---|---|
| 1 | `OpenApi::inline()` keeps a budget of 10000 nodes (`OpenApi::MAX_NODES`): past it a `$ref` is left unexpanded, so a fan-out cannot grow the operation (test: `testRefFanOutIsBounded`). `CircleListingProcessor::operation()` caches it in `cache.default` by the entry's hash, so an unchanged entry does not download the spec again, and an operation over 256 KB is not stored. |
| 2 | `CatalogParserBase::parse()` wraps `normalize()` in `catch (\Throwable)` and counts the entry as unusable, so nothing escapes Feeds' `\Exception` handling and leaves the feed locked. `DiscoveryParser` checks the type of `accepts`, `extensions`, `bazaar`, `info` and of every scalar it copies (tests: `testDiscoveryRefusesMalformedEntries`). |
| 3 | A resource listed twice in one run with different wallets is a conflict, not a duplicate: neither entry is written (the first is dropped before it is processed when both are on the same page) and one marker item has the processor take down the listing the run itself wrote (tests: `testOneResourceWithTwoWalletsListsNeither`, `testAConflictOnALaterPageTakesDownWhatTheRunWrote`). The `wallet_changed` guard stays: an entry naming another wallet than the listing declared never updates it. |
| 4 | Circle's provider term is filled, never overwritten, and a term that already points to another site is neither changed nor linked to the entry's listing (test: `testAnotherSellersProviderTermIsNotRewritten`). |
| 5 | Same as 3 for Bazaar and PayAI: the pair is dropped instead of letting one entry retire the other's listing. What a genuine entry that fails now still does is retire (its own listing), as before (`testListingThatFailsTheChecksNowIsRetired`). |
| 6 | `CircleParser` requires https, string fields, an amount of digits (`ctype_digit`), a known network and a non-empty asset; anything else is `skipped` and never reaches `Calculator::divide` (tests: `testCircleRefusesMalformedEntries`). |
| 7 | A catalogue that reads less than half is trusted once it says the same twice in a row (`ombu.{source}_read_next`), so the reference can come down and retirement is not frozen for good (test: `testCatalogueThatShrinksByHalfRetiresNothing`). The importer got the same rule, so both leave the same state (`testTheFeedAndTheImporterLeaveTheSame`). |

A3, M3, M4 and M5 of the review went in the same pass: the pointer moves by the items the server actually returned, the run completes only on an empty page or at `pagination.total` (a cap of 10000 pages leaves the run partial), a redirect is followed only to the same host, and `max_bytes` is enforced on the headers and while reading.

The audit's files (`findings.json`, the coverage ledger and the proof scripts) are outside the repository, in the owner's `security-audits/ombu/run-1/`.

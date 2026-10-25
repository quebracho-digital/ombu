# Decisions

## TL;DR

- Why Ombú does what it does, and what it chose not to do yet.
- The process: leaks first, a failing test, an independent audit, the
  smallest fix at the root.
- Drupal's access checks are never bypassed, not even to help agents.
- What is not done yet goes to the [ROADMAP](ROADMAP.md) with its reason.
- The x402 module's own decisions are in
  [x402_pay's DECISIONS](https://git.drupalcode.org/project/x402_pay/-/blob/1.0.x/DECISIONS.md).

## How we decide

1. **Leaks and production errors first.** Anything that gives away what is
   sold, shows what a visitor may not see, or breaks pages comes before any
   feature or release.
2. **Prove it.** A test fails on the old code before the fix lands.
3. **Audit everything.** A reviewer who did not write the change reads it.
   Critical and High are fixed before the commit; Medium are fixed or
   written down; Low are judged.
4. **The smallest fix at the root**, with deliberate shortcuts marked
   `ponytail:` in the code. Bigger work goes to the roadmap.
5. **Visible and the same for everyone.** Nothing is shown only to bots or
   only to people.
6. **Leo decides** scope, releases, and what is published (pushes to this
   public repo, drupal.org). Claude proposes, with a recommendation.

## 2026-10-25

### Agents keep reading markdown, with nothing hidden

Every free `.md` page opens with one line saying that every page reads as
markdown. Its links to pages that read free point to their `.md`. The
footer links this page's `.md` and `/llms.txt`.

- **Rejected:** the whole page as markdown in a hidden `div`. It looks like
  prompt injection or cloaking. Ombú carries sellers' text, which would
  reach agents unseen by people. It also doubles every page's weight.
- **Why visible:** what an agent reads from a page is its text, often a
  summary of it, not its `<head>`.

### A link goes to `.md` only where the reader may go

Each link is matched by the access-aware router, as whoever reads it. A
page they may not see keeps its link unchanged.

- **Why:** never bypass Drupal's access checks. A `.md` link must not
  reveal that a page exists, or what it holds.

### The footer is a block plugin, not a menu or a custom block

The link changes per page: this page's `.md` or none, the market with its
search, the language prefix. A menu link or a custom block holds fixed
text. It is still a block, so you place, move or hide it in *Block layout*.

### Caching: the URL, not a context

The page cache and Cloudflare key by URL. A user or agent cache context
only reaches Drupal's render caches. A separate `.md` URL is the fix every
cache understands.

- **Rejected for now:** caching what is sold. Sold pages are sent
  `private` until there is a cache that varies by payment (roadmap: *A
  cache that is safe for what is sold*).

### Not exposed to x402_pay's JSON:API and Markdownify leaks

Ombú has no JSON:API, REST or Markdownify, and keeps it that way until it
needs one of them. The leaks were fixed in x402_pay for 1.0.0-beta2 anyway,
since other sites use those modules.

### Meilisearch upstream: three issues, three stacked merge requests

The three features (vectors provided by Drupal, a vector query, "More like
this") go to drupal.org as one issue each. Each issue gets its own merge
request, one commit each, stacked on a single branch.

- **Why:** the maintainer can accept one and decline another.
- **Why:** the third one breaks the API (the backend's constructor), while
  the first two only add to it.
- **Why:** smaller reviews, and credit per feature.
- **Order:** the second and third depend on the first. They wait,
  postponed, until it is in.
- **Rejected:** one issue with one merge request, where a "no" to any part
  stops all of it.

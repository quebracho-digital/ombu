# Meilisearch for Drupal

The official [Meilisearch](https://www.meilisearch.com) backend for Drupal's [Search API](https://www.drupal.org/project/search_api): self-hosted or Meilisearch Cloud, with Views, Facets and highlighting.

## Requirements

- Drupal 10.3 or 11, PHP 8.1+
- Search API 1.39+
- Meilisearch 1.16+ (self-hosted) or a Meilisearch Cloud project

## Installation

The module is in alpha, so Composer needs the `@alpha` flag:

```bash
composer require 'drupal/meilisearch:^1.0@alpha'
drush en meilisearch -y
```

Using Meilisearch Cloud? See [`docs/guides/meilisearch-cloud.mdx`](docs/guides/meilisearch-cloud.mdx).

## Try it

[Meili Kitchen](https://github.com/meilisearch/drupal-meilisearch-demo) is a recipe search demo built with this module: clone it and run `docker compose up --build`.

[![Meili Kitchen: a typo-tolerant recipe search with highlights and facets](https://raw.githubusercontent.com/meilisearch/drupal-meilisearch-demo/main/docs/screenshot.png)](https://github.com/meilisearch/drupal-meilisearch-demo)

## Quick start

1. **Configuration → Search and metadata → Search API → Add server**: pick the **Meilisearch** backend, enter the URL with its port (`http://127.0.0.1:7700`, or your Cloud project URL) and an API key.
2. Add an index on that server, pick content and fields, then **Index now**.
3. Build a view on the index with a fulltext filter.

Keep the API key out of configuration exports by setting it in `settings.php`:

```php
$config['search_api.server.SERVER_ID']['backend_config']['api_key'] = getenv('MEILISEARCH_API_KEY');
```

Use a dedicated key with the `search`, `documents.*`, `indexes.*`, `settings.*`, `tasks.get` and `version` actions rather than the master key.

## Features

- Typo-tolerant, prefix-matching full-text search; keyword, hybrid or semantic mode
- Every Search API condition, including language (`setLanguages()`) and datasource filters
- Facets ([Facets](https://www.drupal.org/project/facets)): limits, minimum counts, missing values, OR facets
- Highlighted, cropped and escaped excerpts (*Meilisearch highlighting* processor)
- Location search with [Search API Location](https://www.drupal.org/project/search_api_location)
- Exact result counts, ranking scores, magic-field sorts
- Index prefixes, so sites and environments can share one instance
- `quebracho_meilisearch_analytics` submodule: click analytics on Meilisearch Cloud
- `hook_meilisearch_search_params_alter()` and `hook_meilisearch_request_headers_alter()`

Synonyms, stop words, ranking rules and embedders are Meilisearch settings: manage them in the Cloud dashboard, the CLI or the API. The module only sends the attribute lists, pagination and faceting limits, and makes `sort` the first ranking rule of the indexes it creates so explicit sorts are strict.

## How searches differ from the Database backend

- No boolean operators in keywords: parsed keys are flattened, the matching strategy decides how many words must match, negated keys become `-word`.
- The last word matches as a prefix and typos are tolerated.
- Conditions and facets on fulltext fields use whole field values.
- Exact conditions on string values over about 250 bytes cannot match.
- Random sorting is not supported; "relevance, then X" with keywords is relevance order.

Details: [`docs/reference/search-behavior.mdx`](docs/reference/search-behavior.mdx).

## Documentation

The `docs/` directory is a [Mintlify](https://mintlify.com) site:

```bash
cd docs && npx mintlify dev
```

## Development

Tests run against a real Meilisearch, including Search API's backend conformance suite:

```bash
scripts/test.sh
```

See [`docs/reference/development.mdx`](docs/reference/development.mdx).

## License

GPL-2.0-or-later

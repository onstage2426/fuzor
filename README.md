<p align="center"><a href="https://github.com/onstage2426/fuzor" target="_blank"><img src="https://raw.githubusercontent.com/onstage2426/fuzor/refs/heads/assets/logos/logo.svg" width="400" alt="Fuzor"></a></p>

<p align="center">
<img alt="PHP 8.5+" src="https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&amp;logoColor=white">
<a href="https://packagist.org/packages/onstage2426/fuzor"><img alt="Packagist Version" src="https://img.shields.io/packagist/v/onstage2426/fuzor"></a>
<a href="https://packagist.org/packages/onstage2426/fuzor"><img alt="Downloads" src="https://img.shields.io/packagist/dt/onstage2426/fuzor"></a>
<a href="https://github.com/onstage2426/fuzor/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/onstage2426/fuzor/actions/workflows/ci.yml/badge.svg"></a>
<img alt="PHPStan level max" src="https://img.shields.io/badge/PHPStan-level%20max-brightgreen">
<a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-green"></a>
</p>

<p align="center"><b>Full-text search for PHP in a single SQLite file.</b><br>
Typo-tolerant ranking, filters, facets and sorting, with no search server to run.</p>

Fuzor indexes your documents into one SQLite file and searches them from PHP: BM25 ranking with typo
tolerance and search-as-you-type, filters and facet counts for shop pages, sorting, highlighting and
excerpts. It needs no service, no daemon and no package dependencies, only PHP's SQLite and mbstring
extensions. Its options use the names and behaviour of Meilisearch (`filterableAttributes`,
`matchingStrategy`, `typoTolerance`, `facetDistribution`, `_formatted`, …), so the concepts carry over.

## Quick start

```bash
composer require onstage2426/fuzor
```

```php
use Fuzor\FacetRange;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Sort;

$index = new Index('products.db', schema: new SchemaConfig(
    filterableAttributes: ['brand', 'price'],
    sortableAttributes: ['price'],
));

$index->insert([
    ['id' => 1, 'title' => 'Waterproof hiking boots',  'brand' => 'Northpeak', 'price' => 129],
    ['id' => 2, 'title' => 'Trail running shoes',      'brand' => 'Fellwalk',  'price' => 89],
    ['id' => 3, 'title' => 'Leather hiking boots',     'brand' => 'Northpeak', 'price' => 159],
    ['id' => 4, 'title' => 'Lightweight hiking boots', 'brand' => 'Fellwalk',  'price' => 99],
]);

$result = $index->search('boots', new SearchOptions(
    filter: ['price' => FacetRange::max(150)],
    facets: ['brand'],
    sort:   [Sort::asc('price')],
));

$result->hits;              // Lightweight hiking boots (99), Waterproof hiking boots (129)
$result->totalHits;         // 2
$result->facetDistribution; // ['brand' => ['Fellwalk' => 1, 'Northpeak' => 1]]

$index->search('waterprof boots'); // a typo still finds "Waterproof hiking boots"
```

[Getting started](docs/getting-started.md) walks through a complete search page from here.

## Why Fuzor

- **Results you can trust.** Totals are exact up to `totalHits` (1,000 by default), and facet counts are
  exact over up to 50,000 matching documents. Where a limit makes a result approximate, the result says
  so (`exhaustive`, `approximateFacets`, `warnings`) instead of quietly showing a smaller number. The
  ranking is tested against a brute-force reference implementation, and CI runs the tests on the oldest
  supported SQLite (3.46.0) and the latest release.
- **Fast where a shop spends its time.** On a 44k-product catalog, a shop page with five facets takes
  3.5 ms, a sorted listing with six facets 4.1 ms, and a two-word search about 15 ms. Updating 200
  products takes 33 ms and deleting 50 takes 11 ms. Measurements and what each option costs are in
  [Performance](docs/performance.md).
- **Grows with you.** On 1.43M products (a 3 GB index), searches take 6–45 ms and filtered category
  pages with facets up to about 35 ms, in one PHP process.
- **Nothing to operate.** One file per index, read by any number of PHP workers. Rebuilds, snapshots and
  installing an index built on another server publish the new file atomically, so readers never see a
  half-written index.

## Features

- **Search:** BM25 ranking with proximity, typo tolerance, search-as-you-type prefixes, quoted phrases,
  `-word` and `-"phrase"` exclusions, matching strategies, synonyms, boolean queries with AND / OR / NOT
  ([Searching](docs/searching.md))
- **Filters and facets:** value, list and range filters, exclusions, nested `Filter::all()` / `any()` /
  `not()`, facet counts and stats, counts that stay visible while a value is selected, facet value search
  ([Filtering and facets](docs/filtering-and-facets.md))
- **Sorting** on any number of fields, and `distinct` to show one hit per product group
  ([Sorting](docs/sorting.md))
- **Highlighting and excerpts**, with HTML-safe output when you ask for it ([Formatting](docs/formatting.md))
- **Languages:** Chinese, Japanese, Korean and Thai split into n-grams automatically; optional
  stemming with a stemmer class of your own, and stop words ([Language](docs/language.md))
- **Indexing:** insert, update, upsert, delete and delete by filter; HTML stripped at index time; bulk
  loads in bounded memory; an optional document store ([Indexing](docs/indexing.md))
- **Operations:** atomic rebuilds, snapshots, installing a prebuilt file, reader/writer setups
  ([Operations](docs/operations.md))
- **Debugging:** `inspectQuery()` and per-hit ranking details show why a document matched and where it
  ranked ([Debugging](docs/debugging.md))

## When to use something else

Fuzor runs inside your PHP process on one machine. Pick a search server instead when you need search
spread over several machines, vector or semantic search, or the lowest latency on catalogs of millions
of products with heavy traffic. Up to that point, a single file you can copy, snapshot and ship with
your application is usually the simpler choice.

## Requirements

- PHP 8.5+ (64-bit) with the `pdo_sqlite` and `mbstring` extensions
- SQLite 3.46.0+, the library PHP's `pdo_sqlite` is linked against. An older library or a 32-bit PHP
  is refused with a `FuzorException` when an index is opened.

## Documentation

Start with [Getting started](docs/getting-started.md) and [Concepts](docs/concepts.md); every page is
listed in the [documentation index](docs/README.md). The [API reference](docs/reference.md) lists every
class and option.

Upgrading from 1.x: see [UPGRADE-2.0.md](UPGRADE-2.0.md) and the [changelog](CHANGELOG.md). Indexes
written by 1.x have to be rebuilt.

## Contributing

Bug reports and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Report security
issues as described in [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE).

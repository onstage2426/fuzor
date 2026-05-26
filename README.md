<p align="center"><a href="https://github.com/onstage2426/fuzor" target="_blank"><img src="https://raw.githubusercontent.com/onstage2426/fuzor/refs/heads/assets/logos//logo.svg" width="400" alt="Fuzor Logo"></a></p>

<p align="center"><img alt="PHP 8.5+" src="https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&amp;logoColor=white"> <img alt="License" src="https://img.shields.io/badge/license-MIT-green"> <img alt="Packagist Version" src="https://img.shields.io/packagist/v/onstage2426/fuzor"> <img alt="CI" src="https://github.com/onstage2426/fuzor/actions/workflows/ci.yml/badge.svg"></p>

## About

Fuzor is a dependency-free full-text search library for PHP. It tokenises your documents, stores an inverted index in a single SQLite file, and scores results with Okapi BM25 — no external services required.

- BM25 ranked search with automatic typo tolerance and boolean modes
- Faceted search — filter by attribute values and compute per-value counts
- Search-as-you-type prefix matching
- Stopword filtering and Snowball stemming for 62 languages
- Snippet extraction and result highlighting
- One SQLite file per index — zero infrastructure

## Installation

```bash
composer require onstage2426/fuzor
```

**Requirements:** PHP 8.5+, SQLite 3.46.0+

## Usage

```php
use Fuzor\Index;
use Fuzor\FacetRange;

// Create an index — declare facetable fields once at creation time
$index = new Index('/path/to/products.db',
    language:    'en',
    facetFields: ['type', 'price'],
);

$index->insert([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'City car with great fuel economy.',    'type' => 'sedan',  'price' => 24900],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure and any terrain.', 'type' => 'suv',    'price' => 41500],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions and instant torque.',   'type' => 'coupe',  'price' => 58000],
]);

// BM25 search — typo tolerance fires automatically on words ≥ 5 chars
$results = $index->search('economi');

// Boolean search
$results = $index->searchBoolean('sedan or coupe -electric');

// Faceted search — filter by attribute, get counts per value
$results = $index->search('car', filter: ['type' => ['sedan', 'suv'], 'price' => FacetRange::max(45000)], facets: ['type']);
```

## Documentation

- [Indexing](docs/indexing.md) — bulk loading, facet values, upsert, rebuild, snapshots
- [Search](docs/search.md) — BM25 tuning, typo tolerance, boolean, prefix, facet filtering and counts
- [Language](docs/language.md) — stopwords, stemming, CJK/Thai n-grams
- [Configuration](docs/configuration.md) — all tuning parameters
- [Document store](docs/document-store.md) — store and retrieve raw documents
- [Snippeting](docs/snippeting.md) and [Highlighting](docs/highlighting.md)
- [Query inspection](docs/inspect-query.md) and [Performance](docs/performance.md)

## License

MIT — see [LICENSE](LICENSE).

<p align="center"><a href="https://github.com/onstage2426/fuzor" target="_blank"><img src="https://raw.githubusercontent.com/onstage2426/fuzor/refs/heads/assets/logos//logo.svg" width="400" alt="Fuzor Logo"></a></p>

<p align="center"><img alt="PHP 8.5+" src="https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&amp;logoColor=white"> <img alt="License" src="https://img.shields.io/badge/license-MIT-green"> <img alt="Packagist Version" src="https://img.shields.io/packagist/v/onstage2426/fuzor"> <img alt="CI" src="https://github.com/onstage2426/fuzor/actions/workflows/ci.yml/badge.svg"></p>

## About

Fuzor is a dependency-free full-text search library for PHP. It tokenises your documents, stores an inverted index in a single SQLite file, and scores results with Okapi BM25 — no external services required.

- BM25 ranked search with automatic typo tolerance
- Boolean search with AND / OR / NOT operators
- Faceted filtering and per-value counts
- Search-as-you-type prefix matching and phrase search
- Stopword filtering and Snowball stemming for 62 languages
- Result highlighting and snippet extraction
- One SQLite file per index — zero infrastructure

## Installation

```bash
composer require onstage2426/fuzor
```

**Requirements:** PHP 8.5+, SQLite 3.46.0+

## Usage

```php
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\FacetRange;

// Create an index — declare facetable fields at creation time
$index = new Index('/path/to/products.db', schema: new SchemaConfig(
    language:    'en',
    facetFields: ['type', 'price'],
));

$index->insert([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'City car with great fuel economy.',    'type' => 'sedan', 'price' => 24900],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure and any terrain.', 'type' => 'suv',   'price' => 41500],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions and instant torque.',   'type' => 'coupe', 'price' => 58000],
]);

// BM25 ranked search — typo tolerance fires automatically on words ≥ 5 chars
$result = $index->search('economi');

// Boolean search
$result = $index->searchBoolean('sedan or coupe -electric');

// Faceted search — filter by attribute, count values
$result = $index->search('car', new SearchOptions(
    filter: ['type' => ['sedan', 'suv'], 'price' => FacetRange::max(45000)],
    facets: ['type'],
));

$result->hits;               // documents in relevance order
$result->facetDistribution;  // ['type' => ['sedan' => 1, 'suv' => 1]]
```

## Documentation

- [Indexing](docs/indexing.md) — creating indexes, inserting, updating, facet fields, rebuild, snapshots
- [Search](docs/search.md) — BM25, boolean, phrases, typo tolerance, synonyms, facets, sorting, distinct
- [Language](docs/language.md) — stopwords, stemming, CJK/Thai n-grams
- [Formatting](docs/formatting.md) — result highlighting and snippet extraction
- [Document store](docs/document-store.md) — storing and retrieving raw documents
- [Tuning](docs/tuning.md) — BM25 parameters, typo tolerance, field boosting
- [Performance](docs/performance.md) — read/write index split, snapshots
- [Inspect query](docs/inspect-query.md) — debug the query pipeline

## License

MIT — see [LICENSE](LICENSE).

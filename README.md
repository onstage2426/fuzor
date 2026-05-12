<p align="center"><a href="https://github.com/onstage2426/fuzor" target="_blank"><img src="https://raw.githubusercontent.com/onstage2426/fuzor/refs/heads/assets/logo.svg" width="400" alt="Fuzor Logo"></a></p>

<p align="center"><img alt="PHP 8.5+" src="https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&amp;logoColor=white"> <img alt="License" src="https://img.shields.io/badge/license-MIT-green"> <img alt="Packagist Version" src="https://img.shields.io/packagist/v/onstage2426/fuzor"> <img alt="CI" src="https://github.com/onstage2426/fuzor/actions/workflows/ci.yml/badge.svg"></p>

## About

Fuzor is a dependency-free full-text search library for PHP. It tokenises your documents, stores an inverted index in a single SQLite file, and scores results with Okapi BM25 — no external services required.

- BM25 ranked search with fuzzy and boolean modes
- Search-as-you-type prefix matching
- Stopword filtering and Snowball stemming for 62 languages
- Snippet extraction and result highlighting
- One SQLite file per index — zero infrastructure

## Installation

```bash
composer require onstage2426/fuzor
```

**Requirements:** PHP 8.5+, SQLite 3.37.0+

## Usage

```php
use Fuzor\Index;

// Create an index and add documents
$index = new Index('/path/to/articles.db', language: 'en');
$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions, instant torque, sporty design.'],
]);

// Search
$results = $index->search('economi', fuzzy: true);
$results = $index->searchBoolean('sedan or coupe -electric');
```

## Documentation

- [Indexing](docs/indexing.md) — bulk loading, upsert, rebuild, snapshots
- [Search](docs/search.md) — BM25 tuning, fuzzy, boolean, prefix
- [Language](docs/language.md) — stopwords, stemming, CJK/Thai n-grams
- [Configuration](docs/configuration.md) — all tuning parameters
- [Snippeting](docs/snippeting.md) and [Highlighting](docs/highlighting.md)

## License

MIT — see [LICENSE](LICENSE).

# ⚡ Fuzor

![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&logoColor=white) ![License](https://img.shields.io/badge/license-MIT-green) ![Packagist Version](https://img.shields.io/packagist/v/onstage2426/fuzor)

Lightweight dependency-free full-text search for PHP. Tokenises documents, stores an inverted index in a SQLite file, and scores results with Okapi BM25.

- BM25 ranked full-text search with fuzzy and boolean modes
- Search-as-you-type with built-in prefix matching
- Stopword filtering and Snowball stemming for 60+ languages
- Snippet extraction and result highlighting included
- One SQLite file per index — zero infrastructure

[Documentation](docs/) · [Benchmarks](benchmarks/results.txt)

## Installation

```bash
composer require onstage2426/fuzor
```

## Requirements

- PHP 8.5+
- SQLite 3.37.0+

## Quickstart

### Opening and creating an index

The constructor opens an existing index or creates a new one:

```php
use Fuzor\Index;

$index = new Index('/path/to/articles.db');              // open or create
$index = new Index('/path/to/articles.db', force: true); // overwrite existing
```

### Adding, updating, and removing items

Each document requires an `id`. All other fields are concatenated and indexed together.

The `Many` variants are significantly faster when indexing multiple documents.

```php
// Add one item
$index->insert(['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.']);
$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan',    'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',  'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe','body' => 'Zero emissions, instant torque, sporty design.'],
]);

// Replace an existing item (throws if ID not found)
$index->update(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);
$index->updateMany(...);

// Create or replace (upsert semantics)
$index->upsert(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);
$index->upsertMany(...);

// Remove an item
$index->delete(2);
$index->deleteMany([1, 2, 3]);
```

### Searching

```php
$results = $index->search('city car');
$results = $index->search('economi', fuzzy: true); // tolerates typos

$results = $index->searchBoolean('sedan or coupe -electric');
```

### Stopword filtering and stemming

Pass a BCP 47 language tag to enable stopword filtering and stemming:

```php
$index = new Index($path, language: 'en');
```

### Snippeting and highlighting

```php
$snip = $index->snippeter();
echo $snip->snippet('fast connections', $doc['body']);
// "… offers fast broadband connections for …"

$hl = $index->highlighter();
echo $hl->highlight('fast sedan', $doc['title']);
// "Sporty <mark>fast sedan</mark> for sale"
```

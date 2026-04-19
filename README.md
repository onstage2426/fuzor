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

### Creating and opening an index

```php
use Fuzor\Index;

// Create a new index
$index = Index::create('/path/to/articles.db');

// Open an existing index
$index = Index::open('/path/to/articles.db');
```

### Adding, updating, and removing items

Each document requires an `id`. All other fields are concatenated and indexed together.

```php
// Add one item
$index->insert(['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.']);

// Add many at once — faster than inserting one by one
$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan',    'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',  'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe','body' => 'Zero emissions, instant torque, sporty design.'],
]);

// Replace an item
$index->update(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);

// Remove an item
$index->delete(2);
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
$index = Index::create($path, language: 'en');
```

### Snippeting and highlighting

```php
$snip = $index->snippeter();
echo $snip->snippet('fast connections', $doc['body']);
// "… offers fast broadband connections for …"

$hl = $index->highlighter();
echo $hl->highlight('fast sedan', $doc['title']);
```

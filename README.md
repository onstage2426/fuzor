# Fuzor

Lightweight full-text search for PHP. Tokenises documents, stores an inverted index in a SQLite file, and scores results with Okapi BM25. No external services required.

## Requirements

- PHP 8.5+
- SQLite 3.37.0+

## Installation

```bash
composer require onstage2426/fuzor
```

## Usage

```php
use Fuzor\Index;

// Create a new index and add documents
$index = Index::create('/path/to/articles.db');

$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV', 'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions, instant torque, sporty design.'],
]);

// Reopen later
$index = Index::open('/path/to/articles.db');

// BM25 ranked search
$results = $index->search('city car');
// ['ids' => [1, ...], 'hits' => 1, 'docScores' => [...]]

// Fuzzy search (Levenshtein)
$results = $index->searchFuzzy('economi'); // matches 'economy'

// Boolean search (AND / OR / NOT)
$results = $index->searchBoolean('sedan or coupe');
$results = $index->searchBoolean('suv -electric');

// Update and delete
$index->update(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);
$index->delete(2);
```

## Options

```php
$index->asYouType         = true;  // prefix-match the last keyword (default)
$index->fuzzyDistance     = 2;     // max edit distance for fuzzy search
$index->fuzzyPrefixLength = 2;     // exact-match prefix before fuzzy kicks in
$index->maxDocs           = 500;   // max documents returned per keyword
$index->k1                = 1.2;   // BM25 term saturation
$index->b                 = 0.75;  // BM25 length normalisation (0–1)
```

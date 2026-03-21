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

<details>
<summary><strong>Creating and opening an index</strong></summary>

```php
use Fuzor\Index;

// Create a new index
$index = Index::create('/path/to/articles.db');

// Overwrite an existing index file
$index = Index::create('/path/to/articles.db', force: true);

// Open an existing index
$index = Index::open('/path/to/articles.db');

// Close the connection when done
$index->close();
```

</details>

<details>
<summary><strong>Inserting, updating, and deleting documents</strong></summary>

```php
// Insert a single document
$index->insert(['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.']);

// Bulk insert in a single transaction
$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan',    'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',  'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe','body' => 'Zero emissions, instant torque, sporty design.'],
]);

// Update a document (delete + re-index)
$index->update(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);

// Delete a document by ID
$index->delete(2);
```

</details>

<details>
<summary><strong>Normal search</strong></summary>

```php
$results = $index->search('city car');
```

</details>

<details>
<summary><strong>Fuzzy search</strong></summary>

```php
$results = $index->searchFuzzy('economi'); // matches 'economy'
```

</details>

<details>
<summary><strong>Boolean search</strong></summary>

```php
$results = $index->searchBoolean('sedan or coupe');
$results = $index->searchBoolean('suv -electric');
$results = $index->searchBoolean('fast & comfortable');
```

| Syntax          | Operator | Effect                     |
|-----------------|----------|----------------------------|
| `term1 term2`   | AND      | Both terms must be present |
| `term1 or term2`| OR       | Either term present        |
| `-term`         | NOT      | Term must be absent        |

Operators are evaluated via Shunting-Yard (postfix). Combine freely:

```php
$results = $index->searchBoolean('sedan or suv -electric');
```

</details>

<details>
<summary><strong>Stopword filtering</strong></summary>

Stopword filtering removes common words (e.g. "the", "and") from both indexed documents and search queries, reducing index noise. It is opt-in. Should be set before indexing and/or searching.

Supported languages: af, ar, bn, br, bg, ca, zh, hr, cs, da, nl, en, eo, et, eu, fa, fi, fr, ga, gl, de, el, gu, ha, he, hi, hu, hy, id, it, ja, ko, ku, la, lt, lv, mr, ms, no, pl, pt, ro, ru, sk, sl, so, es, st, sv, sw, th, tl, tr, uk, ur, vi, yo, zu

```php
// Disable (default)
$index->language = null;

// Enable
$index->language = 'en';
```
</details>

## Options reference

| Property              | Default | Description                                     |
|-----------------------|---------|-------------------------------------------------|
| `asYouType`           | `true`  | Prefix-match the last keyword                   |
| `fuzzyDistance`       | `2`     | Max edit distance for fuzzy search              |
| `fuzzyPrefixLength`   | `2`     | Exact-match prefix length before fuzzy kicks in |
| `fuzzyMaxExpansions`  | `50`    | Max candidate terms evaluated for fuzzy         |
| `maxDocs`             | `500`   | Max documents returned per keyword              |
| `k1`                  | `1.2`   | BM25 term frequency saturation                  |
| `b`                   | `0.75`  | BM25 length normalisation weight (0–1)          |
| `language`            | `null`  | BCP 47 language code for stopword filtering     |

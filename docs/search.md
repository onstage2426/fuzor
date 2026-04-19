# Search

Fuzor has two search methods: `search()` for BM25 ranked results and `searchBoolean()` for set-based filtering. Both tokenise the query, apply stopword filtering and stemming if a language is set, and respect the `asYouType` prefix setting.

## Result shape

```php
// search()
[
    'ids'       => [3, 1, 7],           // document IDs sorted by relevance
    'hits'      => 3,                   // total number of matching documents
    'docScores' => [3 => 1.4, 1 => 0.9, 7 => 0.3],  // raw BM25 scores
]

// searchBoolean()
[
    'ids'       => [3, 1, 7],
    'hits'      => 3,
    'docScores' => null,                // no scoring in boolean mode
]
```

## Full-text search

Scores results with Okapi BM25. Documents are ranked by how relevant each term is relative to the rest of the index.

```php
$results = $index->search('city car');

// Limit results
$results = $index->search('city car', numOfResults: 20);
```

### Fuzzy matching

Pass `fuzzy: true` to tolerate typos. Fuzor scans the wordlist for candidates within edit distance and ranks them before scoring.

```php
$results = $index->search('economi', fuzzy: true); // matches 'economy'
```

Fuzzy behaviour is controlled by three properties:

| Property             | Default | Effect                                          |
|----------------------|---------|-------------------------------------------------|
| `fuzzyPrefixLength`  | `3`     | Characters that must match exactly before fuzzy |
| `fuzzyMaxExpansions` | `50`    | Max wordlist candidates evaluated               |
| `fuzzyDistance`      | `2`     | Max edit distance accepted                      |

## Boolean search

Set-based: no BM25 scoring, `docScores` is always `null`. Useful for filtering rather than ranking.

```php
$results = $index->searchBoolean('sedan or coupe');
$results = $index->searchBoolean('suv -electric');
$results = $index->searchBoolean('fast & comfortable');
```

| Syntax            | Operator | Effect                     |
|-------------------|----------|----------------------------|
| `term1 term2`     | AND      | Both terms must be present |
| `term1 or term2`  | OR       | Either term present        |
| `-term`           | NOT      | Term must be absent        |
| `term1 & term2`   | AND      | Explicit AND               |

## As-you-type prefix

When `asYouType` is `true` (default), the last query word is matched as a prefix — so `"fast se"` also matches documents containing `"sedan"`. Applies to both `search()` and `searchBoolean()`.

```php
$index->asYouType = false; // disable if querying complete words only
```

## BM25 tuning

See [configuration.md](configuration.md) for `k1`, `b`, and `maxDocs`.

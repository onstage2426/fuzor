# Search

Fuzor has two search methods: `search()` for BM25 ranked results and `searchBoolean()` for set-based filtering. Both tokenise the query, apply stopword filtering and stemming if a language is set, and support as-you-type prefix matching.

## Result object

Both methods return a `SearchResult` object:

| Member              | Type                           | Description                                                              |
|---------------------|--------------------------------|--------------------------------------------------------------------------|
| `$ids`              | `int[]`                        | Document IDs in relevance order (current page only)                      |
| `$hits`             | `int`                          | Total matching documents across all pages                                |
| `hasScores()`       | `bool`                         | `true` for BM25 results; `false` for boolean search                      |
| `score(int $id)`    | `float\|null`                  | BM25 score for a doc ID; `null` in boolean mode or ID not in result      |
| `scores()`          | `array<int,float>`             | All BM25 scores keyed by doc ID; empty array for boolean search          |
| `hasDocuments()`    | `bool`                         | `true` when the document store is enabled on this result                 |
| `document(int $id)` | `array\|null`                  | Single hydrated document; `null` when store is off or ID not in result   |
| `documents()`       | `array<int,array>\|null`       | All hydrated documents keyed by doc ID; `null` when document store is off |

```php
$results = $index->search('city car');

$results->ids;          // [3, 1, 7]
$results->hits;         // total matches (may exceed count($results->ids) when paginating)
$results->hasScores();  // true
$results->score(3);     // 1.4  — BM25 score for doc 3
$results->score(99);    // null — doc not in result set
$results->scores();     // [3 => 1.4, 1 => 0.9, 7 => 0.3]
```

When the [document store](document-store.md) is enabled, documents are automatically populated:

```php
$results->hasDocuments(); // true
$results->document(3);    // the full document array for doc 3, or null if not in result
$results->documents();    // array<int, array> keyed by doc ID, in relevance order
```

## Full-text search

Scores results with Okapi BM25. Documents are ranked by how relevant each term is relative to the rest of the index.

```php
$results = $index->search('city car');

// Limit results
$results = $index->search('city car', limit: 20);
```

### Pagination

Use `offset` to page through results. `$hits` always reflects the full match count regardless of the page window.

```php
$page1 = $index->search('city car', limit: 20, offset: 0);
$page2 = $index->search('city car', limit: 20, offset: 20);

$totalPages = (int) ceil($page1->hits / 20);
```

### Fuzzy matching

Pass `fuzzy: true` to tolerate typos. Fuzor scans the wordlist for candidates within edit distance and ranks them before scoring.

```php
$results = $index->search('economi', fuzzy: true); // matches 'economy'
```

Fuzzy behaviour is controlled by three `Config` properties — see [configuration.md](configuration.md):

| Config property      | Default | Effect                                          |
|----------------------|---------|-------------------------------------------------|
| `fuzzyPrefixLength`  | `3`     | Characters that must match exactly before fuzzy |
| `fuzzyMaxExpansions` | `50`    | Max wordlist candidates evaluated               |
| `fuzzyDistance`      | `2`     | Max edit distance accepted                      |

### BM25 tuning
`k1`, `b`, `maxDocs`, and `proximityBoost` are set via `Config` at construction time — see [configuration.md](configuration.md).

## Boolean search

Set-based: no BM25 scoring, `score()` always returns `null`. Useful for filtering rather than ranking.

```php
$results = $index->searchBoolean('sedan or coupe');
$results = $index->searchBoolean('suv -electric');
$results = $index->searchBoolean('fast & comfortable');
$results = $index->searchBoolean('(sedan or coupe) -electric');
```

| Syntax              | Operator | Effect                          |
|---------------------|----------|---------------------------------|
| `term1 term2`       | AND      | Both terms must be present      |
| `term1 or term2`    | OR       | Either term present             |
| `-term`             | NOT      | Term must be absent             |
| `term1 & term2`     | AND      | Explicit AND                    |
| `(term1 or term2)`  | grouping | Override default precedence     |

Default precedence (tightest to loosest): NOT > AND > OR. Use parentheses when you need OR to bind tighter than an outer AND or NOT — for example `(sedan or coupe) -electric` excludes electric vehicles from a combined sedan/coupe set, whereas `sedan or coupe -electric` would be parsed as `sedan or (coupe and not electric)`.

Spaces adjacent to parentheses are stripped before the AND-substitution step runs, so you must use an explicit `&` when a parenthesised group follows a term without a space: `suv&(sedan or coupe)`. With a space — `suv (sedan or coupe)` — the space is consumed by the paren-stripping step and no AND is inserted.

Boolean search also supports `offset` for pagination:

```php
$page2 = $index->searchBoolean('sedan or coupe', limit: 20, offset: 20);
```

## As-you-type prefix

When `asYouType` is `true` (default), the last query word is matched as a prefix — so `"fast se"` also matches documents containing `"sedan"`. Applies to both `search()` and `searchBoolean()`.

```php
// Disable for exact keyword queries
$results = $index->search('sedan', asYouType: false);
$results = $index->searchBoolean('sedan or coupe', asYouType: false);
```

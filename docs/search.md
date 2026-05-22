# Search

Fuzor has two search methods: `search()` for BM25 ranked results and `searchBoolean()` for set-based filtering. Both tokenise the query, apply stopword filtering and stemming if a language is set, and support as-you-type prefix matching and quoted phrase search.

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
| `hasFacets()`       | `bool`                         | `true` when facet counts were computed for this result                   |
| `facetCounts()`     | `array<string,mixed>`          | All facet counts keyed by facet key name; empty array when not requested |
| `facetCount(string $key, string $value)` | `int\|null` | Count for a specific string facet value; `null` for numeric facets or absent entries |

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

### Typo tolerance

Typo tolerance is automatic. When a query word has no exact or prefix match and meets the minimum word length, Fuzor scans the wordlist for candidates within edit distance and ranks them closest-first before BM25 scoring.

```php
$results = $index->search('economi'); // matches 'economy'
```

Typo tolerance is controlled by four `Config` properties — see [configuration.md](configuration.md):

| Config property      | Default | Effect                                                          |
|----------------------|---------|-----------------------------------------------------------------|
| `fuzzyMinWordLength` | `5`     | Minimum word length before the Levenshtein fallback fires       |
| `fuzzyPrefixLength`  | `3`     | Characters that must match exactly before the fuzzy scan begins |
| `fuzzyMaxExpansions` | `50`    | Max wordlist candidates evaluated                               |
| `fuzzyDistance`      | `2`     | Max edit distance accepted                                      |

Words shorter than `fuzzyMinWordLength` use exact/prefix matching only, which avoids false positives on short tokens where edit distance has too little signal.

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

## Phrase search

Wrap words in double quotes to require them to appear as a contiguous, ordered sequence. Works in both `search()` and `searchBoolean()`.

```php
$results = $index->search('"quick brown fox"');

// Mix phrases and free keywords
$results = $index->search('"quick brown" sedan');

// Multiple phrases — all must match
$results = $index->search('"quick brown" "fast car"');

// In boolean queries
$results = $index->searchBoolean('"quick brown" or sedan');
$results = $index->searchBoolean('"exact phrase" -electric');
```

Phrase words participate in BM25 scoring normally. `asYouType` applies to the last token even when it is inside a phrase — `"quick brow"` matches `quick` followed immediately by any word starting with `brow`.

## As-you-type prefix

When `asYouType` is `true` (default), the last query word is matched as a prefix — so `fast se` also matches documents containing `sedan`. Applies to both `search()` and `searchBoolean()`.

```php
// Disable for exact keyword queries
$results = $index->search('sedan', asYouType: false);
$results = $index->searchBoolean('sedan or coupe', asYouType: false);
```

## Facet filtering

Pass a `filter` map to restrict results to documents matching specific facet attribute values. See [indexing.md](indexing.md) for how to supply `_facets` values on documents.

### String facets

Pass a single value or an array of values. An array is treated as OR within that key: the document must match at least one value. Multiple keys are AND: all constraints must be satisfied.

```php
// Single value
$results = $index->search('watch', filter: ['brand' => 'Casio']);

// Multiple values — OR within the key
$results = $index->search('watch', filter: ['brand' => ['Casio', 'Seiko']]);

// Multiple keys — AND between keys
$results = $index->search('watch', filter: [
    'brand'    => 'Casio',
    'category' => 'Watches',
]);
```

### Numeric range facets

Use `FacetRange` to filter by a numeric range. Either bound may be `null` (open-ended).

```php
use Fuzor\FacetRange;

// Price between 50 and 200 (inclusive on both ends)
$results = $index->search('watch', filter: [
    'price' => FacetRange::between(50.0, 200.0),
]);

// Price 100 or above (no upper bound)
$results = $index->search('watch', filter: [
    'price' => FacetRange::min(100.0),
]);
```

Filters compose with all other options:

```php
$results = $index->search('gshock', limit: 20, filter: [
    'brand' => 'Casio',
    'price' => FacetRange::max(300.0),
]);
```

`searchBoolean()` accepts the same `filter` parameter:

```php
$results = $index->searchBoolean('shock resistant', filter: ['brand' => 'Casio']);
```

## Facet counts

Pass a `facets` list to compute per-value counts across the result set.

```php
$results = $index->search('watch', facets: ['brand', 'category', 'price']);

$results->hasFacets();   // true
$results->facetCounts(); // ['brand' => ['Casio' => 12, 'Seiko' => 8, ...], 'price' => [...], ...]
```

### String facet counts

For string facets, `facetCounts()['key']` is an `array<string, int>` of value → count pairs, sorted by count descending:

```php
$results->facetCounts()['brand'];
// ['Casio' => 12, 'Seiko' => 8, 'Citizen' => 5]

// Convenience accessor for a single value
$results->facetCount('brand', 'Casio');   // 12
$results->facetCount('brand', 'Unknown'); // null — value not present in result set
```

### Numeric facet counts

For numeric facets (int/float values in `_facets`), `facetCounts()['key']` is an aggregate summary:

```php
$results->facetCounts()['price'];
// ['min' => 29.99, 'max' => 499.0, 'count' => 20]
```

`facetCount()` returns `null` for numeric facets — read `facetCounts()['price']['min']` etc. directly.

### Combining facets with filters

`facets` and `filter` are independent and can be used together:

```php
$results = $index->search('watch', filter: ['brand' => 'Casio'], facets: ['category', 'price']);
```

### Disjunctive facet counting

In standard faceted navigation, the counts shown for a facet key should reflect how many results switching to another value would yield — not just the count already selected. Fuzor handles this automatically.

When a `filter` is active on a key that is also in the `facets` list, its counts are computed over the result set *without* that key's filter applied. All other facet key counts are computed over the filtered result set.

```php
$results = $index->search('watch', filter: ['brand' => 'Casio'], facets: ['brand', 'category']);

// Brand counts show all brands available in the unfiltered query result
$results->facetCounts()['brand'];
// ['Casio' => 12, 'Seiko' => 8, 'Citizen' => 5]

// Category counts are scoped to the Casio filter
$results->facetCounts()['category'];
// ['Watches' => 10, 'Accessories' => 2]
```

This makes it straightforward to build a faceted navigation UI where users can switch between values in a facet group without losing count context for the other values.

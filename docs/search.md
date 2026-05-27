# Search

Fuzor has two search methods: `search()` for BM25 ranked results and `searchBoolean()` for set-based filtering. Both tokenise the query, apply stopword filtering and stemming if a language is set, and support as-you-type prefix matching and quoted phrase search.

## Result object

Both methods return a `SearchResult` object:

| Member                  | Type                  | Description                                                              |
|-------------------------|-----------------------|--------------------------------------------------------------------------|
| `$hits`                 | `list<array>`         | Documents in relevance order (current page); stubs `['id' => n]` when the document store is off |
| `$hitsCount`            | `int`                 | Number of documents in this page (≤ limit)                              |
| `$totalHits`            | `int\|null`           | Total matching documents across all pages                                |
| `$query`                | `string`              | Original query string                                                    |
| `$limit`                | `int\|null`           | Page limit                                                               |
| `$offset`               | `int\|null`           | Page offset                                                              |
| `$facetDistribution`    | `array<string,mixed>` | Per-value counts keyed by facet field name; empty when not requested     |
| `getIds()`              | `list<int>`           | Document IDs in relevance order (current page)                           |
| `getHits()`             | `list<array>`         | Same as `$hits`                                                          |
| `getHit(int $index)`    | `array`               | Document at position `$index` (0-based); empty array when out of bounds  |
| `getHitsCount()`        | `int`                 | Same as `$hitsCount`                                                     |
| `getTotalHits()`        | `int\|null`           | Same as `$totalHits`                                                     |
| `getQuery()`            | `string`              | Same as `$query`                                                         |
| `getLimit()`            | `int\|null`           | Same as `$limit`                                                         |
| `getOffset()`           | `int\|null`           | Same as `$offset`                                                        |
| `getFacetDistribution()` | `array<string,mixed>` | Same as `$facetDistribution`                                            |
| `toArray()`             | `array`               | Full result as a plain array                                             |
| `toJson(int $flags = 0)` | `string`             | JSON-encoded result; pass `JSON_PRETTY_PRINT` etc. via `$flags`          |

```php
$result = $index->search('city car');

$result->hits;         // [['id' => 3, 'title' => 'City Car', ...], ...]
$result->totalHits;    // total matches (may exceed count($result->hits) when paginating)
$result->hitsCount;    // count of documents in this page
$result->getHit(0);    // first document, or [] if result is empty
$result->getIds();     // [3, 1, 7] — IDs in relevance order
```

When the [document store](document-store.md) is disabled, `$hits` contains id-only stubs:

```php
// store: false index
$result->hits; // [['id' => 3], ['id' => 1], ['id' => 7]]
```

## Full-text search

Scores results with Okapi BM25. Documents are ranked by how relevant each term is relative to the rest of the index.

```php
$result = $index->search('city car');

// Limit results
$result = $index->search('city car', limit: 20);
```

### Pagination

Use `offset` to page through results. `$totalHits` always reflects the full match count regardless of the page window.

```php
$page1 = $index->search('city car', limit: 20, offset: 0);
$page2 = $index->search('city car', limit: 20, offset: 20);

$totalPages = (int) ceil($page1->totalHits / 20);
```

### Typo tolerance

Typo tolerance is automatic. When a query word has no exact or prefix match and meets the minimum word length, Fuzor scans the wordlist for candidates within edit distance and ranks them closest-first before BM25 scoring.

```php
$result = $index->search('economi'); // matches 'economy'
```

The allowed edit distance scales automatically with word length:

| Word length      | Typos allowed |
|------------------|---------------|
| < 5 codepoints   | 0 (exact/prefix only) |
| 5–8 codepoints   | 1 |
| 9+ codepoints    | 2 |

Typo tolerance is controlled by `Config` properties — see [configuration.md](configuration.md):

| Config property      | Default | Effect                                                          |
|----------------------|---------|-----------------------------------------------------------------|
| `fuzzyMinWordLength` | `5`     | Minimum word length before the Levenshtein fallback fires       |
| `fuzzyPrefixLength`  | `3`     | Characters that must match exactly before the fuzzy scan begins |
| `fuzzyMaxExpansions` | `50`    | Max wordlist candidates evaluated                               |

### BM25 tuning
`k1`, `b`, `maxDocs`, `proximityBoost`, and `fieldBoosts` are set via `Config` at construction time — see [configuration.md](configuration.md).

### Field boosting

Pass `fieldBoosts` in `Config` to weight matches in specific fields more heavily. A term found in `title` (boost 5.0) outscores the same term repeated several times in `body` (boost 1.0):

```php
$index = new Index('/path/to/articles.db', config: new Config(
    fieldBoosts: ['title' => 5.0, 'body' => 1.0],
));

$result = $index->search('turbo');
// Documents where "turbo" appears in the title rank above those where it only appears in the body.
```

See [configuration.md](configuration.md#field-boosting) for details and performance notes.

## Synonyms

Synonyms are configured on the index and applied at query time — no reindexing required. When a query token matches a synonym source, its target terms are looked up alongside the original and their matching documents participate in the same BM25 group. Works in both `search()` and `searchBoolean()`.

### Equivalences

An equivalence group makes every term in the group expand to all the others. Searching any one finds documents containing any other.

```php
$index->setSynonyms(equivalences: [
    ['car', 'automobile', 'vehicle'],
    ['couch', 'sofa', 'settee'],
]);

$index->search('automobile'); // also returns documents containing 'car' or 'vehicle'
```

### One-way synonyms

A one-way synonym expands a source term to its targets, but not the reverse.

```php
$index->setSynonyms(oneWay: [
    'phone' => ['smartphone', 'mobile'],
    'tv'    => ['television'],
]);

$index->search('phone');      // also returns 'smartphone' and 'mobile' documents
$index->search('smartphone'); // does NOT return 'phone' documents
```

### Combining both types

```php
$index->setSynonyms(
    equivalences: [
        ['car', 'automobile', 'vehicle'],
    ],
    oneWay: [
        'phone' => ['smartphone', 'mobile'],
    ],
);
```

`setSynonyms()` always replaces the full synonym configuration. Calling it again discards all previously configured synonyms.

### Reading and clearing

```php
$index->getSynonyms();   // array<string, list<string>> — flat source → targets map (normalized forms)
$index->clearSynonyms(); // removes all synonyms
```

### Normalization

Terms are lowercased and stemmed (when the index has a language set) before storage, so synonym lookups stay consistent with how query tokens and indexed terms are processed. You do not need to pass pre-stemmed forms — `'automobiles'` on an English index is stored as the same stem as `'automobile'`.

Multi-word terms (e.g. `'mobile phone'`) are silently skipped. Only single-word synonyms are supported.

### Phrase search

Synonyms are not applied inside quoted phrases — `"automobile wash"` matches those exact words only.

## Boolean search

Set-based: no BM25 scoring. Useful for filtering rather than ranking.

```php
$result = $index->searchBoolean('sedan or coupe');
$result = $index->searchBoolean('suv -electric');
$result = $index->searchBoolean('fast & comfortable');
$result = $index->searchBoolean('(sedan or coupe) -electric');
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
$result = $index->search('"quick brown fox"');

// Mix phrases and free keywords
$result = $index->search('"quick brown" sedan');

// Multiple phrases — all must match
$result = $index->search('"quick brown" "fast car"');

// In boolean queries
$result = $index->searchBoolean('"quick brown" or sedan');
$result = $index->searchBoolean('"exact phrase" -electric');
```

Phrase words participate in BM25 scoring normally. `asYouType` applies to the last token even when it is inside a phrase — `"quick brow"` matches `quick` followed immediately by any word starting with `brow`.

## As-you-type prefix

When `asYouType` is `true` (default), the last query word is matched as a prefix — so `fast se` also matches documents containing `sedan`. Applies to both `search()` and `searchBoolean()`.

```php
// Disable for exact keyword queries
$result = $index->search('sedan', asYouType: false);
$result = $index->searchBoolean('sedan or coupe', asYouType: false);
```

## Facet filtering

Pass a `filter` map to restrict results to documents matching specific facet attribute values. See [indexing.md](indexing.md) for how to declare `facetFields` on an index.

### String facets

Pass a single value or an array of values. An array is treated as OR within that key: the document must match at least one value. Multiple keys are AND: all constraints must be satisfied.

```php
// Single value
$result = $index->search('watch', filter: ['brand' => 'Casio']);

// Multiple values — OR within the key
$result = $index->search('watch', filter: ['brand' => ['Casio', 'Seiko']]);

// Multiple keys — AND between keys
$result = $index->search('watch', filter: [
    'brand'    => 'Casio',
    'category' => 'Watches',
]);
```

### Numeric range facets

Use `FacetRange` to filter by a numeric range. Either bound may be `null` (open-ended).

```php
use Fuzor\FacetRange;

// Price between 50 and 200 (inclusive on both ends)
$result = $index->search('watch', filter: [
    'price' => FacetRange::between(50.0, 200.0),
]);

// Price 100 or above (no upper bound)
$result = $index->search('watch', filter: [
    'price' => FacetRange::min(100.0),
]);
```

Filters compose with all other options:

```php
$result = $index->search('gshock', limit: 20, filter: [
    'brand' => 'Casio',
    'price' => FacetRange::max(300.0),
]);
```

`searchBoolean()` accepts the same `filter` parameter:

```php
$result = $index->searchBoolean('shock resistant', filter: ['brand' => 'Casio']);
```

## Facet counts

Pass a `facets` list to compute per-value counts across the result set.

```php
$result = $index->search('watch', facets: ['brand', 'category', 'price']);

$result->facetDistribution;
// ['brand' => ['Casio' => 12, 'Seiko' => 8, ...], 'price' => [...], ...]
```

### String facet counts

For string facets, `$facetDistribution['key']` is an `array<string, int>` of value → count pairs, sorted by count descending:

```php
$result->facetDistribution['brand'];
// ['Casio' => 12, 'Seiko' => 8, 'Citizen' => 5]

// Single value lookup
$result->facetDistribution['brand']['Casio'] ?? null;   // 12
$result->facetDistribution['brand']['Unknown'] ?? null; // null
```

### Numeric facet counts

For numeric facets (int/float field values), `$facetDistribution['key']` is an aggregate summary:

```php
$result->facetDistribution['price'];
// ['min' => 29.99, 'max' => 499.0, 'count' => 20]
```

### Combining facets with filters

`facets` and `filter` are independent and can be used together:

```php
$result = $index->search('watch', filter: ['brand' => 'Casio'], facets: ['category', 'price']);
```

### Disjunctive facet counting

In standard faceted navigation, the counts shown for a facet key should reflect how many results switching to another value would yield — not just the count already selected. Fuzor handles this automatically.

When a `filter` is active on a key that is also in the `facets` list, its counts are computed over the result set *without* that key's filter applied. All other facet key counts are computed over the filtered result set.

```php
$result = $index->search('watch', filter: ['brand' => 'Casio'], facets: ['brand', 'category']);

// Brand counts show all brands available in the unfiltered query result
$result->facetDistribution['brand'];
// ['Casio' => 12, 'Seiko' => 8, 'Citizen' => 5]

// Category counts are scoped to the Casio filter
$result->facetDistribution['category'];
// ['Watches' => 10, 'Accessories' => 2]
```

This makes it straightforward to build a faceted navigation UI where users can switch between values in a facet group without losing count context for the other values.

## Custom sort

By default results are ordered by relevance (BM25 score for `search()`, arbitrary stable order for `searchBoolean()`). Pass a `sort` list to override this with one or more field values instead.

Fields used for sorting must be declared as `facetFields` at index creation — the values are read from the facet index.

```php
// Cheapest first
$result = $index->search('watch', sort: ['price:asc']);

// Most expensive first
$result = $index->search('watch', sort: ['price:desc']);

// Multiple keys — left-to-right priority
$result = $index->search('watch', sort: ['brand:asc', 'price:asc']);
```

Each spec is a `'field:asc'` or `'field:desc'` string (case-insensitive direction). An invalid format throws `\InvalidArgumentException`.

### Tiebreaker

When two documents share the same sort value, BM25 score is used as a tiebreaker in `search()`. Boolean search has no scores, so ties are broken by document ID ascending.

### Null-last

Documents that do not have a value for the sort field always appear last, regardless of direction.

```php
// Docs with no 'price' field sort after all priced docs
$result = $index->search('watch', sort: ['price:asc']);
```

### Combining sort with filter and facets

`sort`, `filter`, and `facets` compose freely:

```php
$result = $index->search('watch', filter: ['brand' => 'Casio'], facets: ['category'], sort: ['price:asc']);
```

`sort` affects the order of hits and pagination; it does not change `$totalHits` or facet distribution.

`searchBoolean()` accepts the same `sort` parameter:

```php
$result = $index->searchBoolean('sedan or coupe', sort: ['price:asc']);
```

## Distinct / deduplication

Pass `distinct` to return at most N results per unique value of a facet field. This is useful for collapsing product variants (colour, size) so only the best representative per SKU group surfaces in results.

```php
// At most one result per brand
$result = $index->search('shirt', distinct: 'brand');

// At most two results per brand
$result = $index->search('shirt', distinct: 'brand', distinctCount: 2);
```

The field must be declared as a `facetField` at index creation. An unknown field name is silently ignored and all results pass through unchanged.

### Which document wins per group

The highest-scoring document for each distinct value is kept. When `sort` is also specified, the sort order determines the winner instead of BM25 score — the first document in sort order for each value survives.

```php
// Cheapest item per brand
$result = $index->search('shirt', sort: ['price:asc'], distinct: 'brand');
```

### Docs without a value

Documents that have no value for the distinct field are never collapsed with each other — each passes through independently.

### `$totalHits` with distinct

`$totalHits` reflects the total number of surviving documents after deduplication, not the raw match count. This keeps pagination correct: `ceil($totalHits / $limit)` gives the right page count.

```php
$result = $index->search('shirt', limit: 20, distinct: 'brand');
$totalPages = (int) ceil($result->totalHits / 20); // based on distinct-collapsed count
```

Use `limit: 0` to count distinct groups without fetching any documents:

```php
$countOnly = $index->search('shirt', limit: 0, distinct: 'brand');
$distinctGroups = $countOnly->totalHits;
```

### Combining distinct with filter and facets

`distinct`, `filter`, `facets`, and `sort` compose freely. Facet counts are computed over the pre-distinct result set (consistent with how counts behave relative to sort):

```php
$result = $index->search('shirt', filter: ['category' => 'tops'], facets: ['brand'], distinct: 'brand');
```

`searchBoolean()` accepts `distinct` and `distinctCount` with the same semantics:

```php
$result = $index->searchBoolean('shirt or blouse', distinct: 'brand');
```

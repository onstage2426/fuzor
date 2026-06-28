# Search

Fuzor has two search methods: `search()` for BM25 ranked results and `searchBoolean()` for set-based filtering. Both accept a `SearchOptions` object as the second argument — all options have defaults, so `new SearchOptions()` reproduces the basic behaviour.

```php
$result = $index->search('city car');
$result = $index->search('city car', new SearchOptions(limit: 20, offset: 40));
```

## Result object

Both methods return a `SearchResult`:

```php
$result->hits;        // list of document arrays in relevance order
$result->totalHits;   // total matches across all pages
$result->hitsCount;   // documents in this page

$result->getHit(0);   // first document, or [] if empty
$result->getIds();    // [3, 1, 7] — IDs in relevance order

count($result);       // same as $result->hitsCount
foreach ($result as $hit) { ... } // iterate hits directly
```

When the document store is disabled, `$hits` contains id-only stubs: `[['id' => 3], ...]`.

| Member | Type | Description |
|---|---|---|
| `$hits` | `list<array>` | Documents in relevance order; gains `_formatted` when formatting options are set |
| `$hitsCount` | `int` | Documents in this page (≤ limit) |
| `$totalHits` | `int\|null` | Total matches across all pages |
| `$query` | `string` | Original query string |
| `$limit` | `int\|null` | Page limit |
| `$offset` | `int\|null` | Page offset |
| `$facetDistribution` | `array<string, array<string,int>>` | Value → count per facet field |
| `$facetStats` | `array<string, array{min:float,max:float}>` | Numeric min/max per facet field |
| `getHit(int $index, array $default = [])` | `array` | Document at position (0-based); `$default` when out of bounds |
| `getIds()` | `list<int>` | Document IDs in relevance order |
| `toArray()` | `array` | Full result as a plain array |
| `toJSON(int $flags = 0)` | `string` | JSON-encoded result |

## Pagination

Use `offset` to page through results. `$totalHits` always reflects the full match count.

```php
$page1 = $index->search('city car', new SearchOptions(limit: 20, offset: 0));
$page2 = $index->search('city car', new SearchOptions(limit: 20, offset: 20));

$totalPages = (int) ceil($page1->totalHits / 20);
```

## Browse (empty query)

Pass an empty string (or whitespace-only string) to browse all documents without a text query. The full `SearchOptions` surface — filter, sort, facets, distinct, highlight, crop — works as usual.

```php
// All documents, newest-inserted first
$result = $index->search('');

// Sorted by price ascending
$result = $index->search('', new SearchOptions(sort: ['price:asc']));

// Filtered to one category with facet counts
$result = $index->search('', new SearchOptions(
    filter: ['category' => 'shoes'],
    facets: ['brand'],
));

// Paginated browse
$result = $index->search('', new SearchOptions(limit: 20, offset: 0));
$totalPages = (int) ceil($result->totalHits / 20);
```

When no `sort` is specified, results are returned in insertion order, newest first (`doc_id DESC`). `$totalHits` reflects the full document count (or filtered count when a filter is active).

`searchBoolean('')` follows the same path.

## As-you-type prefix

When `asYouType` is `true` (the default), the last query word is matched as a prefix — `"fast se"` also matches documents containing `"sedan"`.

```php
// Disable for exact keyword queries
$result = $index->search('sedan', new SearchOptions(asYouType: false));
```

## Phrase search

Wrap words in double quotes to require them to appear as a contiguous, ordered sequence:

```php
$result = $index->search('"quick brown fox"');
$result = $index->search('"quick brown" sedan'); // mix phrase + free keyword
$result = $index->search('"quick brown" "fast car"'); // multiple phrases
```

Phrase words participate in BM25 scoring normally. `asYouType` applies to the last token even inside a phrase.

## Typo tolerance

Automatic. When a query word has no exact or prefix match and is long enough, Fuzor scans the wordlist for candidates within edit distance.

```php
$result = $index->search('economi'); // matches 'economy'
```

| Word length | Typos allowed |
|---|---|
| < 5 characters | 0 (exact/prefix only) |
| 5–8 characters | 1 |
| 9+ characters | 2 |

Control the behaviour via `Config` — see [tuning.md](tuning.md).

## Boolean search

Set-based: no BM25 scoring. Useful for strict filtering rather than ranking.

```php
$result = $index->searchBoolean('sedan or coupe');
$result = $index->searchBoolean('suv -electric');
$result = $index->searchBoolean('fast & comfortable');
$result = $index->searchBoolean('(sedan or coupe) -electric');
```

| Syntax | Operator | Effect |
|---|---|---|
| `term1 term2` | AND | Both must be present |
| `term1 or term2` | OR | Either present |
| `-term` | NOT | Term must be absent |
| `term1 & term2` | AND | Explicit AND |
| `(term1 or term2)` | grouping | Override default precedence |

Default precedence (tightest to loosest): NOT → AND → OR. Use parentheses when OR needs to bind tighter.

`searchBoolean()` accepts all the same `SearchOptions` as `search()`.

## Synonyms

Synonyms are configured on the index and applied at query time — no reindexing needed.

### Equivalences

Every term in the group expands to all the others:

```php
$index->setSynonyms(equivalences: [
    ['car', 'automobile', 'vehicle'],
    ['couch', 'sofa', 'settee'],
]);

$index->search('automobile'); // also returns 'car' and 'vehicle' documents
```

### One-way synonyms

A source term expands to its targets, but not the reverse:

```php
$index->setSynonyms(oneWay: [
    'phone' => ['smartphone', 'mobile'],
]);

$index->search('phone');      // also returns 'smartphone' and 'mobile' documents
$index->search('smartphone'); // does NOT return 'phone' documents
```

`setSynonyms()` always replaces the full configuration. Call it again to update.

```php
$index->getSynonyms();   // array<string, list<string>> — normalized source → targets
$index->clearSynonyms(); // removes all synonyms
```

Terms are lowercased and stemmed before storage, so you don't need to pass pre-stemmed forms. Multi-word terms are silently skipped — only single-word synonyms are supported. Synonyms are not applied inside quoted phrases.

## Formatting (highlight & crop)

Pass `attributesToHighlight` or `attributesToCrop` to receive a `_formatted` key on each hit:

```php
$result = $index->search('mercedes sedan', new SearchOptions(
    attributesToHighlight: ['title', 'body'],
    attributesToCrop:      ['body'],
    cropLength:            200,
));

$hit = $result->getHit(0);
$hit['_formatted']['title']; // "<mark>Mercedes</mark> <mark>Sedan</mark> review"
$hit['_formatted']['body'];  // "… the new <mark>Mercedes</mark> E-Class is a fine <mark>sedan</mark> …"
```

See [formatting.md](formatting.md) for the full option reference and standalone Highlighter/Snippeter usage.

## Facet filtering

Pass a `filter` map to restrict results to documents matching specific facet values. Fields must be declared as `facetFields` at index creation — see [indexing.md](indexing.md).

```php
// Single value
$result = $index->search('watch', new SearchOptions(
    filter: ['brand' => 'Casio'],
));

// Multiple values — OR within the key
$result = $index->search('watch', new SearchOptions(
    filter: ['brand' => ['Casio', 'Seiko']],
));

// Multiple keys — AND between keys
$result = $index->search('watch', new SearchOptions(
    filter: ['brand' => 'Casio', 'category' => 'Watches'],
));
```

### Numeric range filters

Use `FacetRange` for numeric fields:

```php
use Fuzor\FacetRange;

$result = $index->search('watch', new SearchOptions(
    filter: ['price' => FacetRange::between(50.0, 200.0)],
));
```

| Factory | Effect |
|---|---|
| `FacetRange::between(float $gte, float $lte)` | Inclusive range |
| `FacetRange::min(float $gte)` | Lower bound (inclusive) |
| `FacetRange::max(float $lte)` | Upper bound (inclusive) |
| `FacetRange::gt(float $gt)` | Strict lower bound |
| `FacetRange::lt(float $lt)` | Strict upper bound |

## Facet counts

Pass a `facets` list to compute per-value counts across the result set:

```php
$result = $index->search('watch', new SearchOptions(
    facets: ['brand', 'category', 'price'],
));

$result->facetDistribution;
// ['brand' => ['Casio' => 12, 'Seiko' => 8], 'price' => [...], ...]

$result->facetStats;
// ['price' => ['min' => 29.99, 'max' => 499.0]]
```

### Disjunctive counting

When a `filter` is active on a key that is also in `facets`, its counts are computed over the result set *without* that key's filter — so all values stay visible even while one is selected:

```php
$result = $index->search('watch', new SearchOptions(
    filter: ['brand' => 'Casio'],
    facets: ['brand', 'category'],
));

// Brand counts show all brands, not just Casio
$result->facetDistribution['brand'];
// ['Casio' => 12, 'Seiko' => 8, 'Citizen' => 5]

// Category counts are scoped to the Casio filter
$result->facetDistribution['category'];
// ['Watches' => 10, 'Accessories' => 2]
```

## Facet value search

`facetSearch()` returns the values present in a facet field, with document counts — useful for autocompleting a filter dropdown as the user types.

```php
use Fuzor\FacetSearchQuery;

// All values for 'genre', ordered by count descending
$result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre'));

foreach ($result as $hit) {
    echo $hit['value'] . ': ' . $hit['count'] . "\n";
    // e.g. "Action: 42", "Drama: 31", ...
}
```

### Prefix match

The `facetQuery` is matched case-insensitively against the start of each value:

```php
// "sc" matches "Science Fiction" but not "Action" or "Drama"
$result = $index->facetSearch(new FacetSearchQuery(
    facetName:  'genre',
    facetQuery: 'sc',
));
```

### Restricting to a document set

Use `query` to restrict candidates via FTS and `filter` to apply facet filters before counting:

```php
$result = $index->facetSearch(new FacetSearchQuery(
    facetName:  'genre',
    facetQuery: 'sc',
    query:      'adventure',
    filter:     ['year' => FacetRange::min(2000)],
    limit:      10,
));
```

Only values that appear on documents satisfying both the FTS query and all filters are returned.

### Result object

`facetSearch()` returns a `FacetSearchResult`:

```php
$result->facetHits;        // list<array{value: string, count: int}>
$result->facetQuery;       // the prefix that was searched
count($result);            // number of values returned
foreach ($result as $hit) { ... }
$result->toArray();        // serialisable snapshot
$result->toJSON();
```

### `FacetSearchQuery` reference

| Property | Default | Description |
|---|---|---|
| `facetName` | _(required)_ | Facet field to search; must be declared as a `facetField` at index creation |
| `facetQuery` | `''` | Prefix matched case-insensitively against values; empty string returns all values |
| `query` | `''` | FTS phrase to restrict candidate documents; empty string means all documents |
| `filter` | `[]` | Facet filters applied before counting; same type as `SearchOptions::$filter` |
| `limit` | `100` | Maximum number of values to return, ordered by count descending |

## Custom sort

Override relevance order with one or more field values. Fields must be declared as `facetFields` at index creation.

```php
$result = $index->search('watch', new SearchOptions(sort: ['price:asc']));
$result = $index->search('watch', new SearchOptions(sort: ['brand:asc', 'price:asc']));
```

Each spec is `'field:asc'` or `'field:desc'` (case-insensitive). Documents missing a sort field always appear last, regardless of direction.

When two documents share the same sort value, BM25 score is used as a tiebreaker in `search()`. Boolean search breaks ties by document ID ascending.

```php
$result = $index->searchBoolean('sedan or coupe', new SearchOptions(sort: ['price:asc']));
```

## Distinct / deduplication

Return at most N results per unique value of a facet field — useful for collapsing product variants so only the best representative per SKU group surfaces:

```php
// At most one result per brand
$result = $index->search('shirt', new SearchOptions(distinct: 'brand'));

// At most two
$result = $index->search('shirt', new SearchOptions(distinct: 'brand', distinctCount: 2));
```

The field must be declared as a `facetField`. Unknown field names are silently ignored.

When `sort` is also set, sort order determines which document wins per group instead of BM25 score:

```php
// Cheapest item per brand
$result = $index->search('shirt', new SearchOptions(
    sort:     ['price:asc'],
    distinct: 'brand',
));
```

`$totalHits` reflects the count after deduplication, keeping pagination correct:

```php
$result = $index->search('shirt', new SearchOptions(limit: 20, distinct: 'brand'));
$totalPages = (int) ceil($result->totalHits / 20);
```

Use `limit: 0` to count distinct groups without fetching documents:

```php
$countOnly = $index->search('shirt', new SearchOptions(limit: 0, distinct: 'brand'));
$distinctGroups = $countOnly->totalHits;
```

Documents with no value for the distinct field are never collapsed — each passes through independently.

## SearchOptions reference

| Property | Default | Description |
|---|---|---|
| `asYouType` | `true` | Match the last keyword as a prefix |
| `limit` | `100` | Maximum hits to return |
| `offset` | `0` | Hits to skip (pagination) |
| `filter` | `[]` | Facet filters — `array<string, string\|list<string>\|FacetRange>` |
| `facets` | `[]` | Facet fields to compute value counts for |
| `sort` | `[]` | Sort specs — `list<string>` of `'field:asc'` / `'field:desc'` |
| `distinct` | `null` | Facet field to collapse on |
| `distinctCount` | `1` | Max hits per distinct value |
| `attributesToHighlight` | `null` | Fields to highlight in `_formatted`; `['*']` for all |
| `highlightPreTag` | `'<mark>'` | Opening highlight tag |
| `highlightPostTag` | `'</mark>'` | Closing highlight tag |
| `attributesToCrop` | `null` | Fields to crop in `_formatted`; `['*']` for all |
| `cropLength` | `200` | Excerpt window size in characters |
| `cropMarker` | `'…'` | Inserted at crop boundaries |

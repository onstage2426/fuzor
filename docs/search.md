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
| `$warnings` | `list<string>` | Notices about options that were ignored or had no effect — see [Warnings](#warnings) |
| `$exhaustive` | `bool` | `false` when a cap may have cut matches out of the result — see [Approximate results](#approximate-results) |
| `$approximateFacets` | `list<string>` | Facet fields whose counts were computed over a capped document set |
| `getHit(int $index, array $default = [])` | `array` | Document at position (0-based); `$default` when out of bounds |
| `getIds()` | `list<int>` | Document IDs in relevance order |
| `toArray()` | `array` | Full result as a plain array |
| `toJSON(int $flags = 0)` | `string` | JSON-encoded result |

### Warnings

`$result->warnings` lists anything in the request that Fuzor ignored or that could not have an effect, such as a `sort`, `filter`, `facets`, or `distinct` field that is not a declared `facetField`. It is empty for a well-formed request.

```php
$result = $index->search('watch', new SearchOptions(sort: ['title:asc']));
$result->warnings; // ["Sort field 'title' is not a declared facet field; ignored."]
```

The messages are meant for logs and debugging; their wording is not a stable API. To check a field up front, compare it against `$index->facetFields`. A declared field that no document has a value for yet does not produce a warning.

### Approximate results

Fuzor bounds the work behind a query with a few caps (see [tuning.md](tuning.md)). When one of them cuts something off, the result says so instead of silently undercounting:

| Flag | Set when | Effect |
|---|---|---|
| `$exhaustive === false` | A keyword matched more than `Config::$maxDocs` documents, or the last keyword's prefix matched more than `Config::$fuzzyMaxExpansions` terms | Hits come from the best candidates; `$totalHits` and facet counts are estimates |
| `$approximateFacets` lists a field | That field's counts covered more than `Config::$maxFacetCountDocs` matching documents | Its counts in `$facetDistribution` / `$facetStats` are approximate |

```php
$result = $index->search('shoe', new SearchOptions(facets: ['brand']));

if (!$result->exhaustive) {
    // e.g. show "about {$result->totalHits} results"
}
```

Each cap that was hit also adds a line to `$warnings`. A browse (empty query) is always exhaustive; only its filtered facet counts can be approximate. `FacetSearchResult::$exhaustive` is `false` when the `query` restricting a facet search was capped.

This mirrors Meilisearch's split between `estimatedTotalHits` and an exhaustive `totalHits`: the numbers stay cheap to compute, and callers can tell when they are estimates.

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

A browse is answered by SQL over the whole index, so `$totalHits`, the page, and the sort order are exact at any index size. As in Meilisearch, a sorted browse walks the sort field's index in order and stops once the page is full, so its cost follows the page position, not the index size. On a 45k-document index a sorted or filtered page takes well under 5 ms.

Facet counts are exact when no filter applies to them. With a filter they are counted over at most `Config::$maxFacetCountDocs` matching documents, the same cap as in `search()` — see [tuning.md](tuning.md#facets). `distinct` has to look at every matching document to count the surviving groups, so a browse with `distinct` costs time proportional to the number of matches.

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
| `term1 or term2`, `term1 \| term2` | OR | Either present |
| `-term`, `~term` | NOT | Term must be absent |
| `term1 & term2` | AND | Explicit AND |
| `(term1 or term2)` | grouping | Override default precedence |

Default precedence (tightest to loosest): NOT → AND → OR. Use parentheses when OR needs to bind tighter.

Spacing never changes the meaning: `a|b`, `a | b`, and `a or b` are the same query, and extra, leading, or trailing whitespace is ignored. The parser is forgiving with half-typed input — a dangling operator (`shirt |`), an unclosed parenthesis (`(shirt`), or a lone symbol such as `+` or `-` is dropped rather than emptying the result. A hyphen inside a word is part of the word (`e-mail`); only a leading one negates.

NOT subtracts from the terms it is combined with by AND, in any order: `shirt -jeans` and `-jeans shirt` are the same. A query that is **only** negations (`-jeans`) matches nothing — there is no positive set to subtract from — and inside an OR a negation contributes nothing (`shirt or -jeans` behaves like `shirt`).

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

Terms are lowercased and stemmed before storage, so you don't need to pass pre-stemmed forms. Multi-word terms are skipped — only single-word synonyms are supported — and `setSynonyms()` returns the list of raw terms it skipped, so you can surface a warning instead of failing silently:

```php
$skipped = $index->setSynonyms(oneWay: ['mobile phone' => ['smartphone']]);
// $skipped === ['mobile phone']
```

Synonyms are not applied inside quoted phrases.

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

Filtering on a field that is not a declared `facetField` matches no documents and adds a [warning](#warnings).

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
$result->warnings;         // list<string> — e.g. an undeclared facetName or filter field
$result->exhaustive;       // false when the `query` candidates were capped (maxFacetCountDocs)
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

Each spec is `'field:asc'` or `'field:desc'` (case-insensitive). A malformed spec throws `\InvalidArgumentException`. A spec naming a field that is not a declared `facetField` is ignored and reported in [`$warnings`](#warnings); the remaining specs still apply, and with none left the result keeps its normal order (relevance, or newest first for a browse).

Values are ordered as follows:

- **Numbers before strings**, in both directions. Numbers are facet values indexed as PHP `int` or `float`.
- **Numbers** compare by value.
- **Strings** compare by their bytes (`strcmp`). This is case-sensitive, so `"Zebra"` sorts before `"apple"`, and accented letters sort after `z`. A numeric-looking string is still a string: `"10"` sorts before `"9"`. Index numbers as `int`/`float` to sort them numerically (range filters and `facetStats` need that too).
- **Multi-value fields** sort by the value that places the document earliest: its smallest value ascending, its largest descending.
- **Documents missing the field** always appear last, regardless of direction.

For a human-facing A–Z order, store a normalized copy as its own facet field and sort on that. `Tokenizer::sortKey()` lowercases, folds Latin accents to their base letter (`é` → `e`, `ß` → `ss`), and collapses whitespace, using a built-in table so every server produces the same key:

```php
use Fuzor\Tokenizer;

$index = new Index('/path/to/products.db', schema: new SchemaConfig(
    facetFields: ['brand', 'price', 'title_sort'],
));
$index->insert([[
    'id'         => 1,
    'title'      => 'Éclair au chocolat',
    'title_sort' => Tokenizer::sortKey('Éclair au chocolat'), // "eclair au chocolat"
]]);

$result = $index->search('', new SearchOptions(sort: ['title_sort:asc']));
```

When two documents share the same sort value, BM25 score is used as a tiebreaker in `search()`. Boolean search breaks ties by document ID ascending.

**Compared with Meilisearch:** the type order, byte order for strings, and missing-values-last rule match Meilisearch. Two differences: Meilisearch compares strings case-insensitively (planned for Fuzor 2.0), and by default Meilisearch applies `sort` only as a tiebreaker after its relevance rules, whereas in Fuzor the sort fields decide the order and relevance breaks ties.

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

The field must be declared as a `facetField`. An undeclared field has no effect and adds a [warning](#warnings). For a multi-value field, each document is grouped by its smallest value.

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
| `escapeFormatted` | `false` | Make every `_formatted` value safe HTML (stored text escaped, tags verbatim) — see [formatting.md](formatting.md#rendering-_formatted-as-html) |

# Performance

What requests cost, what makes them expensive, and the settings that trade speed for exactness.

## Measurements

A 44k-product catalog with HTML descriptions, default settings, one PHP process (i5-9600K, PHP 8.5,
SQLite 3.46.1, measured on 2026-11-14; reads are medians):

| Request | Time |
|---|---|
| Shop page: browse with 5 facets, one value excluded | 3.5 ms |
| Facet counts of the whole catalog / price bounds only | 0.5 / 0.9 ms |
| Listing: two values of one field, 6 facets, sorted | 4.1 ms |
| Price range sorted by price | 0.4 ms |
| Sorted page at offset 20,000 | 0.01 ms |
| Two-word search / with a typo | 15 / 19 ms |
| Three-word search | 37 ms |
| Search with a filter, sort and 6 facets | 38 ms |
| `distinct` browse / with a filter and sort | 18 / 35 ms |
| `update()` of 200 documents, price only / title changed | 33 / 136 ms |
| `delete()` of 50 documents | 11 ms |
| `insert()` of the catalog, from a generator | 24 s, 172 MB peak |
| `rebuild()` | 23 s, 208 MB peak |
| `snapshotTo()` (360 MB file) | about 2 s |

On 1.43M products (a 3 GB index), searches take 6–45 ms, and filtered category pages with facets up
to about 35 ms.

## What costs what

**Browsing** (an empty query, a category page) is the cheapest request. A sorted page walks the sort
field's index and stops when the page is full; facet counts without a filter come from counts kept
up to date on every write, and with a filter from one packed row per matching document, about
0.6–1 µs per document whatever the number of facet fields.

**A search** first reads each word's best `Limits::$candidatesPerWord` documents, then ranks the
documents of the page's words bucket exactly when there are at most 2,000 of them. Its total and
facet counts are counted over every match, which adds about 1 µs per match: a word that matches a
third of the catalog costs more than a rare one. Typos and the as-you-type prefix add the documents
of every word they expand to.

**Some requests grow with the index, not the page.** Keep them off hot paths on large indexes, or
cache their results:

- `distinct`, which looks at every match to count the groups;
- facet counts of a filtered request over more matches than `facetCountDocs`, which stop at the
  cap (and are then approximate);
- facet counts of a browse whose only filters are exclusions, at about 1 µs per excluded document;
- `facetSearch()` with a filter and no query.

**Writes.** Insert documents in bulk: one `insert()` of many documents is far faster than many of
one. An `update()` that changes no searchable field rewrites only the stored document and its facet
values; a `delete()` marks the word index entries and removes them later in bulk
([Indexing](indexing.md#deleting)).

## Returning less per hit

Every hit is read from the document store and decoded from JSON. An endpoint that needs only IDs,
for example to look products up elsewhere, skips that with `attributesToRetrieve: []`:

```php
$ids = $index->search($query, new SearchOptions(attributesToRetrieve: []))->getIds();

// Or only the fields the page shows:
$index->search($query, new SearchOptions(attributesToRetrieve: ['title', 'price']));
```

A field list still loads and decodes each document, so it makes the response smaller rather than
the request faster; the empty list removes the work (about 7% of a faceted request). Formatting
uses the full document either way, so a field can be highlighted without being returned:

```php
$index->search($query, new SearchOptions(
    attributesToHighlight: ['body'],
    attributesToRetrieve:  ['title'],  // body is highlighted in _formatted, not returned
));
```

## Settings

Settings are passed as a `Config` when the index is opened, and apply to that connection:

```php
use Fuzor\Config;
use Fuzor\Limits;

$index = new Index('/path/to/products.db', config: new Config(
    limits: new Limits(candidatesPerWord: 1_000),
));
```

Ranking settings (`fieldBoosts`, `k1`, `b`, `proximityBoost`) are on
[Searching](searching.md#relevance-settings), typo settings under
[Typo tolerance](searching.md#typo-tolerance); the [reference](reference.md#config) lists all.

### Limits

`Config::$limits` decides how much of a large result a search reads. The default, `Limits::fast()`,
keeps broad searches on large indexes fast and reports what its caps cut out (`exhaustive`,
`approximateFacets`, `warnings`). `Limits::exact()` lifts the candidate and facet caps: rankings and
facet counts cover every match, at a cost that grows with the matches, which is fine for tens of
thousands of documents and slow for broad queries on millions.

```php
new Config(limits: Limits::exact());                       // every result exact
new Config(limits: Limits::exact(totalHits: 10_000));      // and 10,000 reachable hits
new Config(limits: new Limits(candidatesPerWord: 1_000));  // fast, with more candidates
```

| `Limits` | Default | Effect |
|---|---|---|
| `candidatesPerWord` | `500` | Documents read per query word before ranking, its best by term frequency. The documents of the page's words bucket are ranked exactly while there are at most 4 × this (2,000); of a larger bucket the candidates and the 2,000 documents in which its rarest word weighs most are ranked, the rest follow, and `exhaustive` is `false`. A first word in at most 2,000 documents is read whole; a larger one also contributes its 500 best by relevance. Higher values give more pages identical to a full ranking, at more cost per search. On the 44k catalog 97% of first pages equal the full ranking at the default (94% with a typo in the query; measured 2026-11-07) |
| `facetCountDocs` | `50000` | Most matching documents counted for facets (filtered browse, search, `searchBoolean()`, `facetSearch()` with `q`). Above it, counts cover the newest matches and the field is in `approximateFacets`. Counts without a filter and query, or with only exclusions, are always exact |
| `totalHits` | `1000` | Most documents a search reaches: `totalHits` stops here (`totalHitsCapped`), and `offset` / `limit` are clamped to it. Searches with many matches count up to it, so a larger value costs more on broad queries |

A last word whose prefix matches more than `Config::$fuzzyMaxExpansions` (50) words is cut in both
presets, so typing `s` does not expand to every word starting with s.

### Other settings

| `Config` | Default | Effect |
|---|---|---|
| `fuzzyPrefixLength` | `3` | Characters at the start of a word that a typo may not change; lower values find more typos and cost more |
| `fuzzyMaxExpansions` | `50` | Most indexed words a typo or a prefix expands to (a typo: closest first, then most frequent; a prefix: shortest first) |
| `proxWindowSize` | `0` | Most documents the proximity boost is computed for; `0` computes it for as many as the page needs |
| `maxValuesPerFacet` | `100` | Most values per field in `facetDistribution`; `0` returns all |
| `insertChunkSize` | `2000` | Documents per step of a bulk write; memory follows it (about 170 MB at 2,000 on HTML-rich documents). Lower it under a tight `memory_limit` |
| `bulkSynchronousOff` | `true` | Skip the sync to disk during bulk writes ([Durability](indexing.md#durability-during-bulk-loads)) |
| `cacheSizeKb` | `65536` | SQLite page cache per connection, in KB; lower it on many workers ([Operations](operations.md#memory-with-many-workers)) |
| `mmapSizeBytes` | `536870912` | Memory-mapped window, shared between processes; `0` turns it off |
| `busyTimeoutMs` | `5000` | How long a write waits for another process's write before throwing |

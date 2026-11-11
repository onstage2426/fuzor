# Tuning

Pass a `Config` object to customise BM25 scoring, typo tolerance, and facet behaviour. All settings are per-connection and apply to every query on that index instance.

```php
use Fuzor\Config;
use Fuzor\Index;
use Fuzor\Limits;

$index = new Index('/path/to/articles.db', config: new Config(
    limits: new Limits(candidatesPerWord: 200),
    k1:     1.5,
));
```

All properties have sensible defaults — you only need to set what differs from below.

## Limits

`Config::$limits` decides how much of a large result set a search reads. The default,
`Limits::fast()`, reads each query word through its best candidates and counts facets over at most
50,000 matching documents, so a broad search on a large index stays fast; whatever a cap cut out is
reported (`$exhaustive`, `$approximateFacets`, `$warnings`). `Limits::exact()` lifts both caps:
rankings, totals and facet counts are computed over every matching document, at a cost that grows
with the matches (fine for tens of thousands of documents, slow for broad queries on millions); a
last word whose prefix matches more than `fuzzyMaxExpansions` terms is still cut. Both keep `totalHits` at 1,000 unless you pass another value.

```php
use Fuzor\Limits;

new Config(limits: Limits::exact());                       // every result exact
new Config(limits: Limits::exact(totalHits: 10_000));      // and 10,000 reachable hits
new Config(limits: new Limits(candidatesPerWord: 1_000));  // fast, with more candidates
```

| Property | Default | Effect |
|---|---|---|
| `candidatesPerWord` | `500` | Documents fetched per keyword before scoring (its best matches by term frequency). Higher = better ranking inside large result sets; lower = faster queries. The documents that contain the words of the page's words bucket are read and ranked exactly while there are at most 4 × `candidatesPerWord` of them (2,000), and so is a first word in at most that many documents, so the page equals the ranking without any cap; of a larger bucket the candidates and the 4 × `candidatesPerWord` documents in which its rarest word weighs most (BM25 term frequency) are ranked, the rest follow them, and the result reports `exhaustive: false`; pages past the candidates take the other matches after them, so no page up to `totalHits` is empty. When the page reaches the first word alone (a one-word query, or a word that matches nothing), the first word's `candidatesPerWord` documents with the best BM25 score are fetched instead, so the top of that ranking is exact too (without sort fields). On a 44k-product catalog 97% of first pages are identical to the uncapped ranking at the default (94% with a typo in the query), and a plain search takes about 7 ms (homelab, 2026-11-07). Lower values trade identical pages for speed, higher ones the reverse (measured before the first-word step: 79% at `candidatesPerWord: 250` in 12.3 ms, 92% at `1250` in 16.4 ms). Full matches are read as long as the rarest word is in at most 50,000 documents. |
| `facetCountDocs` | `50000` | Max matching documents included in facet counting, for `search()`, `searchBoolean()`, a filtered browse, and `facetSearch()` with a `q`. Above this cap the counts cover the newest matches only (highest document IDs) and are approximate, and the field is listed in `$approximateFacets`. Facet counts with no filter and no query are always exact, and so are those whose only filters are `FacetExclude` exclusions, or value filters on a field every document has once that leave out at most this many documents. |
| `totalHits` | `1000` | Most documents a search can reach, as Meilisearch's `pagination.maxTotalHits`: `offset` and `limit` are clamped so no hit ranked past it is returned, and `$totalHits` stops at it (`$totalHitsCapped` is then `true`). Applies to `search()`, `searchBoolean()` and a browse; facet counts are not affected. A search with more than `candidatesPerWord` matches per word counts them up to this number, so a larger value costs more on broad queries. Must be at least 1. |

## BM25

| Property | Default | Effect |
|---|---|---|
| `k1` | `1.2` | Term frequency saturation. Lower values reduce the advantage of repeated terms. |
| `b` | `0.75` | Length normalisation weight. `0` disables it; `1` fully penalises long documents. |
| `proximityBoost` | `1.0` | How much to reward terms that appear close together. `0` disables proximity ranking. Words in different fields count as 8 positions apart per word gap, so a document with the words close together in one field ranks above one that has them only across fields. |
| `proxWindowSize` | `0` | Max candidate documents to apply proximity ranking to (0 = as many as the requested page needs; Fuzor stops as soon as the page is settled). A positive value reranks only that many of the best BM25 matches. |

## Typo tolerance

| Property | Default | Effect |
|---|---|---|
| `typoTolerance` | `new TypoTolerance()` | When typos are allowed; see the table below. |
| `fuzzyPrefixLength` | `3` | Characters that must match exactly before the fuzzy scan begins. |
| `fuzzyMaxExpansions` | `50` | Max terms a typo'd word expands to (closest first, then most frequent), and max terms an as-you-type prefix expands to (shortest first). A capped prefix expansion reports `exhaustive: false`. |

`TypoTolerance` properties:

| Property | Default | Effect |
|---|---|---|
| `enabled` | `true` | `false`: words match exactly or by prefix only. |
| `minWordSizeForOneTypo` | `5` | Shortest query word (codepoints) that may have one typo. |
| `minWordSizeForTwoTypos` | `9` | Shortest query word that may have two typos. Must be at least `minWordSizeForOneTypo` and at most 255. |
| `disableOnNumbers` | `false` | `true`: words containing a digit (SKUs, model numbers, years) match exactly. |
| `disableOnWords` | `[]` | Query words that always match exactly; case-insensitive, stemmed like query words. |

A typo is an inserted, deleted, or replaced character, or two neighbouring characters swapped.

## Facets

| Property | Default | Effect |
|---|---|---|
| `maxValuesPerFacet` | `100` | Max values returned per facet field in `$facetDistribution`, after ordering them (`SearchOptions::$sortFacetValuesBy`, alphabetical by default). `0` returns all values. |

## Field boosting

Weight specific fields so a match in `title` outranks a match in `body`:

```php
$index = new Index('/path/to/articles.db', config: new Config(
    fieldBoosts: ['title' => 5.0, 'body' => 1.0],
));
```

When `fieldBoosts` is set, term frequency is computed as `Σ boost(field) × hit_count(field)`. Fields omitted from the map default to `1.0`. Fields that do not appear in any document are ignored.

The default (`[]`) uses the standard uniform BM25 path with no overhead.

## Bulk loads

| Property | Default | Effect |
|---|---|---|
| `insertChunkSize` | `2000` | Documents tokenised and written per step of a multi-document `insert()` (all steps commit in one transaction). Memory during a bulk insert or `rebuild()` follows this number: about 127 MB at 2,000 on a catalog with HTML descriptions, 275 MB at 5,000. Lower it under a tight `memory_limit`; larger values did not load faster. See [Inserting](indexing.md#inserting). |
| `bulkSynchronousOff` | `true` | `PRAGMA synchronous=OFF` during bulk loads; see [Durability during bulk loads](indexing.md#durability-during-bulk-loads). |

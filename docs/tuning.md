# Tuning

Pass a `Config` object to customise BM25 scoring, typo tolerance, and facet behaviour. All settings are per-connection and apply to every query on that index instance.

```php
use Fuzor\Config;
use Fuzor\Index;

$index = new Index('/path/to/articles.db', config: new Config(
    maxDocs: 200,
    k1:      1.5,
));
```

All properties have sensible defaults — you only need to set what differs from below.

## BM25

| Property | Default | Effect |
|---|---|---|
| `maxDocs` | `500` | Max documents fetched per keyword before scoring (its best matches by term frequency). Higher = better recall; lower = faster queries. When a keyword matches more, the result reports `exhaustive: false`. A document found through another keyword is still scored for every keyword it contains, so a document matching all words is ranked as such even when one word is very common. |
| `k1` | `1.2` | Term frequency saturation. Lower values reduce the advantage of repeated terms. |
| `b` | `0.75` | Length normalisation weight. `0` disables it; `1` fully penalises long documents. |
| `proximityBoost` | `1.0` | How much to reward terms that appear close together. `0` disables proximity ranking. |
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
| `maxFacetCountDocs` | `10000` | Max matching documents included in facet counting, for `search()`, `searchBoolean()`, a filtered browse, and `facetSearch()` with a `query`. Counts are approximate above this cap, and the field is listed in `$approximateFacets`. Facet counts with no filter and no query are always exact, and so are those whose only filters are `FacetExclude` exclusions. |
| `maxValuesPerFacet` | `100` | Max values returned per facet field in `$facetDistribution`, after ordering them (`SearchOptions::$sortFacetValuesBy`, count by default). `0` returns all values. |

## Field boosting

Weight specific fields so a match in `title` outranks a match in `body`:

```php
$index = new Index('/path/to/articles.db', config: new Config(
    fieldBoosts: ['title' => 5.0, 'body' => 1.0],
));
```

When `fieldBoosts` is set, term frequency is computed as `Σ boost(field) × hit_count(field)`. Fields omitted from the map default to `1.0`. Fields that do not appear in any document are ignored.

The default (`[]`) uses the standard uniform BM25 path with no overhead.

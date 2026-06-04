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
| `maxDocs` | `500` | Max documents fetched per keyword before scoring. Higher = better recall; lower = faster queries. |
| `k1` | `1.2` | Term frequency saturation. Lower values reduce the advantage of repeated terms. |
| `b` | `0.75` | Length normalisation weight. `0` disables it; `1` fully penalises long documents. |
| `proximityBoost` | `1.0` | How much to reward terms that appear close together. `0` disables proximity ranking. |
| `proxWindowSize` | `0` | Max candidate documents to apply proximity ranking to (0 = all). Set a positive value to cap CPU cost on very broad queries. |

## Typo tolerance

| Property | Default | Effect |
|---|---|---|
| `fuzzyMinWordLength` | `5` | Minimum word length before Levenshtein fallback fires. Shorter words are exact/prefix only. |
| `fuzzyPrefixLength` | `3` | Characters that must match exactly before the fuzzy scan begins. |
| `fuzzyMaxExpansions` | `50` | Max wordlist candidates evaluated per fuzzy term. |

The allowed edit distance scales automatically with word length: 1 typo for 5–8 characters, 2 typos for 9+.

## Facets

| Property | Default | Effect |
|---|---|---|
| `filterMaxDocs` | `2000` | Max documents fetched per FTS term when a facet filter is active. |
| `maxFacetCountDocs` | `10000` | Max result documents included in the facet count query. Counts are approximate above this cap. |
| `maxValuesPerFacet` | `100` | Max values returned per facet field in `$facetDistribution`, ordered by count descending. `0` returns all values. |

## Field boosting

Weight specific fields so a match in `title` outranks a match in `body`:

```php
$index = new Index('/path/to/articles.db', config: new Config(
    fieldBoosts: ['title' => 5.0, 'body' => 1.0],
));
```

When `fieldBoosts` is set, term frequency is computed as `Σ boost(field) × hit_count(field)`. Fields omitted from the map default to `1.0`. Fields that do not appear in any document are ignored.

The default (`[]`) uses the standard uniform BM25 path with no overhead.

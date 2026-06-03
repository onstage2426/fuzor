# Configuration

## Search tuning — `Config` DTO

Pass a `Config` object at construction time to tune BM25 and fuzzy behaviour. All properties are read-only; create a new instance to use different values.

```php
use Fuzor\Config;
use Fuzor\Index;

$index = new Index('/path/to/articles.db', config: new Config(
    maxDocs: 200,
    k1:      1.5,
    b:       0.8,
));
```

Omitting `config` uses the optimised defaults shown below.

| Property             | Default  | Effect                                                                 |
|----------------------|----------|------------------------------------------------------------------------|
| `maxDocs`            | `500`    | Max documents fetched per keyword before BM25 scoring                  |
| `k1`                 | `1.2`    | BM25 term frequency saturation — lower reduces weight of repeated terms|
| `b`                  | `0.75`   | BM25 length normalisation — `0` disables it, `1` fully normalises     |
| `fuzzyMinWordLength` | `5`      | Minimum word length (codepoints) before the Levenshtein fallback fires; shorter words use exact/prefix only |
| `fuzzyPrefixLength`  | `3`      | Characters that must match exactly before the fuzzy scan begins        |
| `fuzzyMaxExpansions` | `50`     | Max wordlist candidates evaluated during fuzzy search                  |
| `proximityBoost`     | `1.0`    | Strength of bonus for multi-term queries where terms appear close together; `0` disables |
| `filterMaxDocs`      | `2000`   | Max documents fetched per FTS term when a facet `filter` is active; higher values improve recall at the cost of more scoring work |
| `maxFacetCountDocs`  | `10000`  | Max result doc IDs included in the facet count query; counts are approximate when the result set exceeds this cap |
| `maxValuesPerFacet`  | `100`    | Max values returned per facet field in `$facetDistribution`, ordered by count descending; `0` returns all values |
| `fieldBoosts`        | `[]`     | Per-field BM25 multipliers — see [Field boosting](#field-boosting) below |

## Field boosting

By default Fuzor treats all fields equally: a term hit in `title` counts the same as one in `body`. Field boosting lets you weight fields so that a match in a more important field outranks a match in a less important field.

```php
$index = new Index('/path/to/articles.db', config: new Config(
    fieldBoosts: ['title' => 5.0, 'body' => 1.0],
));
```

When `fieldBoosts` is set, term frequency is computed as:

```
weighted_tf = Σ boost(field) × hit_count(field)
```

This weighted TF replaces the raw `hit_count` in the BM25 formula. A term found once in `title` (boost 5.0) produces a weighted TF of 5, while three hits in `body` (boost 1.0) produce a weighted TF of 3 — so the title match scores higher.

Fields omitted from the map fall back to a multiplier of `1.0`. Fields that do not appear in any document are simply ignored.

### Performance

The uniform BM25 path is completely unaffected when `fieldBoosts` is empty (the default). When boosts are configured, a second query fetches field hit counts for the bounded candidate set after the initial BM25 pass — the main `doclist` LIMIT query is unchanged.

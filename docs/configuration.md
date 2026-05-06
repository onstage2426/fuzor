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

| Property             | Default | Effect                                                                 |
|----------------------|---------|------------------------------------------------------------------------|
| `maxDocs`            | `500`   | Max documents fetched per keyword before BM25 scoring                  |
| `k1`                 | `1.2`   | BM25 term frequency saturation — lower reduces weight of repeated terms|
| `b`                  | `0.75`  | BM25 length normalisation — `0` disables it, `1` fully normalises     |
| `fuzzyPrefixLength`  | `3`     | Characters that must match exactly before fuzzy edit distance kicks in |
| `fuzzyMaxExpansions` | `50`    | Max wordlist candidates evaluated during fuzzy search                  |
| `fuzzyDistance`      | `2`     | Max Levenshtein edit distance accepted as a fuzzy match                |
| `proximityBoost`     | `1.0`   | Strength of bonus for multi-term queries where terms appear close together; `0` disables |

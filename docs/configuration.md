# Configuration

All configuration properties on an `IndexHandle` instance. Properties are set directly and take effect on the next search call.

```php
$index = Index::open('/path/to/articles.db');

$index->asYouType        = false;
$index->fuzzyDistance    = 1;
$index->maxDocs          = 200;
```

## Properties

| Property             | Default | Effect                                                                 |
|----------------------|---------|------------------------------------------------------------------------|
| `asYouType`          | `true`  | Last query word matched as a prefix — `"cit"` matches `"city"`        |
| `fuzzyPrefixLength`  | `3`     | Characters that must match exactly before fuzzy edit distance kicks in |
| `fuzzyMaxExpansions` | `50`    | Max wordlist candidates evaluated during fuzzy search                  |
| `fuzzyDistance`      | `2`     | Max Levenshtein edit distance accepted as a fuzzy match                |
| `maxDocs`            | `500`   | Max documents fetched per keyword before BM25 scoring                  |
| `k1`                 | `1.2`   | BM25 term frequency saturation — lower reduces weight of repeated terms|
| `b`                  | `0.75`  | BM25 length normalisation — `0` disables it, `1` fully normalises     |

## Read-only

| Property   | Effect                                                                        |
|------------|-------------------------------------------------------------------------------|
| `language` | BCP 47 language tag active on this index; `null` if none was set at creation  |

Language is set at index creation time and cannot be changed. See [language.md](language.md).

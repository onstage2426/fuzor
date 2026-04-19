# Inspect Query

`inspectQuery()` walks the same pipeline as `search()` — tokenisation, stopword filtering, stemming, wordlist resolution — and returns a detailed breakdown of each step. Useful for understanding why results are returned or missing.

```php
$result = $index->inspectQuery('fast connections');
$result = $index->inspectQuery('fast connections', fuzzy: true);
```

Read-only: makes no writes and does not affect search results.

## Return shape

```php
[
    'raw_tokens'       => ['fast', 'connections'],   // tokens before filtering/stemming
    'filtered_tokens'  => ['fast', 'connect'],       // after stopwords + stemming
    'stopwords_active' => true,
    'stemmer_active'   => true,
    'all_stripped'     => false,                     // true if all tokens were stopwords
    'index_info'       => ['total_documents' => '42', 'avg_doc_length' => '128.4'],
    'tokens' => [
        [
            'raw'          => 'connections',         // original input word
            'processed'    => 'connect',             // after stemming
            'is_last'      => true,
            'found'        => true,
            'match_type'   => 'exact',               // 'exact', 'prefix', 'fuzzy', or 'none'
            'wordlist_rows' => [
                ['term' => 'connect', 'num_hits' => 18, 'num_docs' => 5, 'distance' => null],
            ],
            'num_hits'     => 18,
            'num_docs'     => 5,
        ],
        // …one entry per filtered token
    ],
    'boolean_postfix'  => ['fast', 'connect', '&'], // postfix expression used by searchBoolean()
]
```

## Common use cases

**Term not found** — `found: false` and `match_type: 'none'` means the term has no entry in the wordlist. The document either was never indexed, or the term was filtered out at index time.

**Unexpected stemming** — compare `raw` vs `processed` per token to see what the stemmer did to the query.

**All tokens stripped** — `all_stripped: true` means every query word was a stopword. Fuzor falls back to the unfiltered tokens in this case, so results are still returned.

# Inspect Query

`inspectQuery()` walks the same pipeline as `search()` — tokenisation, stopword filtering, stemming, wordlist resolution — and returns a detailed breakdown of each step. Useful for understanding why results are returned or missing.

```php
$result = $index->inspectQuery('fast connections');
$result = $index->inspectQuery('fast con', asYouType: true); // prefix on last token
```

Read-only: makes no writes and does not affect search results.

## Return value — `QueryInspection`

`inspectQuery()` returns a `QueryInspection` value object:

| Property | Type | Description |
|---|---|---|
| `rawTokens` | `list<string>` | Tokens before stopword filtering and stemming |
| `filteredTokens` | `list<string>` | Tokens after stopword filtering and stemming |
| `stopwordsActive` | `bool` | Whether a stopword filter is active on this index |
| `stemmerActive` | `bool` | Whether a stemmer is active on this index |
| `allStripped` | `bool` | `true` if every token was a stopword (fallback to raw tokens fires) |
| `totalDocuments` | `int` | Total documents in the index at query time |
| `avgDocLength` | `float` | Average document length in tokens |
| `tokens` | `list<QueryToken>` | Per-token detail, one entry per filtered token |
| `booleanPostfix` | `list<string>` | Postfix expression used by `searchBoolean()` |
| `phraseGroups` | `list<string>` | Raw contents of each quoted phrase in the query |

### `QueryToken`

Each entry in `$tokens` is a `QueryToken` value object:

| Property | Type | Description |
|---|---|---|
| `raw` | `string` | Original input word before filtering and stemming |
| `processed` | `string` | Token after stopword filtering and stemming |
| `isLast` | `bool` | `true` when this is the last token and `asYouType` prefix matching is active |
| `found` | `bool` | Whether the token matched anything in the wordlist |
| `matchType` | `string` | `'exact'`, `'prefix'`, `'fuzzy'`, or `'none'` |
| `wordlistRows` | `list<WordlistMatch>` | Matching wordlist entries |
| `numHits` | `int` | Total hit count across all matching wordlist entries |
| `numDocs` | `int` | Total document count across all matching wordlist entries |

### `$wordlistRows` entries

Each entry in `$wordlistRows` is an array:

| Key | Type | Description |
|---|---|---|
| `term` | `string` | Matched term in the wordlist |
| `numHits` | `int` | Total occurrences across all documents |
| `numDocs` | `int` | Number of documents containing this term |
| `distance` | `int\|null` | Levenshtein edit distance for fuzzy matches; `null` for exact/prefix |

## Example

```php
$result = $index->inspectQuery('fast connections');

$result->rawTokens;      // ['fast', 'connections']
$result->filteredTokens; // ['fast', 'connect']  (stemmed)
$result->stopwordsActive; // true
$result->stemmerActive;   // true
$result->allStripped;     // false

$token = $result->tokens[1];
$token->raw;       // 'connections'
$token->processed; // 'connect'
$token->matchType; // 'exact'
$token->found;     // true
$token->numHits;   // 18
$token->numDocs;   // 5

$token->wordlistRows[0]['term'];     // 'connect'
$token->wordlistRows[0]['distance']; // null
```

## Common use cases

**Term not found** — `found: false` and `matchType: 'none'` means the term has no entry in the wordlist. The document either was never indexed, or the term was filtered out at index time.

**Unexpected stemming** — compare `raw` vs `processed` per token to see what the stemmer did to the query.

**All tokens stripped** — `allStripped: true` means every query word was a stopword. Fuzor falls back to the unfiltered tokens in this case, so results are still returned.

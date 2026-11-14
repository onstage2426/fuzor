# Debugging

Three tools show why a search returns what it does: `inspectQuery()` for how the query's words
were read, ranking details for why one hit comes before another, and the result's flags for what a
limit cut off.

## How the query was read

`inspectQuery()` runs the first steps of `search()` (splitting, stop words, stemming, the lookup of
each word in the index) and returns what each step produced. It writes nothing.

```php
$inspection = $index->inspectQuery('hikng boo');  // asYouType: true by default

$inspection->rawTokens;       // ['hikng', 'boo']
$inspection->filteredTokens;  // ['hikng', 'boo']: after stop words and stemming

$typo = $inspection->tokens[0];
$typo->matchType;     // MatchType::Fuzzy
$typo->wordlistRows;  // [['term' => 'hiking', 'numHits' => 3, 'numDocs' => 3, 'distance' => 1]]

$last = $inspection->tokens[1];
$last->isLast;        // true: matched as a prefix
$last->matchType;     // MatchType::Prefix
$last->wordlistRows;  // [['term' => 'boots', 'numHits' => 3, 'numDocs' => 3, 'distance' => null]]
```

| `QueryInspection` | Type | Contains |
|---|---|---|
| `rawTokens` | `list<string>` | The query's words, before stop words and stemming |
| `filteredTokens` | `list<string>` | The words searched for, after stop words and stemming |
| `tokens` | `list<QueryToken>` | One entry per searched word (below) |
| `stopwordsActive` / `stemmerActive` | `bool` | Whether the index has stop words / a stemmer |
| `allStripped` | `bool` | Every word was a stop word, so the words are searched as typed |
| `phraseGroups` | `list<list<string>>` | The words of each quoted phrase |
| `negations` | `list<array>` | Each `-word` / `-"phrase"`: `raw` as typed, `tokens`, and `applied` (`false` when a word is not in the index, so the exclusion is dropped) |
| `booleanPostfix` | `list<string>` | The expression `searchBoolean()` evaluates, in postfix order |
| `totalDocuments` / `avgDocLength` | `int` / `float` | Documents in the index, and their average length in words |

| `QueryToken` | Type | Contains |
|---|---|---|
| `raw` / `processed` | `string` | The word as typed / after stemming |
| `isLast` | `bool` | The last word, matched as a prefix (`asYouType`) |
| `found` | `bool` | Whether any indexed word matched |
| `matchType` | `MatchType` | `Exact`, `Prefix`, `Fuzzy` (through a typo) or `None` |
| `wordlistRows` | `list<array>` | The indexed words it matched: `term`, `numHits` (occurrences), `numDocs`, `distance` (typos; `null` for exact and prefix matches) |
| `numHits` / `numDocs` | `int` | Sums over `wordlistRows` |

**Common findings:**

- **`found: false`**: no indexed word matches. Is the field searchable (`$index->searchableAttributes`)?
  Is the word too short for a typo, or is the typo within the first three characters?
- **`processed` differs from `raw` in an unexpected way**: the stemmer changed the word.
- **A prefix with exactly `fuzzyMaxExpansions` rows**: the prefix was cut; the result then reports
  `exhaustive: false`.
- **`allStripped: true`**: every word is a stop word; the words are searched as typed.

## Why a hit ranks where it does

`showRankingScoreDetails: true` adds `_rankingScoreDetails` to each hit: the values the ranking
compared, in rank order.

```php
$result = $index->search('hikng boots', new SearchOptions(
    sort: [Sort::asc('price')],
    showRankingScoreDetails: true,
));

$result->hits[0]['_rankingScoreDetails'];
// [
//     'words'     => ['order' => 0, 'matchingWords' => 2, 'maxMatchingWords' => 2],
//     'typo'      => ['order' => 1, 'typoCount' => 1, 'maxTypoCount' => 2],
//     'price:asc' => ['order' => 2, 'value' => 99.5],
//     'bm25'      => ['order' => 3, 'score' => 0.713, 'proximityFactor' => 1.0],
// ]
```

| Entry | Values | Meaning |
|---|---|---|
| `words` | `matchingWords`, `maxMatchingWords` | The hit's words bucket and the number of query words; more first |
| `typo` | `typoCount`, `maxTypoCount` | Typos in the matched words, and the most their budgets allow; fewer first |
| `field:asc` / `field:desc` | `value` | One per sort spec: the value compared (a number as float, `null` when the document lacks the field) |
| `bm25` | `score`, `proximityFactor` | Relevance, and the proximity factor it is multiplied by (`1.0` when proximity does not apply); higher first |

Hits with equal values are ordered by document ID. When a words bucket was too large to score
(`exhaustive: false`), its unscored members have `score` 0. A browse and `searchBoolean()` rank by
sort fields only, so they show only the sort entries (none without `sort`). The option costs
nothing when off.

**Compared with Meilisearch:** the same option, key and `words`, `typo` and sort entries. There is
no `_rankingScore` or per-rule score between 0 and 1: Fuzor's relevance is BM25, reported in its own
`bm25` entry.

## What a limit cut off

When a result looks incomplete, check its flags before anything else:

```php
$result->exhaustive;         // false: the page comes from the best candidates
$result->totalHitsCapped;    // true: more matches than Limits::$totalHits
$result->approximateFacets;  // fields whose counts covered only part of the matches
$result->warnings;           // one line per cap that was hit, for logs
```

[Searching](searching.md#when-a-result-is-approximate) explains each, and
[Performance](performance.md#limits) the settings behind them. A stemmer whose `id()` changed since
the index was built also adds a warning, until `rebuild()` ([Language](language.md#when-the-stems-change)).

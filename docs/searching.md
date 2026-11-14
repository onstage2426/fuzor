# Searching

`search()` ranks documents by relevance; `searchBoolean()` evaluates AND / OR / NOT queries.
Both take the query text and a `SearchOptions`, whose options all have defaults:

```php
use Fuzor\SearchOptions;
use Fuzor\Sort;

$result = $index->search('city car');
$result = $index->search('city car', new SearchOptions(limit: 20, offset: 40));
```

Filters, facet counts and sorting are options of the same call; they have their own pages:
[Filtering and facets](filtering-and-facets.md), [Sorting](sorting.md) and
[Formatting](formatting.md) for highlighting and excerpts.

## The result

Both methods return a `SearchResult`:

```php
$result->hits;        // the documents of this page, best first
$result->totalHits;   // matches over all pages, up to Limits::$totalHits (1,000)
$result->hitsCount;   // documents in this page

$result->getHit(0);   // first document, or [] when there is none
$result->getIds();    // [3, 1, 7]

count($result);                  // same as $result->hitsCount
foreach ($result as $hit) { … }  // iterate the hits
$result->toArray();              // everything as a plain array; toJSON() for JSON
```

Each hit is the stored document. Without a document store, hits are `['id' => 3]` stubs;
`attributesToRetrieve` trims the fields of each hit (see [Performance](performance.md#returning-less-per-hit)).
The other members (`facetDistribution`, `facetStats`, `exhaustive`, `approximateFacets`,
`totalHitsCapped`, `warnings`) are listed in the [reference](reference.md#searchresult).

### When a result is approximate

On large indexes some caps bound the work of a search (see [Concepts](concepts.md#exact-results-and-limits)).
When one of them cuts something off, the result says so instead of undercounting silently:

| Flag | Set when | What it means |
|---|---|---|
| `exhaustive === false` | A query word matched more than `Limits::$candidatesPerWord` documents and the page could not be ranked from them exactly, or the last word's prefix matched more than `Config::$fuzzyMaxExpansions` words | The page comes from the best candidates and can differ from a full ranking. Pages past the ranked candidates are filled with the other matches (newest first, or by `sort`), so every page up to `totalHits` is full. `totalHits` is still counted exactly over every match (also with `distinct`), and so are `search()`'s facet counts; `searchBoolean()`'s facet counts and the total of a capped prefix are estimates |
| `totalHitsCapped === true` | More documents match than `Limits::$totalHits` (1,000) | `totalHits` is the cap, and no hit past it can be paged to |
| `approximateFacets` lists a field | Its counts covered more than `Limits::$facetCountDocs` (50,000) matching documents | Its counts cover the newest matches (highest IDs) |

```php
if ($result->totalHitsCapped) {
    // show "{$result->totalHits}+ results"
}
```

Each cap that was hit also adds a line to `$result->warnings`, meant for logs; its wording is not a
stable API. A browse (empty query) is always exhaustive; only its filtered facet counts can be
approximate.

## Pagination

`offset` and `limit` (default 20) select the page. `totalHits` counts the matches up to
`Limits::$totalHits` (1,000), and hits ranked past it cannot be reached: `offset` and `limit` are
clamped to it, so a page past the cap is empty. Raise `totalHits` to page deeper; broad queries
then cost more, since they count further.

```php
$page1 = $index->search('city car', new SearchOptions(limit: 20, offset: 0));
$page2 = $index->search('city car', new SearchOptions(limit: 20, offset: 20));

$totalPages = (int) ceil($page1->totalHits / 20);
```

## Browse (empty query)

An empty or whitespace-only query returns every document that passes the filters, newest first
(highest ID) or by `sort`. Filters, facets, sorting, `distinct` and formatting work as with a query:

```php
$index->search('');                                                // all documents, newest first
$index->search('', new SearchOptions(sort: [Sort::asc('price')]));
$index->search('', new SearchOptions(filter: ['category' => 'shoes'], facets: ['brand']));
```

A browse is answered over the whole index, so its total, page and order are exact at any size. A
sorted browse walks the sort field's index and stops once the page is full, so its cost follows the
page, not the index: on a 44k-product catalog a sorted or filtered page takes well under 5 ms.
`searchBoolean('')` is the same browse.

## Search as you type

With `asYouType` (the default), the last word is matched as a prefix: `fast se` also finds
"sedan". Turn it off for queries that are submitted whole:

```php
$index->search('sedan', new SearchOptions(asYouType: false));
```

A prefix expands to at most `Config::$fuzzyMaxExpansions` (50) indexed words, shortest first.

## Typo tolerance

Every query word long enough for a typo also matches the indexed words within its typo budget;
documents that match with fewer typos rank first.

| Word length | Typos allowed |
|---|---|
| up to 4 characters | 0 (exact, or prefix for the last word) |
| 5–8 characters | 1 |
| 9 or more | 2 |

A typo is one inserted, deleted or replaced character, or two neighbouring characters swapped
(`casaul` finds "casual"). The first three characters must be right (`Config::$fuzzyPrefixLength`).

A word also matches its typos when it is in the index itself: "bluetoth" finds the documents that
really contain "bluetoth" and those with "bluetooth", the exact ones first, whatever `sort` says.

`Config::$typoTolerance` changes the word sizes, turns typos off for numbers or for given words, or
turns typo tolerance off:

```php
use Fuzor\Config;
use Fuzor\TypoTolerance;

$index = new Index('/path/to/products.db', config: new Config(typoTolerance: new TypoTolerance(
    minWordSizeForOneTypo:  4,
    minWordSizeForTwoTypos: 8,
    disableOnNumbers:       true,      // "fz04218" no longer finds "fz04217"
    disableOnWords:         ['ikea'],  // brand names that must be spelled right
)));
```

| `TypoTolerance` | Default | Effect |
|---|---|---|
| `enabled` | `true` | `false`: words match exactly, or by prefix |
| `minWordSizeForOneTypo` | `5` | Shortest query word (in characters) that may have one typo |
| `minWordSizeForTwoTypos` | `9` | Shortest query word that may have two typos |
| `disableOnNumbers` | `false` | Words containing a digit (SKUs, model numbers, years) match exactly |
| `disableOnWords` | `[]` | Query words that always match exactly; case-insensitive |

**Compared with Meilisearch:** the same settings and defaults, and the same rule that fewer typos
rank first. Fuzor requires the first three characters to match instead of counting a typo on the
first character as two, counts a swap of neighbouring characters as one typo, and has no
`disableOnAttributes`.

## Phrases

Double quotes require the words to appear together, in order, within one field:

```php
$index->search('"quick brown fox"');
$index->search('"quick brown" sedan');      // a phrase and a word
$index->search('"quick brown" "fast car"'); // two phrases
```

Phrase words count for relevance like other words, and match exactly: no typos, no prefix.
`asYouType` applies only to a last word typed after the phrase (`"blue jeans" sh`). A phrase is
always required: if one of its words is in no document, `search()` finds nothing.

Quotes pair from the left. A quote left open makes a phrase of the rest of the query: `blue "denim
jacket` is `blue "denim jacket"`.

## Matching strategy

For a query of several words, `matchingStrategy` decides which documents are returned, and ranks
them in "words" buckets: a document matching more of the words always comes before one matching
fewer, before `sort` and relevance.

```php
use Fuzor\MatchingStrategy;

$index->search('big fat cat');  // MatchingStrategy::Last, the default
$index->search('big fat cat', new SearchOptions(matchingStrategy: MatchingStrategy::All));
$index->search('big fat cat', new SearchOptions(matchingStrategy: MatchingStrategy::Frequency));
```

| Strategy | Returns | Ranked first |
|---|---|---|
| `Last` | Documents containing the first word | All words, then "big fat", then "big" |
| `All` | Only documents containing every word | (one bucket) |
| `Frequency` | Documents containing the rarest word | Words kept rarest first; the most common is dropped first |

With `Last`, a document with "big" and "cat" but not "fat" is in the "big" bucket: only leading
words count. A word that is in no document still counts as a word, so `big zzz cat` returns the
"big" bucket with `Last` and nothing with `All`. With `All`, every document holding all words is
found and `exhaustive` is `true` whenever the rarest word is in at most `candidatesPerWord`
documents, however common the others are.

`searchBoolean()` ignores `matchingStrategy`; `facetSearch()` with a `q` requires every word.

**Compared with Meilisearch:** the same three strategies and the same default. Fuzor returns all
buckets, in order, and counts them all in `totalHits`.

## Excluding words and phrases

A `-` before a word or a quoted phrase leaves out the documents that contain it:

```php
$index->search('shirt -formal');        // shirts, except those mentioning "formal"
$index->search('dress -"long sleeve"');  // only the two words together exclude
$index->search('-sale');                 // every document without "sale"
```

- The `-` counts at the start of the query or after a space, so `t-shirt` is an ordinary word.
- Excluded words match exactly (after stemming, when the index has a stemmer): no typos, prefix or
  synonyms, also for the last word, so `shirt -wor` does not hide "word".
- A word that no document contains, or a stop word, excludes nothing.
- A query of only exclusions is a browse of every other document, with exact totals and facets.
- Exclusions also apply to facet counts and to `facetSearch()`'s `q`. They are not highlighted.

`searchBoolean()` has its own `-` / `~` operator (below).

## Synonyms

Synonyms are stored in the index and applied at query time, so changing them needs no reindex.

```php
// Every word of a group finds the others
$index->setSynonyms(equivalences: [
    ['car', 'automobile', 'vehicle'],
    ['couch', 'sofa', 'settee'],
]);

// One way: "phone" also finds "smartphone" and "mobile", not the reverse
$index->setSynonyms(oneWay: [
    'phone' => ['smartphone', 'mobile'],
]);
```

`setSynonyms()` replaces the whole configuration each time; pass both arguments in one call to
have both kinds. Words are lowercased (and stemmed, with a stemmer) before they are stored. Only
single words are supported: `setSynonyms()` returns the entries it skipped, so you can report them.

```php
$skipped = $index->setSynonyms(oneWay: ['mobile phone' => ['smartphone']]);  // ['mobile phone']

$index->getSynonyms();    // normalized source => targets
$index->clearSynonyms();
```

Synonyms do not apply inside quoted phrases or to excluded words.

## Relevance settings

Within a words bucket and typo tier, documents without `sort` are ordered by BM25 with a
proximity boost. `Config` sets the weights:

```php
$index = new Index('/path/to/articles.db', config: new Config(
    fieldBoosts:    ['title' => 5.0, 'body' => 1.0],  // a title match weighs five times as much
    proximityBoost: 1.0,
));
```

| `Config` | Default | Effect |
|---|---|---|
| `fieldBoosts` | `[]` | Weight per field: a word's frequency in a document counts `Σ boost × hits` over its fields; fields not listed weigh `1.0`. Empty uses plain BM25, at no cost |
| `k1` | `1.2` | Term frequency saturation: lower values give repeated words less advantage |
| `b` | `0.75` | Length normalisation: `0` ignores document length, `1` fully penalises long documents |
| `proximityBoost` | `1.0` | Reward for query words close together; `0` turns proximity off. Words in different fields count as 8 positions apart per word in between |

## Boolean search

`searchBoolean()` returns the documents that satisfy a boolean expression, without relevance
scores; pass `sort` for a meaningful order.

```php
$index->searchBoolean('sedan or coupe');
$index->searchBoolean('suv -electric');
$index->searchBoolean('(sedan or coupe) -electric');
$index->searchBoolean('sedan or coupe', new SearchOptions(sort: [Sort::asc('price')]));
```

| Syntax | Meaning |
|---|---|
| `a b`, `a & b` | both |
| `a or b`, `a \| b` | either |
| `-a`, `~a` | not `a` |
| `-(a b)`, `~(…)` | not the group |
| `"a b"` | the phrase |
| `-"a b"`, `~"…"` | not the phrase |
| `(…)` | grouping |

NOT binds tightest, then AND, then OR; use parentheses to group an OR. Spacing never changes the
meaning (`a|b` is `a | b`), and half-typed input is forgiven: a dangling operator (`shirt |`), an
unclosed parenthesis or a lone symbol is dropped instead of emptying the result. A hyphen inside a
word is part of it (`e-mail`).

- NOT removes from what it is combined with by AND, in either order: `shirt -jeans` equals
  `-jeans shirt`. A query of only negations matches nothing, and inside an OR a negation adds
  nothing (`shirt or -jeans` is `shirt`).
- A quoted phrase applies to the whole result, also to the other side of an OR. Inside quotes,
  operators are words: `"black or white"` is a phrase.
- All `SearchOptions` apply.

Each word contributes its first `candidatesPerWord` documents to the set operations, so when a
word matches more the page comes from those (`exhaustive: false`). `totalHits` is still counted
over every match; facet counts come from the candidates.

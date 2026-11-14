# Upgrading from 1.x to 2.0

## Recreate every index from your source data

2.0 does not open index files written by 1.x (schema revisions 1 to 3). Opening one, also
read-only, throws a `QueryException`. An index is not a permanent store:
recreate it and insert your documents again.

```php
$index = new Index($path, schema: new SchemaConfig(/* ... */), force: true);
$index->insert($documentsFromYourSource);
```

`force: true` replaces the old file safely while other processes still have it open. Files
written by a newer Fuzor are rejected the same way.

`Index::rebuild($path, $callback, schema: ...)` also replaces a 1.x file: with both a callback and
a schema it does not need to open the old index, and publishes the new one in its place (the old
file's synonyms are not carried over; set them in the callback). Without a callback, or without a
schema, it throws like opening the file does.

The sections below list every change that needs a change in calling code.

## `maxDocs` and `maxFacetCountDocs` moved to `Config::$limits`

The settings that decide how much of a large result set a search reads are now one value object,
`Fuzor\Limits`, passed as `Config::$limits`. The old `Config` properties are gone:

| 1.x | 2.0 |
|---|---|
| `new Config(maxDocs: 200)` | `new Config(limits: new Limits(candidatesPerWord: 200))` |
| `new Config(maxFacetCountDocs: 10_000)` | `new Config(limits: new Limits(facetCountDocs: 10_000))` |
| `$config->maxDocs` / `$config->maxFacetCountDocs` | `$config->limits->candidatesPerWord` / `$config->limits->facetCountDocs` |

`Limits` also holds the new `totalHits` (below). `Limits::fast()` is the default (500 candidates
per word, facet counts over 50,000 documents, 1,000 reachable hits); `Limits::exact()` lifts the
first two caps, so rankings, totals and facet counts cover every matching document:

```php
use Fuzor\Limits;

new Index($path, config: new Config(limits: Limits::exact()));
```

A value below 1 throws `\InvalidArgumentException`. Warnings name the new settings
(`Limits::$candidatesPerWord`, `Limits::$facetCountDocs`, `Limits::$totalHits`).

## Facet values are listed alphabetically

`$facetDistribution` and `facetSearch()` list values in alphabetical order by default (numbers
first, by value, then strings ignoring case), as in Meilisearch. 1.x listed the values with the
most documents first. Ask for that order to keep it, also because `Config::$maxValuesPerFacet`
(100) cuts the list after ordering:

```php
new SearchOptions(facets: ['brand'], sortFacetValuesBy: ['*' => FacetOrder::Count]);
new FacetSearchQuery('brand', sortFacetValuesBy: FacetOrder::Count);
```

## `Index`, `SearchResult` and the exceptions are `final`

`Index`, `SearchResult`, `IOException` and `QueryException` can no longer be extended. Wrap an
`Index` in your own class instead of subclassing it, and catch `FuzorException` (still open) or the
specific exceptions.

## Result getters are removed; read the properties

`SearchResult` and `FacetSearchResult` expose their values as readonly properties only. The getters
that returned the same values are gone:

| 1.x | 2.0 |
|---|---|
| `getHits()`, `getHitsCount()`, `getTotalHits()` | `->hits`, `->hitsCount`, `->totalHits` |
| `getQuery()`, `getLimit()`, `getOffset()` | `->query`, `->limit`, `->offset` |
| `getFacetDistribution()`, `getFacetStats()` | `->facetDistribution`, `->facetStats` |
| `getWarnings()`, `isExhaustive()`, `getApproximateFacets()` | `->warnings`, `->exhaustive`, `->approximateFacets` |
| `FacetSearchResult::getFacetHits()`, `getFacetQuery()`, `getWarnings()`, `isExhaustive()` | `->facetHits`, `->facetQuery`, `->warnings`, `->exhaustive` |

`getIds()`, `getHit()`, `count()`, `toArray()` and `toJSON()` stay.

## `SearchOptions::$sort` holds `Sort` objects; malformed options throw when built

Sort specs can be written as `Sort::asc('price')` / `Sort::desc('price')`; strings such as
`'price:asc'` are still accepted. Either way `SearchOptions` stores them as `Sort` objects, so code
that reads `$options->sort` gets `$spec->field` and `$spec->ascending` instead of strings
(`(string) $spec` gives the old `'price:asc'` form).

A malformed sort string, a malformed filter (a list instead of a map of fields, or a value no
document can hold: a bool, `null`, NaN, infinity) and a field in both `facets` and
`facetStatsOnly` throw the same `\InvalidArgumentException` as before, but from the `SearchOptions`
or `FacetSearchQuery` constructor instead of from `search()`. Code that built the options outside
its `try` block has to move the construction inside it. A field outside the schema still throws a
`QueryException` from the search.

## `QueryToken::$matchType` is a `MatchType` enum

`inspectQuery()` reports how each token matched as `Fuzor\MatchType` (`Exact`, `Prefix`, `Fuzzy`,
`None`) instead of a string. Compare with the enum, or read `->value` for the old strings
(`'exact'`, `'prefix'`, `'fuzzy'`, `'none'`).

## `FacetSearchQuery::$query` is `$q`

The facet search's full-text restriction takes Meilisearch's name: `new FacetSearchQuery('brand',
q: 'shirt')` instead of `query: 'shirt'`. Same meaning.

## A word also matches its typos when it exists

In 1.x a query word only fell back to typo matching when the index had no exact (or prefix) match
for it. Now every word long enough for a typo (`TypoTolerance`, 5+ characters by default) also
matches the indexed words within its typo budget, as in Meilisearch: "bluetoth" finds "bluetooth"
even when some product spells it "bluetoth". Results can therefore grow, and within a words
bucket documents that match with fewer typos rank first, before `sort` fields. To keep words
exact, list them in `TypoTolerance::$disableOnWords`, set `disableOnNumbers` for codes, or raise
`minWordSizeForOneTypo`.

## `SchemaConfig::$language` is removed; stemming is your own class

In 1.x, `SchemaConfig::$language` switched on a Snowball stemmer (31 languages), n-grams for
Chinese, Japanese, Korean and Thai, and a bundled stop word list. In 2.0 each of these works on
its own:

- **N-grams need no setting.** Chinese, Japanese, Korean and Thai words are split into n-grams
  by their script, in every index. Remove `language: 'zh'` (or `ja`, `ko`, `th`).
- **Stemming is opt-in, and Fuzor ships no stemmers.** Without a stemmer, words match as written;
  prefixes, typo tolerance and synonyms cover many word forms ("shoe" finds "shoes"). On a shop
  catalogue that is often the better choice: with a full stemmer, a word being typed ("hikin")
  or misspelled ("hikng") is compared with the stems in the index ("hike") and can miss. To keep
  stemming, implement `Fuzor\Stemmer` (`id()`, `stem()`), for example around a stemming library,
  and name the class in the schema; see "Writing a stemmer" in `docs/language.md`:

  ```php
  new SchemaConfig(stemmer: \App\Search\MyStemmer::class);
  ```

- **Stop words are a list you pass.** 1.x left out a bundled list (1,298 English words,
  including product words such as `c`, `men`, `tv`, `case` and every two-letter code). 2.0 ships
  no lists: list the words in `SchemaConfig::$stopWords`, which is empty by default.
  `QueryInspection::$stopwordsActive` is true when the index has stop words.
- `Index::$language` is replaced by `Index::$stemmer` (the class name, or `null`), and the
  `Language` class is removed (`all()`, `supports()`, `hasStemmer()`, `hasStopwords()`).
- `Index::highlighter()` and `Index::snippeter()` pass the index's stemmer; code that constructed
  `Highlighter` or `Snippeter` with `language:` passes nothing (or `stemmer:`) instead.

## `facetFields` is split into `filterableAttributes` and `sortableAttributes`; `searchableFields` is `searchableAttributes`

The schema's field lists take Meilisearch's names. `SchemaConfig::$facetFields` and
`$index->facetFields` are gone. Declare fields used in `filter`, `facets`, `distinct`, and
`facetSearch()` in `filterableAttributes`, and fields used in `sort` in `sortableAttributes`. A
field may be in both. Neither kind is tokenised for full-text search unless it is also in
`searchableAttributes`, which is the former `searchableFields` (same meaning; `$index->searchableFields`
is `$index->searchableAttributes`).

```php
// 1.x
new SchemaConfig(facetFields: ['brand', 'color', 'price'], searchableFields: ['title', 'body']);

// 2.0
new SchemaConfig(
    filterableAttributes: ['brand', 'color', 'price'],
    sortableAttributes:   ['price'],
    searchableAttributes: ['title', 'body'],
);
```

## Undeclared fields throw instead of warning

A `sort` field that is not in `sortableAttributes`, or a `filter`, `facets`, `distinct`, or
`FacetSearchQuery` `facetName`/`filter` field that is not in `filterableAttributes`, now throws a
`QueryException`. In 1.x it was ignored (or matched nothing) and reported in `$warnings`. When
these options come from user input, check them against `$index->sortableAttributes` /
`$index->filterableAttributes` or catch `QueryException`. `$warnings` now only reports caps.

## `Config::$filterMaxDocs` is removed

It has had no effect since 1.6.0. Remove it from `new Config(...)` calls.

## String sort ignores case

`sort` on a string field now orders uppercase letters as if they were lowercase: `"apple"`
before `"Zebra"` (1.x put `"Zebra"` first). Values that differ only in case tie. Accents are
still not folded. Results sorted on mixed-case string fields can come back in a different order.

## A leading `-` in `search()` excludes

In 1.x `search('shirt -formal')` searched for both words. In 2.0 a word or quoted phrase with a
leading `-` (at the start of the query or after a space) removes the documents that contain it,
and a query of only such words returns all other documents. Hyphens inside words (`t-shirt`)
are unchanged. If your users type a leading `-` meaning something else, strip it before calling
`search()`.

## Words in quotes are never a prefix

With `asYouType` on, 1.x matched the last word of a query as a prefix even inside a closed
quoted phrase: `"blue jeans"` also found "blue jeanswear". In 2.0 every word of a quoted
phrase matches exactly, and a query that ends in a quoted phrase has no prefix word at all
(also in highlighting and `inspectQuery()`). A word typed after the phrase (`"blue jeans" sh`)
is still a prefix. If you relied on the old behaviour, leave the last word outside the quotes.

A quote left open now makes a phrase of the rest of the query (`blue "denim jacket` is
`blue "denim jacket"`); 1.x ignored it. A query with an inch mark (`24" monitor`) therefore
searches `monitor` exactly. Remove such quotes before searching if they are not meant
as phrases.

## A phrase with a word no document holds finds nothing

In 1.x a quoted phrase whose word was not in the index was ignored, so `search('"blue jaens"')`
returned every document with "blue". In 2.0 such a phrase matches no document, and since a
phrase is never dropped from a query, `search()` and `facetSearch()` return nothing (phrase words
have no typo tolerance). `searchBoolean()` still leaves such a phrase out. If you want results
for a misspelled phrase, retry the query without the quotes when it comes back empty.

## `Config::$fuzzyMinWordLength` is replaced by `TypoTolerance`

```php
// 1.x
new Config(fuzzyMinWordLength: 4);

// 2.0
new Config(typoTolerance: new TypoTolerance(minWordSizeForOneTypo: 4));
```

The two-typo threshold (9) is now configurable as `minWordSizeForTwoTypos`. A swap of two
neighbouring characters now counts as one typo instead of two, so slightly more words match.

## Multi-word searches return documents matching the first word, ranked by matched words

1.x returned every document containing any query word, ranked by how many words it matched. 2.0
uses `SearchOptions::$matchingStrategy`, default `MatchingStrategy::Last`: documents must contain
the first word, and rank by how many words they match from the start of the query ("big fat cat",
then "big fat", then "big"). A document with only later words ("fat cat") is no longer returned.
Pass `MatchingStrategy::All` to require every word, or `Frequency` to drop the most common words
first.

`sort` now applies after that words ranking: a document matching more words still comes first,
and the sort fields order documents within each group. In 1.x the sort fields decided the whole
order.

## Some results are ranked differently

Five ranking fixes change the order of some results without any change on your side; tests
that assert an exact order may need updating:

- When words match more than `Limits::$candidatesPerWord` documents, the documents that contain them are
  now ranked by their real BM25 score while there are at most 4 × `candidatesPerWord` (2,000) of them (with a
  quoted phrase, only those that contain the phrase count), not only those with the most
  occurrences of each word: a short document that mentions the words once can now rank first. When a page reaches documents that contain only the first word (a
  one-word query, or a query whose other words match nothing), those are the first word's
  `candidatesPerWord` documents with the best BM25 score, not with the most occurrences. On a 44k-product
  catalog 97% of first pages equal the ranking without any cap (50% before). Of a larger set, the
  4 × `candidatesPerWord` in which the rarest word weighs most (its BM25 term frequency, so a short document
  counts more than a long one with the same hits) are ranked and the rest follow them. Raise
  `candidatesPerWord` for more, at the cost of speed.
- In a multi-word search where one word matches more than `Limits::$candidatesPerWord` documents,
  documents found through the other words are now scored for the common word too, so documents
  that contain every word rank as such.
- A prefix that expands to words counting more documents together than the index holds no longer
  ranks documents with more matching words last.
- A typo'd word is matched against its closest candidates first, so it can find a match that
  1.x missed when many words share its first letters.
- Each field's word positions start on their own, so a quoted phrase no longer matches across
  the end of one field and the start of the next. Proximity scoring measures the distance between
  query words inside one field; words that only meet across two fields count a fixed cross-field
  distance per gap. On a 44k-product catalog 99% of multi-word searches keep their top hit.

## Combining marks stay inside their word

A combining mark (an accent written as its own code point, the vowel signs and tone marks of
Devanagari and other Indic scripts, Arabic, Hebrew and Thai) is now part of the word it sits
in. 1.x ended the word at every mark: "हिन्दी" was indexed as the three consonants ह, न, द, and
"İstanbul" as `i` and `stanbul`. Words in these scripts now match as whole words, also by
prefix and with typos, and are highlighted whole. Nothing in your code changes; the index you
recreate for 2.0 holds the new terms. Text without combining marks is indexed as before.

Accents are still not folded: a word written with a separate accent mark and the same word
written with the precomposed letter are different terms.

## `inspectQuery()` reports negations separately

Like `search()`, `inspectQuery()` splits `-word` and `-"phrase"` off the query first: they are no
longer listed in `$tokens`, `$rawTokens`, or `$filteredTokens`, but in the new `$negations`.

## `totalHits` stops at `Limits::$totalHits` (1000) and is exact in `search()`

As in Meilisearch, a search reaches at most `Limits::$totalHits` documents (default 1,000):
`offset` and `limit` are clamped so no hit ranked past it is returned, and `$totalHits` stops at
it, with the new `$totalHitsCapped` set to `true`. This also holds for a browse (empty query),
whose total used to be the full count. Show "1000+" when the flag is set, or raise the cap:

```php
new Index($path, config: new Config(limits: new Limits(totalHits: 10_000)));
```

Below the cap, `search()` now counts `$totalHits` exactly also when a word matches more than
`Limits::$candidatesPerWord` documents (`exhaustive: false`); 1.x reported the number of candidates it had
scored, often a quarter of the real number or less. Facet counts of such a search are taken over
every match too (up to `Limits::$facetCountDocs`), not over the scored candidates; on the 44k
catalog they were off by 76% at the median. `searchBoolean()` and `distinct` totals are exact
in that case too.

## `SearchOptions::$limit` defaults to 20

The default page size is 20 hits instead of 100. Pass `limit: 100` to keep the old size.

## `Limits::$facetCountDocs` defaults to 50,000

Facet counts over a filter or query are taken over at most `facetCountDocs` matching
documents. The default is 50,000 instead of 10,000, so pages with 10,001 to 50,000 matches now
get exact counts (and no longer list their fields in `$approximateFacets`). Counting is cheaper
in 2.0 (one row per document for all facet fields), so such a page is still faster than a
capped one in 1.x. `facetSearch()` with `q` also considers up to 50,000 candidates. Pass
`limits: new Limits(facetCountDocs: 10_000)` to keep the old cap.

## `facetSearch()` matches any word, ignoring accents

`FacetSearchQuery::$facetQuery` used to match the start of the value only, ignoring ASCII case.
It now matches the start of any word (separated by spaces, `-`, `_`, or `/`) and ignores case and
Latin accents on both sides, so more values can match.

## Facet values with equal counts are ordered by value

In 1.x, values with the same count came in whatever order they were counted, which could differ
between a filtered and an unfiltered page. 2.0 lists them by value, so with more values than
`Config::$maxValuesPerFacet` a different value can be the last one kept.

## Deleted documents count in word statistics until purged

`delete()` no longer rewrites the word index right away (see "Deleting" in `docs/indexing.md`).
Results never include deleted documents, but BM25's per-word document counts do until the next
purge, so close results can be ordered slightly differently after deletes. Call `optimize()`
after a large deletion if you need exact statistics; it also runs by itself once 10% of the
index is deleted.

## `insert()`, `update()` and `upsert()` read a generator in chunks

A multi-document `insert()`, `update()` or `upsert()` no longer reads its whole input before writing (see
`Config::$insertChunkSize`). For a generator, or any `Traversable` that is not `Countable`, the
`progress` callback now receives `0` as `$total`; arrays and `Countable` inputs still get their
size. A generator is read while the write runs, so it should not depend on the index it feeds.
When an `update()` names IDs that do not exist, nothing is written as before, but the exception
lists the missing IDs of the first chunk that has any, not all of them.

## Pages past `candidatesPerWord` candidates are no longer empty

When the words of a `search()` match more than `Limits::$candidatesPerWord` documents (`exhaustive: false`),
pages past the ranked candidates used to be empty while `$totalHits` counted every match. They are
now filled with the other matches, after the ranked ones, so every page up to `$totalHits` is full.
With `sort` the candidates and the other matches are sorted together, so even the first page of a
sorted search can show different documents. Callers that stopped paging at the first empty page
get more pages; nothing else changes.

## A number as a filter value matches, other non-strings throw

A number in a filter (`['stars' => 5]`, `['stars' => [4.5, 5]]`, `new FacetExclude(0)`) matched
nothing in 1.x, even where documents held that number, because values are stored as text. It now
matches what its text matches: documents with `5`, `5.0` or `'5'`, the same as `['stars' => '5']`.
Check filters that pass numbers from a request or a database: they start filtering.

A value no document can hold — `true`, `false`, `null`, NaN, an infinite number, an object or a
nested list — now throws an `\InvalidArgumentException` naming the field, in `search()`,
`searchBoolean()`, `facetSearch()` and `deleteByFilter()`, instead of returning no documents (and
deleting none).

## Arrays and bools in searchable fields are indexed

A searchable field whose value is an array (`'tags' => ['red', 'fast']`, also nested arrays or
objects) is now indexed element by element; it was indexed as the single word "array", with an
"Array to string conversion" warning. A bool is indexed as `true` or `false` (was `1`, and nothing
for `false`). Rebuild indexes with such fields. Without `searchableAttributes` every array field
is now tokenised, which makes an index of documents with image URLs or attribute maps slower to
build and larger; list the fields whose words should be found in `searchableAttributes`, and the
others stay stored-only.

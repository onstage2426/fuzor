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

## A word also matches its typos when it exists

In 1.x a query word only fell back to typo matching when the index had no exact (or prefix) match
for it. Now every word long enough for a typo (`TypoTolerance`, 5+ characters by default) also
matches the indexed words within its typo budget, as in Meilisearch: "bluetoth" finds "bluetooth"
even when some product spells it "bluetoth". Results can therefore grow, and within a words
bucket documents that match with fewer typos rank first, before `sort` fields. To keep words
exact, list them in `TypoTolerance::$disableOnWords`, set `disableOnNumbers` for codes, or raise
`minWordSizeForOneTypo`.

## A language no longer filters stop words

In 1.x, `SchemaConfig::$language` also switched on a bundled stop word list (1,298 English
words, including product words such as `c`, `men`, `tv`, `case` and every two-letter code).
Those words were left out of the index and of queries. 2.0 ships no lists: every word is indexed
and searched unless you list it in the new `SchemaConfig::$stopWords`, which is empty by
default.

- To keep filtering words, pass them: `new SchemaConfig(language: 'en', stopWords: ['the', 'a', 'of'])`.
  Each entry must be a single word; matching ignores case.
- `Language::hasStopwords()` is removed. `QueryInspection::$stopwordsActive` is true when the
  index has stop words.
- Language tags that only had a stop word list (no stemmer, no n-grams) are no longer accepted:
  `af`, `bg`, `bn`, `br`, `cs`, `fa`, `gl`, `gu`, `ha`, `he`, `hr`, `ku`, `la`, `lv`, `mr`, `ms`, `sk`,
  `sl`, `so`, `st`, `sw`, `tl`, `uk`, `ur`, `vi`, `yo`, `zu`. Create those indexes with
  `language: null`; indexing and search are the same apart from the stop words.

## `facetFields` is split into `filterableFields` and `sortableFields`

`SchemaConfig::$facetFields` and `$index->facetFields` are gone. Declare fields used in `filter`,
`facets`, `distinct`, and `facetSearch()` in `filterableFields`, and fields used in `sort` in
`sortableFields`. A field may be in both. Neither kind is tokenised for full-text search unless
it is also in `searchableFields`.

```php
// 1.x
new SchemaConfig(facetFields: ['brand', 'color', 'price']);

// 2.0
new SchemaConfig(filterableFields: ['brand', 'color', 'price'], sortableFields: ['price']);
```

## Undeclared fields throw instead of warning

A `sort` field that is not in `sortableFields`, or a `filter`, `facets`, `distinct`, or
`FacetSearchQuery` `facetName`/`filter` field that is not in `filterableFields`, now throws a
`QueryException`. In 1.x it was ignored (or matched nothing) and reported in `$warnings`. When
these options come from user input, check them against `$index->sortableFields` /
`$index->filterableFields` or catch `QueryException`. `$warnings` now only reports caps.

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

Four ranking fixes change the order of some results without any change on your side; tests
that assert an exact order may need updating:

- In a multi-word search where one word matches more than `Config::$maxDocs` documents,
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

## `inspectQuery()` reports negations separately

Like `search()`, `inspectQuery()` splits `-word` and `-"phrase"` off the query first: they are no
longer listed in `$tokens`, `$rawTokens`, or `$filteredTokens`, but in the new `$negations`.

## `SearchOptions::$limit` defaults to 20

The default page size is 20 hits instead of 100. Pass `limit: 100` to keep the old size.

## `Config::$maxFacetCountDocs` defaults to 50,000

Facet counts over a filter or query are taken over at most `maxFacetCountDocs` matching
documents. The default is 50,000 instead of 10,000, so pages with 10,001 to 50,000 matches now
get exact counts (and no longer list their fields in `$approximateFacets`). Counting is cheaper
in 2.0 (one row per document for all facet fields), so such a page is still faster than a
capped one in 1.x. `facetSearch()` with a `query` also considers up to 50,000 candidates. Pass
`maxFacetCountDocs: 10000` to keep the old cap.

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

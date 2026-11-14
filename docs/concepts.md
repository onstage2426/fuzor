# Concepts

The ideas the other pages build on: what goes into an index, how a search finds and orders
documents, and when a result is exact.

## Documents and fields

A document is a PHP array with a unique integer `id`. Its other fields are strings, numbers, bools,
or arrays of them. Each field has one or more roles, set in the schema:

| Role | Declared with | Used by |
|---|---|---|
| Searchable | `searchableAttributes` (default: every field that is not filterable or sortable) | the query text |
| Filterable | `filterableAttributes` | `filter`, `facets`, `facetStatsOnly`, `distinct`, `facetSearch()` |
| Sortable | `sortableAttributes` | `sort` |
| Stored only | in no list, once `searchableAttributes` is set | returned with each hit |

A field can be both searchable and filterable (a brand that should also match the search box);
list it in both. Using a field in an option its role does not allow throws a `QueryException`
that names the field, so a typo in a filter never silently matches everything.

With the document store (the default), the whole document is stored as JSON and returned as the
hit, so a search page needs no second query to your database.

## Schema and config

Two objects configure an index:

- **`SchemaConfig`** decides what is written to the file: the field roles, `stripHtml`, the
  stemmer, stop words and the document store. It is stored in the file when the file is created,
  and every later open reads it back. Passing the same schema on later opens is fine, so one line
  can create and open the index; a different one throws a `QueryException` that names the
  difference. To change it, `Index::rebuild()` the index with the new schema.
- **`Config`** decides how this connection searches: the limits, BM25 and typo settings, field
  boosts, SQLite cache sizes. It is passed on every open and may differ between processes.

Per-request choices (filters, facets, sorting, paging, formatting) go in `SearchOptions`.

## Words

Text is split into words at spaces and punctuation, and lowercased; letters, digits and the
combining marks of scripts such as Hindi stay together, so `t-shirt` gives `t` and `shirt`, and
`usb-c` gives `usb` and `c`. Accents are kept: `café` and `cafe` are different words. Chinese,
Japanese, Korean and Thai, written without spaces, are split into overlapping character n-grams
(see [Language](language.md)).

A query is split the same way. Its last word is matched as a prefix while it is typed (`asYouType`),
and every word long enough also matches indexed words within its typo budget: one typo from five
letters, two from nine. Synonyms add other words a query word matches. With a stemmer, both sides
are reduced to stems first.

## How a search ranks

For a query of several words, documents are ranked by these rules, each one only deciding between
documents the previous rules left tied:

1. **Words.** How many of the query's words a document contains, counted from the first word
   (`matchingStrategy`, default `Last`): documents with all words come first, then those missing
   the last word, and so on. A document that lacks the first word is not returned.
2. **Typos.** Fewer typos first: a document matching "bluetoth" as written comes before one that
   matches it as "bluetooth".
3. **Sort.** The `sort` fields, when given (price, date).
4. **Relevance.** BM25: a word counts for more when it is rare in the index and frequent in the
   document, and less in long documents. Documents with the words close together, in the same field,
   get a proximity boost. `Config::$fieldBoosts` makes some fields weigh more.

Then the document ID decides, so the order is always the same. `showRankingScoreDetails` shows the
values each hit was ranked by ([Debugging](debugging.md)).

An empty query skips the text part entirely: a **browse** returns every document that passes the
filters, newest first (highest ID) or by `sort`. A category page is a browse with a filter.

## Filters and facet counts

A filter keeps the documents whose values match: one value, any of a list, a numeric range, or
an exclusion, combined with AND across fields, or as any tree of AND / OR / NOT with `Filter`.
Values match exactly, case included.

Facet counts (`facetDistribution`) give, per value of a field, the number of matching documents.
A field that is filtered and counted in the same request is counted **without its own filter**,
so a sidebar keeps showing the other brands while one is selected, with the number each would
add. Every other field is counted over the filtered result. See
[Filtering and facets](filtering-and-facets.md).

## Exact results and limits

On a large index, a common word can match a large part of the catalog, and ranking every one of
those documents for every keystroke would be slow. `Config::$limits` bounds that work:

- **`candidatesPerWord`** (500): each query word is first read through its best documents. When
  every document that could be on the page is among them, or few enough to read in full (up to
  2,000), the page is the exact ranking. Otherwise the page comes from the best candidates and
  `exhaustive` is `false`.
- **`facetCountDocs`** (50,000): facet counts cover at most this many matching documents. Above
  it they count the newest matches and the field is listed in `approximateFacets`.
- **`totalHits`** (1,000): the total is counted exactly up to this number and pages stop there;
  above it `totalHitsCapped` is `true` ("1,000+ results").

Totals are counted over every match whatever the candidates were, so they are exact up to
`totalHits`, and each page up to it is full. A result never hides a cut: `exhaustive`,
`approximateFacets`, `totalHitsCapped` and `warnings` say what was approximate. `Limits::exact()`
lifts the first two caps, at a cost that grows with the number of matches. Browsing is always
exact. [Performance](performance.md#limits) has the details.

## The index file

An index is one SQLite file in WAL mode, plus its `-wal` and `-shm` files while it is open. Any
number of PHP processes can search it at once while one writes; a write is visible to the next
search in every process. `rebuild()`, `snapshotTo()` and `install()` write a new file next to it
and switch to it atomically, so readers never see a half-written index. The path is then a
symlink to the current version: back it up with a tool that follows links, and delete it with
`Index::remove()`. See [Operations](operations.md).

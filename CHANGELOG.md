# Changelog

## 2.0.0 - unreleased

2.0 makes results on large indexes exact, or reports them as approximate where a cap applies. It also adds filter trees, exclusions and tools to maintain an index. Many options now use Meilisearch's names and behaviour (`filterableAttributes`, `matchingStrategy`, `typoTolerance`, `totalHits`, facet value order), so its documentation explains them as well. See `UPGRADE-2.0.md` for what calling code has to change.

Breaking:

**Requirements and index files**

- `new Index()` throws a `FuzorException` naming both versions when PHP's SQLite library is older than `Index::MIN_SQLITE_VERSION` (3.46.0), and refuses a 32-bit PHP. `composer.json` requires `ext-pdo_sqlite` and `ext-mbstring`.
- Index files written by 1.x (schema revisions 1 to 3) are not opened: `new Index()` throws a `QueryException` saying how to recreate the index. A file written by a newer version is rejected the same way. `Index::rebuild()` with a callback and a schema replaces such a file without opening it. 2.0 writes schema revision 4.

**Schema and configuration**

- `SchemaConfig::$facetFields` is replaced by `filterableAttributes` (fields for `filter`, `facets`, `distinct` and `facetSearch()`) and `sortableAttributes` (fields for `sort`); `searchableFields` is renamed `searchableAttributes`. The same goes for the `Index` properties.
- A field outside the matching list in `sort`, `filter`, `facets`, `distinct` or a `FacetSearchQuery` throws a `QueryException` naming the field and the declared fields. It used to be ignored with a warning; `$warnings` now only reports caps.
- `Config::$maxDocs` and `Config::$maxFacetCountDocs` moved into a `Limits` value object, `Config::$limits`, as `candidatesPerWord` and `facetCountDocs`, next to the new `totalHits`. `Limits::fast()` is the default; `Limits::exact()` reads every word whole and counts facets over every match. A value below 1 throws an `\InvalidArgumentException`.
- `Limits::$facetCountDocs` defaults to 50,000 (was 10,000).
- `Config::$fuzzyMinWordLength` is replaced by `Config::$typoTolerance` (`TypoTolerance`): `enabled`, `minWordSizeForOneTypo` (5), `minWordSizeForTwoTypos` (9, was fixed), `disableOnNumbers` and `disableOnWords`.
- `Config::$filterMaxDocs` is removed; it had no effect since 1.6.0.
- `SchemaConfig::$language`, the `Index::$language` property and the `Language` class are removed. Chinese, Japanese, Korean and Thai words are split into n-grams by their script, in every index and also in text that mixes scripts (an index without a language indexed such text as whole runs). Stemming is opt-in: `SchemaConfig::$stemmer` takes a class implementing the new `Stemmer` interface, and Fuzor no longer ships the Snowball stemmers. Without a stemmer, every word is matched as written, as in Meilisearch.
- The bundled stop word lists are removed, so every word is indexed and searched. Stop words are the new `SchemaConfig::$stopWords` (single words, empty by default). The English list held product words such as `c`, `men`, `tv` and `case`: `"usb c cable"` was searched as `"usb cable"`. `Language::hasStopwords()` is gone with `Language`.
- `SearchOptions::$limit` defaults to 20 (was 100).

**Which documents a search returns**

- `SearchOptions::$matchingStrategy` (`MatchingStrategy::Last` by default, `All`, `Frequency`): a multi-word `search()` returns the documents that contain the first word (`Last`), every word (`All`) or the rarest word (`Frequency`), ranked by how many words they match in that order. 1.x returned documents with any word. `sort` orders documents within each of those groups instead of deciding the whole order.
- A query word that exists in the index also matches its typos. Within a words group, documents that match with fewer typos rank first, before `sort`. Two swapped neighbouring characters count as one typo (`casaul` finds `casual`).
- A leading `-` excludes: `shirt -formal` leaves out documents with "formal", `-"long sleeve"` a phrase, and a query of only exclusions returns every other document. 1.x searched for the word. In `inspectQuery()` these are listed in the new `QueryInspection::$negations` instead of among the tokens.
- Words in a quoted phrase always match exactly; with `asYouType` the last word was a prefix even inside closed quotes. A phrase with a word that no document holds makes `search()` and `facetSearch()` return nothing; it used to be ignored (`searchBoolean()` still leaves it out). A quote left open makes a phrase of the rest of the query.
- Each field's word positions start on their own, so a phrase no longer matches across the end of one field and the start of the next. Proximity counts a fixed distance between words in different fields.
- A number as a filter value (`['stars' => 5]`) matches documents with `5`, `5.0` or `'5'`; it matched nothing. A value no document can hold (`true`, `false`, `null`, NaN, infinity, an object, a nested list) throws an `\InvalidArgumentException`.
- A searchable field holding an array is indexed element by element, nested arrays and objects included; it was indexed as the word "array", with a PHP warning. A bool is indexed as `true` or `false`.
- Combining marks (a separately written accent, the vowel signs of Indic scripts, Arabic, Hebrew and Thai marks) stay inside their word: "हिन्दी" was indexed as three single consonants and "İstanbul" as `i` and `stanbul`. Accents are still not folded.

**Totals, pages and facet counts**

- `totalHits` stops at `Limits::$totalHits` (1,000, Meilisearch's `maxTotalHits`), with the new `SearchResult::$totalHitsCapped` set; `offset` and `limit` are clamped so no hit past it is returned. This also applies to a browse, whose total used to be the full count.
- Below that cap, `$totalHits` is exact when words match more than `candidatesPerWord` documents, in `search()`, `searchBoolean()` and with `distinct`. 1.x reported the number of scored candidates.
- Facet counts of such a search are taken over every match (up to `facetCountDocs`), not over the candidates. When there are more matches than `facetCountDocs`, the newest are counted (the lowest values when a browse is read from a numeric range filter); this used to depend on SQLite's plan.
- Pages past the ranked candidates are filled with the other matches, newest first; they were empty. With `sort` these matches are sorted together with the candidates, so the first page can change too.
- The page of a search whose words match many documents is ranked by BM25 over the documents that match its words, not only over each word's documents with the most occurrences: a short document mentioning the words once is no longer missed, and a document found through one word is scored for every word it contains. Paging shows each document once. A larger group than the ranking reads is reported with `exhaustive: false`.
- String sort ignores case (`"apple"` before `"Zebra"`). Accents are not folded.
- Facet values are listed alphabetically by default (`FacetOrder::Alpha`); 1.x listed them by count. Values with equal counts are always in value order.
- `facetSearch()` matches `facetQuery` at the start of any word of a value and ignores case and Latin accents; it matched the start of the whole value.

**API**

- `FacetSearchQuery::$query` is renamed `$q`.
- `SearchResult` and `FacetSearchResult` have no getters for their properties any more (`getTotalHits()`, `isExhaustive()`, …): read the readonly properties. `getIds()`, `getHit()`, `toArray()` and `toJSON()` stay.
- `SearchOptions::$sort` is read back as `list<Sort>`. A malformed sort spec or filter, and a field in both `facets` and `facetStatsOnly`, throw when the `SearchOptions` or `FacetSearchQuery` is built instead of in the search; a filter value that is NaN or infinite also throws in `Filter::all()` / `any()` / `not()`.
- `new Index($path, schema: …)` on an existing index opens it when the schema is the one it was created with (field lists and stop words in any order); a different schema throws a `QueryException` naming each difference. Before, any schema threw.
- `QueryToken::$matchType` is a `MatchType` enum (`Exact`, `Prefix`, `Fuzzy`, `None`, backed by the former strings).
- `Index`, `SearchResult`, `IOException`, `QueryException` and `BooleanParser` are `final`. `Index` implements `\Countable`.
- The tokenizer methods other than `Tokenizer::sortKey()` and the constructors that only `Index` calls are marked `@internal`. `Highlighter` and `Snippeter` take the index's stemmer instead of a language tag.
- The `progress` callback of `insert()`, `update()` and `upsert()` receives `0` as `$total` for a generator or other non-countable input, which is now read one chunk at a time. An `update()` with missing IDs names those of the first chunk that has any.

Added:

- The `Stemmer` interface (`id()`, `stem()`), for `SchemaConfig::$stemmer`; `docs/language.md` shows an example. The index stores the stemmer's class and `id()` and creates it again when opened: a class that cannot be loaded throws a `QueryException` naming it; a changed `id()` (a stemmer release that stems some words differently) keeps the index working, sets `$index->stemmerChanged` and adds a warning to every search result until `rebuild()`.
- On an index with a stemmer, the word being typed and typos are matched against the words of the documents as written, then searched by their stems: with an English stemmer that turns "hiking" into "hike", `hikin` and `hikng` find it. The words are kept in a small table next to the index (20,594 words for the 44k catalog, 0.5 MB).
- `Sort::asc('price')` / `Sort::desc('price')` for `SearchOptions::$sort`, next to the `'price:asc'` strings.
- `Filter::all()`, `Filter::any()` and `Filter::not()` combine filter conditions with AND, OR and NOT, nested freely, wherever a filter map is accepted. A plain map keeps its meaning. A key with one condition of its own at the top level is counted without it, as with a map; other keys are counted over the filtered result.
- `SearchOptions::$showRankingScoreDetails` adds `_rankingScoreDetails` to each hit: words, typos, sort values and the BM25 score with its proximity factor.
- `SearchOptions::$facetStatsOnly` returns `facetStats` for numeric fields without counting their values, for the ends of a price slider.
- `SearchOptions::$sortFacetValuesBy` (per field, `'*'` for the rest) and `FacetSearchQuery::$sortFacetValuesBy` order facet values before the list is cut.
- `$index->stopWords`. A stop word keeps its place: `"bank of england"` matches "bank", any word, "england", and proximity counts it as a word between.
- `searchBoolean()`: `-"phrase"` and `~"phrase"` leave out the documents with the phrase (they required it), `-(group)` negates like `~(group)`, operators inside quotes are words of the phrase, and stop words are left out of the expression.
- `optimize(): int` purges the word index rows of deleted documents and returns how many.
- `deleteByFilter(array|Filter $filter): int` deletes every document that matches a filter.
- `Index::remove($path)` deletes an index with its versions, their `-wal`/`-shm` files and dead temp builds.
- `Index::install($file, $path, check: false)` verifies an index file made elsewhere and publishes it like `rebuild()`.
- `Config::$insertChunkSize` (2,000): multi-document writes and `rebuild()` work in chunks inside one transaction, so memory follows the chunk size instead of the input size.

Changed:

- `delete()` removes a document's own rows at once and purges its word index rows later: in `optimize()`, `rebuild()`, `clear()`, or by itself once deleted documents reach 10% of the index. Until then BM25's per-word document counts include the deleted documents.
- `update()` and `upsert()` keep the word index rows of a document whose searchable fields are unchanged (with the document store), and otherwise write only the rows that change.
- Index files are larger: maintained facet counts, packed facet values per document and string sort keys are stored. An index without stop words also holds more word rows than 1.x with its list.
- Statements that keep the first rows by score or hit count name the order of tied rows, so results do not depend on the SQLite version.

Fixed:

- `_formatted` did not mark words that matched through a typo or a synonym, and a crop did not centre on them.
- A crop could cut away the word it was made for.
- A typo'd word could miss its match when more than `fuzzyMaxExpansions` terms shared its first letters.
- A prefix whose expansions together counted more documents than the index got a negative IDF, which ranked documents with more matching words last.
- `searchBoolean()` with a negation returned documents containing the negated word when that word was in more documents than were fetched per word. With `asYouType: false` a negated last word was expanded as a prefix.
- After a write that failed and was rolled back, later writes on the same `Index` reused cached IDs the rollback had removed; those documents could not be found.
- `has()`, a filtered browse, and `distinct` or sorting over 1,000 or more documents left a read open on the connection, so another connection's `checkpoint()` or `close()` waited for the busy timeout.
- With `open_basedir` set, a read-only `Index` and `Index::remove()` failed on every file.
- `Index::exists()` could create an empty database when the file was removed while it checked.

Performance:

Measured on 2026-11-14 against 1.7.1 with the same requests on a 44k-product catalog with HTML descriptions (i5-9600K, PHP 8.5, SQLite 3.46.1, default settings; reads are the best median of four alternating runs):

| Operation | 1.7.1 | 2.0.0 |
|---|---|---|
| Shop page: 5 facets, one value excluded | 45 ms | 3.5 ms |
| Facets of an unfiltered page / price stats only | 12 / 7.3 ms | 0.5 / 0.9 ms |
| Listing: two values of one field, 6 facets, sorted | 42 ms | 4.1 ms |
| Price range sorted by price | 14 ms | 0.4 ms |
| Page at offset 20,000 sorted by price | 8.5 ms | 0.01 ms |
| `distinct` browse / with a filter and sort | 52 / 71 ms | 18 / 35 ms |
| `update()` of 200 documents, price only / title changed | 320 / 350 ms | 33 / 136 ms |
| `delete()` of 50 documents | 490 ms | 11 ms |
| `insert()` of all documents | 18.5 s, 2.0 GB | 24.4 s, 344 MB |
| `rebuild()` | 18.1 s, 2.0 GB | 22.9 s, 208 MB |
| `snapshotTo()` (index file 310 / 363 MB) | 1.5–1.7 s | 1.7–2.4 s |
| Two-word search | 1.9 ms | 15 ms |
| Three-word search | 1.8 ms | 37 ms |
| Two-word search with a typo | 2.4 ms | 19 ms |
| Search with a filter, sort and 6 facets | 7.0 ms | 38 ms |

Browsing, facet counts, sorted listings, `update()` and `delete()` are faster: facet counts read maintained per-value counts or one packed row per document, sorted pages walk the sort field's index, filters are read once per request, and writes touch fewer rows. The `update()` and `delete()` times leave the WAL checkpoint to `close()`; counted with it, the updates take 590 / 660 ms in 1.7.1 and 136 / 333 ms in 2.0.0, the delete 500 and 68 ms.

Keyword searches whose words match more than `candidatesPerWord` documents are slower, because they do more: 1.7.1 ranked, counted and faceted only each word's candidates, so its pages, totals and facet counts were often wrong. With every cap lifted on both versions, so both do the same work, 2.0 is faster on most of them (two words 27 to 16 ms, the typo 47 to 21 ms, filter + sort + 6 facets 144 to 49 ms); a prefix of a common word, a swapped-letter typo and a `searchBoolean()` negation take longer, since every word also matches its typos and a negation is checked exactly.

`insert()` and `rebuild()` take longer mainly because a language no longer leaves out stop words, so more word rows are written; memory follows the chunk size (an `insert()` from a generator peaks at 172 MB). The index file is 17% larger (new facet and sort tables).

## 1.7.1 - 2026-10-07

Fixed:

- `rebuild()` could pair the new index file with the old file's write-ahead log. SQLite finds a database's `-wal` by file name, so when another process still had the old file open for writing, its uncheckpointed commits — and every commit it made after the swap — were read as pages of the new file: old documents showing up in the rebuilt index, or a corrupt file. `snapshotTo()` deleted the old sidecars after its rename, which left a short window for the same problem. Both now publish each new file under a name of its own, `{path}.v-{8 hex}`, and atomically swap a symlink at `{path}` to it, so two files never share a `-wal`. The previous version is kept until the next publish. Copy or back up the index with a tool that follows symlinks. See "Index files after a rebuild or snapshot" in `docs/indexing.md`.
- `new Index($path, force: true)` over an existing index deleted the old file and its `-wal`/`-shm` and created the new file under the same name, which left a moment in which another process's connection could pair the new file with the old sidecars. It now publishes the empty index the same way as `rebuild()`.

## 1.7.0 — 2026-09-27

Added:

- `FacetExclude` — exclusion filters for `search()`, `searchBoolean()`, browse, and `facetSearch()`. Wrap any filter value (a value, a list of values, or a `FacetRange`) to keep only the documents that do not match it: `filter: ['visibility' => new FacetExclude('exclude-from-search')]`. Documents without the field pass, and a multi-value document is removed as soon as one of its values matches, which positive filters cannot express. Exclusions combine with other filters by AND and also apply when their own field is counted: they are constraints, not selections, so they are never counted disjunctively. An exclusion on an undeclared field matches nothing and adds a warning, like any undeclared filter. See "Excluding values" in `docs/search.md`.
- A browse or `facetSearch()` whose only filters are exclusions is answered from the excluded documents instead of visiting every kept one, so its facet counts are exact at any index size and its cost follows the number of excluded documents. On the 44k catalog, a page with its total takes under 1 ms, and three facets take 36–52 ms with 410–4,000 products excluded (33 ms unfiltered).
- `HtmlText::toText()` is now public: the HTML-to-visible-text conversion that `stripHtml` uses for indexing. To have the document store (and so hits, `get()`, and `stream()`) hold text instead of HTML, convert the fields with it before inserting; see "Storing text instead of HTML" in `docs/indexing.md`. The conversion is not idempotent, so convert once from the original HTML and do not combine a pre-converted field with `stripHtml`.
- `docs/search.md` explains how to sort by the document ID: declare `id` in `facetFields`. The 1.6.0 notes below now mention that an undeclared `id:asc` was among the sort specs that stopped working.

Fixed:

- `facetStats` ignored a bound of exactly `0` whenever a facet field was counted with a per-field scan — always for counts over the whole index (such as an unfiltered browse), and for a single facet counted over more than 2,000 matching documents. A `price` field with free items reported the lowest non-zero price as `min`, and a field whose values are all `<= 0` reported the wrong `max`.

## 1.6.0 — 2026-09-26

Behaviour changes to check when upgrading:

- String facet values now sort strictly by bytes. Numeric-looking strings (`"10"`, `"9"`) used to compare as numbers against each other, which made mixed sets order inconsistently; they now sort as text like any other string, as in Meilisearch. Index numbers as `int`/`float` to sort them numerically (range filters and `facetStats` already require that).
- A `sort` spec on a field that is not a declared facet field is now ignored (with a warning), so the query keeps its normal order. Previously every document tied on it, which on a browse replaced the documented newest-first order with oldest-first. This includes `id`: `id:asc` only sorted by ascending ID because of that tie. To sort by the document ID, declare `id` in `facetFields`.
- A browse with a filter now reports the exact `totalHits`; on large indexes this can be much higher than before (see the browse fix below).
- `stripHtml` indexing changed in schema revision 3. Existing `stripHtml` indexes keep the old tokenisation until `Index::rebuild()`.

Added:

- `SearchResult::$warnings` / `getWarnings()` and `FacetSearchResult::$warnings` / `getWarnings()` — human-readable notices about options that were ignored or had no effect, such as a `sort`, `filter`, `facets`, `distinct`, or `facetName` field that is not a declared facet field, and about caps that were hit. Undeclared filters still match nothing.
- `SearchResult::$exhaustive` / `isExhaustive()`, `SearchResult::$approximateFacets` / `getApproximateFacets()`, and `FacetSearchResult::$exhaustive` / `isExhaustive()` — say when a cap made a result approximate: a keyword above `Config::$maxDocs`, a prefix expanding to more than `Config::$fuzzyMaxExpansions` terms, or facet counts over more than `Config::$maxFacetCountDocs` documents. Detection costs one extra row per capped lookup.
- `SearchOptions::$escapeFormatted` (default `false`) — makes every `_formatted` value safe HTML: stored text is escaped, the highlight tags are inserted verbatim, and on a `stripHtml` index the stored HTML is converted to its visible text first, so highlighting no longer lands inside tags or attributes and cropping no longer cuts through markup. `Highlighter`, `Snippeter`, `Index::highlighter()`, and `Index::snippeter()` gained a matching `escape` parameter. The default output is unchanged; `docs/formatting.md` now states that it is only safe to render when the stored text is trusted.
- `Tokenizer::sortKey()` — lowercases, folds Latin accents (`é` → `e`, `ß` → `ss`), and collapses whitespace for a human-facing A–Z sort field such as `title_sort`. Uses a built-in table, so keys are identical on every server.
- `Index::cleanupTempFiles(string $path, int $minAgeSeconds = 3600)` — removes the `{path}.tmp-*` files (and their `-wal` / `-shm`) that a `rebuild()` or `snapshotTo()` leaves behind when its process is killed mid-build, and returns the deleted paths. `rebuild()` now sweeps them before it starts, as `snapshotTo()` already did.
- `docs/indexing.md` documents that writes made to an index while `rebuild()` runs are lost at the swap, and how to serialize or replay them.

Fixed:

- A browse (empty query) with a filter, sort, facets, or distinct only considered the newest `max(filterMaxDocs, maxFacetCountDocs)` documents (10,000 by default). On a larger index, sorted pages missed older documents and filtered `totalHits` undercounted — on a 44k-document catalog, a `gender` filter reported 2,907 hits instead of 22,160. Browse is now answered by SQL over the whole index: totals, pages, and sort order are exact, and a sorted page walks the sort field's index and stops once the page is full. On that catalog, sorted and filtered pages take 0.1–3 ms (previously 12–122 ms). Unfiltered facet counts are exact; filtered ones stay capped at `maxFacetCountDocs` as in `search()`.
- `SchemaConfig::$stripHtml` now converts HTML to the visible text before indexing. Plain `strip_tags()` glued the words of adjacent blocks together (`<p>foo</p><p>bar</p>` was indexed as `foobar`), indexed `<script>`/`<style>` contents, and indexed entities as words (`&amp;` as `amp`). Block tags now separate words, hidden elements are dropped, and entities are decoded. This is schema revision 3 (`Index::CURRENT_SCHEMA_VERSION`); indexes without `stripHtml` are unaffected and already report revision 3.
- Sort order is now a strict total order, independent of the order candidates arrive in: numbers before strings in both directions, numbers by value, strings by bytes, missing values last.
- A multi-value facet field now sorts by the document's smallest value ascending and largest descending, and `distinct` groups it by its smallest value. Previously an arbitrary value was used.
- `facetSearch()` with a `filter` and no `query` only counted the first `filterMaxDocs` (2,000) matching documents; it is now exact.
- `facetSearch()` with a `query` that matched nothing and a `filter` returned counts as if there were no query; it now returns no values.
- `searchBoolean()` returned no results when operators had spaces around them (`shirt | jeans`, `shirt & jeans`), when words were separated by more than one space, or when the query ended in a space — the parser turned every space into an AND, producing dangling operators. Spacing no longer changes the meaning, dangling operators and lone symbols are dropped, parentheses are balanced, NOT works on either side of an AND (`-jeans shirt`), a word followed by a group is an AND (`shirt (jeans or blue)` used to behave like an OR), and a query that is only negations returns no results instead of failing.
- `snapshotTo()` deleted every `{path}.tmp-*` file before starting, including the temp files of a `rebuild()` or `snapshotTo()` still running in another process, which could make that run's final rename fail. Each build now holds an `flock()` on a `{tmp}.lock` file, and the sweep only removes groups whose lock is free and whose newest file is at least an hour old.

Performance and deprecations:

- Faceted search counts two or more facet keys over large result sets up to ~30% faster on schema revision 2+ files.
- Sorting search results by facet fields ranks each field's distinct values once and orders candidates with one native `array_multisort()` instead of a PHP comparison callback — a sorted, faceted search on the 44k catalog went from 15.9 ms to 14.2 ms.
- `facetSearch()` with a `query` or `filter` counts values by looking up each matching document instead of scanning every value of the facet — 10–20% faster for broad restrictions, far faster for narrow ones.
- `Config::$filterMaxDocs` no longer has any effect, since facet filters are always evaluated exactly. It is still accepted and will be removed in 2.0.

## 1.5.0 — 2026-09-26

- `facet_doc_id_index` now covers `(doc_id, key_id, value, num_value)`, making the facet count join index-only — ~29% faster faceted search on a 45k-document index, for +0.2% file size and no measurable write cost. Existing indexes keep working at their current speed; run `rebuild()` to migrate.
- Added `info.schema_version` and `Index::$schemaVersion` / `Index::CURRENT_SCHEMA_VERSION` so a stale index can be detected: `$index->schemaVersion < Index::CURRENT_SCHEMA_VERSION` means `rebuild()` would help. Indexes created before 1.5.0 report revision 1.

## 1.4.0 — 2026-07-28

- Added `reopenIfChanged()` — reopens the connection when the index file was replaced by `snapshotTo()`/`rebuild()` rotation. One `stat()` when unchanged; lets worker-mode runtimes hold a long-lived `Index` across snapshot pushes.
- Added `Config::$cacheSizeKb` (default 65536) and `Config::$mmapSizeBytes` (default 536870912) — tune SQLite's per-connection page cache and shared memory-mapped window. Defaults are unchanged from previous releases.
- Fixed: bulk loads restored `cache_size` to the hardcoded 64 MB default instead of the configured value.
- Added `checkpoint(string $mode = 'TRUNCATE')` — run a WAL checkpoint on demand and read back its page counters, so a long-running writer can keep the `-wal` file bounded when continuous readers starve automatic checkpointing.
- Added `SearchOptions::$attributesToRetrieve` — `[]` returns `['id' => n]` stubs and skips document hydration entirely (~7% faster on a typical faceted request), a field list trims each hit to those keys. Applied after highlight/crop, so formatting still reads full stored values.

## 1.3.1 — 2026-07-15

- Write transactions use `BEGIN IMMEDIATE`, so concurrent writers from other processes wait on `Config::$busyTimeoutMs` instead of failing with an instant `SQLITE_BUSY`.
- Caches are invalidated when another connection commits (`PRAGMA data_version`), so long-lived instances no longer serve stale stats or synonyms, and external deletes can no longer corrupt a cached term ID on write.

## 1.3.0 — 2026-07-12

- Added `Config::$busyTimeoutMs` (default 5000) — sets `PRAGMA busy_timeout` so a second writer waits instead of throwing `SQLITE_BUSY` immediately.
- `delete()` now uses the bulk-removal path when given more than one id, matching the performance of `update()`/`upsert()`.
- `setSynonyms()` now returns the list of multi-word terms it silently skipped, instead of discarding them with no signal.
- Added `Config::$bulkSynchronousOff` (default true) — opt out of the `synchronous=OFF` bulk-load pragma when durability against OS/power-loss crashes matters more than load speed. Documented the precise crash risk in docs/indexing.md.
- Bumped `composer.lock` dependencies.

## 1.2.0 — 2026-06-28

- Added `facetSearch(FacetSearchQuery)` — enumerate facet values with counts; supports case-insensitive prefix match on values, optional FTS restriction (`query` keywords are AND-combined; quoted phrases require adjacent words), and facet filters. Returns `FacetSearchResult`.
- Bumped `composer.lock` dependencies.

## 1.1.0 — 2026-06-11

- Empty phrase (`""`) in `search()` and `searchBoolean()` now browses all documents instead of returning nothing. Supports all `SearchOptions` (filter, sort, facets, distinct, pagination). Default order is newest-inserted first.
- Bumped `actions/checkout` and `actions/cache` in CI.
- Added release workflow that creates a GitHub Release on `v*` tag push.

## 1.0.0 — initial release

# Changelog

## 2.0.0 - unreleased

See `UPGRADE-2.0.md` for what calling code has to change.

Breaking:

- Index files written by 1.x (schema revisions 1 to 3) are no longer opened: `new Index()` throws a `QueryException` that says how to recreate the index. `Index::rebuild()` with a callback and a schema replaces such a file without opening it. The same happens for a file written by a newer version. 2.0 writes schema revision 4; the code that kept older revisions working (the narrow facet index, `strip_tags()` for old `stripHtml` files, defaults for missing `info` keys) is gone.
- `SchemaConfig::$facetFields` is replaced by `filterableFields` (fields for `filter`, `facets`, `distinct`, and `facetSearch()`) and `sortableFields` (fields for `sort`). `$index->facetFields` is replaced by `$index->filterableFields` and `$index->sortableFields`.
- A field outside the matching list in `sort`, `filter`, `facets`, `distinct`, or a `FacetSearchQuery` now throws a `QueryException` naming the field and the declared fields, instead of being ignored with a warning.
- `Config::$filterMaxDocs` is removed; it had no effect since 1.6.0.
- `Config::$fuzzyMinWordLength` is replaced by `Config::$typoTolerance` (`TypoTolerance`): `enabled`, `minWordSizeForOneTypo` (5), `minWordSizeForTwoTypos` (9, was fixed), `disableOnNumbers`, and `disableOnWords`.
- `SearchOptions::$matchingStrategy` (`MatchingStrategy::Last` default, `All`, `Frequency`): a multi-word `search()` returns documents containing the first word (`Last`), every word (`All`), or the rarest word (`Frequency`), ranked by how many words they match in that order. 1.x returned documents with any word. `sort` now applies after this words ranking instead of before it. `All` is exact when its rarest word is under `maxDocs`.
- `SearchOptions::$limit` defaults to 20 (was 100).
- A query word that exists in the index also matches its typos, as in Meilisearch (typo matching used to start only when the word had no match). Within a words bucket, documents that match with fewer typos rank first, before `sort`. On 1.43M Amazon products "bluetoth headphones" ("bluetoth" is in 20 titles) returns 792 hits instead of 20.
- A `language` no longer enables a stop word list, and the bundled lists are removed: every word is indexed and searched, as in Meilisearch. Stop words are the new `SchemaConfig::$stopWords` (single words, empty by default), left out of the index and of queries. The English list held 1,298 words, among them `c`, `men`, `tv`, `case` and `computer`: `"usb c cable"` was searched as `"usb cable"` and "Men" was never indexed. `Language::hasStopwords()` is removed, and the 27 language tags that only had a list (`af`, `cs`, `he`, `uk`, `vi`, …) throw a `QueryException` at index creation.
- `Config::$maxFacetCountDocs` defaults to 50,000 (was 10,000): facet counts over up to 50,000 matching documents are exact. A 28.6k-product category of 1.43M Amazon products with three facets counts all matches in 23 ms, where 1.7.1 took 35 ms for the first 10,000.
- `inspectQuery()` lists `-word` / `-"phrase"` exclusions in the new `QueryInspection::$negations` (with their normalised tokens and whether `search()` applies them) instead of among the searched tokens.
- String sort is case-insensitive: uppercase letters sort as if they were lowercase, so `"apple"` comes before `"Zebra"`. Accents are not folded. Sorting on string fields walks a new `sort_keys` table, written only for string values of sortable fields.
- `search()` and `facetSearch()` queries support exclusions: `shirt -formal` leaves out documents containing "formal", `-"long sleeve"` a phrase, and a query of only exclusions returns every other document. In 1.x a leading `-` was ignored and the word was searched for.
- `facetSearch()` matches `facetQuery` at the start of any word of a value and ignores case and Latin accents ("rosa cl" finds "rosa-clara" and "Rosa Clará"). It was a case-insensitive prefix of the whole value.
- Facet values with equal counts are always listed in value order, so the same request returns the same distribution whichever way it was counted (also which values survive the `maxValuesPerFacet` cut).
- Two swapped neighbouring characters count as one typo (`casaul` finds `casual`).
- Each field's word positions start on their own instead of continuing from the previous field: a quoted phrase no longer matches across the end of one field and the start of the next. Proximity scoring measures the distance between query words inside one field; a window whose words lie in different fields counts a fixed cross-field distance per word gap. On the 44k catalog 99% of 300 multi-word searches keep their top hit (mean top-24 overlap 0.98).

Added:

- `SearchOptions::$sortFacetValuesBy` and `FacetSearchQuery::$sortFacetValuesBy` (`FacetOrder::Count` default, `Alpha`) order facet values; alphabetical order is applied before `maxValuesPerFacet` / `limit`.
- `optimize(): int` purges the word index rows of deleted documents (see the `delete()` entry below) and returns how many documents it purged.
- `deleteByFilter(array $filter): int` deletes every document that matches a filter (the shape of `SearchOptions::$filter`, filterable fields only) and returns how many; an empty filter throws.
- `Index::remove($path)` deletes an index with everything Fuzor keeps next to it: the link at the path, every `{path}.v-{8 hex}` version, their `-wal`/`-shm` files, and temp files of builds that are no longer running. Unlinking the path alone, as before 1.7.1, leaves the version files behind.
- `Index::install($file, $path, check: false)` puts an index file made elsewhere (a snapshot from another server) at a path: copied next to it, synced, rejected unless it is a complete Fuzor index this version opens (optionally `PRAGMA quick_check`), then published like `rebuild()`. A file with a non-empty `-wal` is refused.
- A multi-document `insert()`, `update()` or `upsert()` tokenises and writes its documents in chunks of the new `Config::$insertChunkSize` (default 2,000) inside its single transaction, reading a generator one chunk at a time. On the 44k catalog, a streamed `insert()` peaks at 127 MB instead of 2 GB and `rebuild()` at about 150 MB instead of 2 GB, at the same speed. The `progress` callback's `$total` is `0` for a generator or other non-countable input.

Performance (44k-product catalog, against 1.7.1):

| Operation | 1.7.1 | 2.0 |
|---|---|---|
| Shop page: 5 facets, 4,025 products excluded | 37 ms | 13 ms |
| Facets of an unfiltered page / price stats | 9.8 / 6.2 ms | 0.5 / 1.0 ms |
| Price range sorted by price | 11.7 ms | 0.7 ms |
| One gender sorted by price | 0.84 ms | 0.37 ms |

Further improvements (this version, relative to 2.0 milestone builds):

- Per-field term hit data moved into two columns on `doclist` (`field_id`, `field_split`); the separate `field_hits` table is gone. Index size −50 MB on the 44k catalog (319 → 269 MB). Field-boost pair read 1.3 → 0.9 ms.
- Partial-text `update()` / `upsert()` now rewrites only the word-index rows that actually changed. Price-only update of 200 products 358 → 46 ms; title + one word 9,541 → 2,888 WAL pages, 780 → 278 ms.
- Sorted, filtered browse pages choose between walking the sort field and sorting the matches by the expected walk length, and fall back to sorting when the walk runs long. On 1.43M products: a category sorted by price 33.6 → 17.8 ms, with a stars range sorted by reviews 73.8 → 32.2 ms, three categories 66.7 → 19.4 ms. Range filters count their rows from the facet counts (47 → 0.12 ms there).
| Two genders sorted by price | 7.4 ms | 1.1 ms |
| Listing: two genders, 6 facets, sort | 34 ms | 13 ms |
| `distinct` browse / with filter and sort | 43 / 63 ms | 20 / 44 ms |
| `facetSearch()` without query or filter | 4.1 ms | 0.2 ms |
| `facetSearch()` with a filter | 11.0 ms | 9.6 ms |
| Price-only `update()` of 200 products | 360–400 ms | 45–80 ms |
| `delete()` of 50 products / of one | 260–390 / 3.9 ms | 12–25 / 0.5 ms |
| `insert()` from a generator, all products | 25 s, 2.0 GB | 17 s, 138 MB |
| `rebuild()` | 15 s, 2.0 GB | 15 s, 164 MB |
| Multi-word search ("blue cotton shirt") | 1.7 ms | 5.7 ms |
| Two-word search with a typo ("casaul shirt") | 0.7 ms | 4.0 ms |

Multi-word searches are slower because 1.7.1 scored about half of their candidates as if a word were missing, and missed swapped-letter typos entirely (see Fixed); 2.0 ranks them correctly. `facetSearch()` with an accented `facetQuery` and a filter takes 12% longer (8.6 → 9.6 ms) and finds more values (word starts, accents folded). A single-word search sorted by a string field takes 1.2 → 1.4 ms. Every other read scenario is within ±7%.

- `delete()` removes a document's own rows at once and marks its rows in the word index deleted; they are purged in bulk by the new `optimize()`, by `rebuild()` / `clear()`, or by `delete()` itself once deleted documents reach 10% of the index. On the 44k catalog a 50-document delete takes 13 ms instead of 250–340 ms (WAL 541 pages instead of 5,335) and a single delete 0.5 ms instead of 3.4 ms. Until the purge, BM25's per-word document counts include the deleted documents (with 10% deleted: same first hit, 97% of top-20 sets unchanged) and searches add a 0.1–1.3 ms filter.
- `update()` and `upsert()` keep a document's word index entries when its searchable fields are unchanged (compared with the stored document, so only with the document store enabled) and rewrite only the stored document and its filterable / sortable values. On the 44k catalog a price-only update of 200 products takes 46 ms instead of 360 ms (WAL 1,046 pages instead of 8,284); an update that changes the titles costs the same as before.
- Facet counts over the whole index, and the counts of a page whose only filters are exclusions, read a new `facet_counts` table kept exact on every write, instead of counting every document. On the 44k catalog: facets of an unfiltered page 9.9 → 0.6 ms, price stats 6.2 → 1.3 ms, a shop page with five facets hiding 4,025 products 37 → 13 ms (hiding 34,409: 60 → 45 ms), an unrestricted `facetSearch()` 4.1 → 0.2 ms. Writes stay within a few percent.
- Filters on fields that never hold two values per document skip `DISTINCT` in their exact counts, and a range filter on the sort field of such a field seeks straight into the range: on the 44k catalog a price range sorted by price 11.5 → 0.8 ms, `gender: [Women, Men]` sorted by price 6.6 → 1.4 ms.
- Field boosts (`Config::$fieldBoosts`) read per-field hits only for the term and document pairs a search found, instead of every term against every candidate: with a title boost, `sh` 3.7 → 2.1 ms, "blue jeans" 7.2 → 5.3 ms, "blue cotton shirt" 13.5 → 9.5 ms.
- A filtered browse with facets reads its matching documents once for both the total and the facet counts, and text-only facets skip the numeric stats query. Exclusion-only facet counts on a large index subtract the excluded documents instead of scanning the kept ones whenever few are excluded: a shop page hiding 9% of a 533k catalog counts five facets in 171 ms instead of 714 ms.
- `update()` and `upsert()` write only the word index rows that change when a document's searchable text changes, also without a document store: appending one word to the title of 200 products writes 2,888 WAL pages instead of 9,541 and takes 278 ms instead of 780 ms on the 44k catalog; a document whose text changes completely is rewritten as before, at the same speed. Without a document store, an update that leaves the text unchanged no longer touches the word index.
- A browse filtered on one field whose documents hold one value each takes its total from the maintained facet counts instead of counting the matching rows: on the 44k catalog `gender: Men` sorted by price 0.79 → 0.29 ms (6.8 → 0.45 ms on a 533k catalog). Filter fields are also ordered by the same counts.
- A browse that combines filters with exclusions that select fewer documents than the filters counts its total as the filter's matches minus the excluded ones among them: men without sports products, five facets, 34 → 26 ms on the 44k catalog, 149 → 59 ms on 533k.
- A value list that selects most documents of a field every document has once (`gender: [Women, Men]`) is browsed as the exclusion of the other values when those hold at most `maxFacetCountDocs` documents. Its facet counts are then exact instead of approximate, and the 44k listing with six facets takes 13 ms instead of 21 ms.
- `distinct` and sorting over many matches read the field's values with one scan of the field instead of one lookup per document, and numbers sort without string keys: `distinct` browse 42 → 20 ms, with a filter and sort 55 → 44 ms.
- Exclusion-only facet counts subtract the excluded documents' counts whatever share of the index they exclude (was up to 25%, then a scan): `-shirt` with three facets 33 → 11 ms on the 44k catalog.
- Filtered facet counts read one row per matching document for all facet fields instead of one row per document and field: each document's filterable values are stored together as packed IDs in a new `doc_facets` table (with a `facet_value_ids` dictionary). Same counts, about 0.6–1 µs per counted document. On the 44k catalog, gender Men with six facets 42.6 → 9.9 ms, Tshirts with six facets 31.6 → 8.1 ms; on 1.43M Amazon products, a 28.6k category with three facets 34.5 → 10.8 ms. Single-document updates cost up to 0.2 ms more, a full build 3–5% more, and the 1.43M index is 41.5 MB larger.
- `facetSearch()` counts the values of a filtered or queried set in PHP instead of grouping them in SQL, from the documents' packed facet rows: 15–36% faster with a filter or a broad query (a 28.6k category of 1.43M products, stars: 23.7 → 15.2 ms).
- A multi-word `search()` no longer loses documents that contain every word when each word is common. Each word still fetches its `maxDocs` best rows first; when those hold fewer full matches than the page needs (or with a filter, `-word` or `distinct`), the documents containing all words (then all but the last, …) are read exactly while the rarest word is in at most 50,000 documents. On 1.43M Amazon products "women cotton dress" returns 20 of 20 full matches instead of 1 (22 → 39 ms), `women "cotton dress"` 6 hits instead of 0. Completing a word for the candidates looks each document up instead of scanning the word's postings, so most multi-word searches got faster: "men running shoes" 19 → 10 ms, "women shoes" 20 → 8 ms, `"usb c cable"` 29 → 20 ms.
- Proximity ranking runs after filtering and stops once the requested page is settled (with sort fields it only breaks ties on the page), so filtered and sorted multi-word searches no longer compute word distances for documents that are filtered out or never shown.

Fixed:

- A typo'd word could miss its match when more than `fuzzyMaxExpansions` (50) terms shared its first three letters, because the candidates were cut by frequency before their distance was checked. The cap now applies to the closest matches.
- In a multi-word `search()` where a word matches more than `Config::$maxDocs` documents, documents found through another word were scored and ranked as if they lacked the common word, so documents matching all words could rank below documents matching one. Those documents are now scored for every word they contain ("casual shirt" on the 44k catalog: about half of the candidates gained the missing word).
- A prefix whose expansions together count more documents than the index (`car` matching `cara`, `carb`, `carc`) got a negative IDF, which ranked documents with more matching words last.
- After a write that failed and was rolled back (an `insert()` with a duplicate ID in a later chunk), the next writes on the same `Index` reused cached IDs of words, facet fields and field names that the rollback had removed, and the cached document count stayed raised: those documents could not be found by search or filter, also not from other connections.
- `has()` on an existing document, a filtered browse, and `distinct` or sorting over 1,000 or more documents left a read open on the connection until its next query. Meanwhile another connection's `checkpoint()` or `close()` waited for the busy timeout (5 s by default), and the write-ahead log of a long-lived reader could not be reset.

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

# Changelog

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

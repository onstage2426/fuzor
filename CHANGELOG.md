# Changelog

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

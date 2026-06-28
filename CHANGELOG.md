# Changelog

## 1.2.0 — 2026-06-28

- Added `facetSearch(FacetSearchQuery)` — enumerate facet values with counts; supports case-insensitive prefix match on values, optional FTS restriction (`query` keywords are AND-combined; quoted phrases require adjacent words), and facet filters. Returns `FacetSearchResult`.
- Bumped `composer.lock` dependencies.

## 1.1.0 — 2026-06-11

- Empty phrase (`""`) in `search()` and `searchBoolean()` now browses all documents instead of returning nothing. Supports all `SearchOptions` (filter, sort, facets, distinct, pagination). Default order is newest-inserted first.
- Bumped `actions/checkout` and `actions/cache` in CI.
- Added release workflow that creates a GitHub Release on `v*` tag push.

## 1.0.0 — initial release

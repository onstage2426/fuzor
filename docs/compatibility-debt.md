# Compatibility debt

Every mechanism that exists only to keep older index files working. Each entry states
what it tolerates, how a user migrates, and what to delete when the next major release
drops support.

**Policy:** anything added to 1.x that accepts an older on-disk shape gets an entry here
in the same commit that introduces it. 2.0 empties this file.

Since 1.5.0 the `info` table carries a `schema_version` key (`Index::CURRENT_SCHEMA_VERSION`,
exposed as `$index->schemaVersion`), so a stale file can be detected:

```php
if ($index->schemaVersion < Index::CURRENT_SCHEMA_VERSION) {
    // rebuild() would speed this index up
}
```

Revisions are cumulative and `rebuild()` always writes the current one, so a single rebuild
migrates a file from any older revision.

Everything listed here degrades gracefully — an older file works, it is just slower or
indexes HTML less cleanly.

---

## Schema revision 1: narrow `facet_doc_id_index`

**Since:** 1.5.0. **Remove in:** 2.0.

Revision 1 (any index created before 1.5.0) has `facet_doc_id_index ON facet_values
(doc_id)`. Revision 2 widens it to `(doc_id, key_id, value, num_value)` so the facet count
join in `fetchAllFacetCountsJoin()` is satisfied index-only rather than seeking into the
table once per row. Measured on the 45k-document ecom set: **~29% faster faceted search**
(p50 15.0 ms → 10.8 ms), for +0.2% file size and no measurable write cost.

**The mechanism:** the queries are identical for both revisions; SQLite simply picks the
covering plan when the index supports it. Two places are version-aware:

- `facetDocIndexDdl(int $schemaVersion)`, which the bulk-load teardown calls with
  `$this->schemaVersion` so that dropping and recreating indexes around a bulk write cannot
  silently change the shape of an older file.
- `collectFacetCounts()` (since 1.6.0), which counts two or more facet keys over a large
  document set with the doc-driven join only on revision 2. There the join is ~30% faster
  than the per-key scan; on revision 1 every join row seeks into the table and the scan wins.

**Migration:** `Index::rebuild($path)` — it builds a fresh index through `createIndex()`,
so the new shape and version key come for free. Note that `snapshotTo()` does *not*
migrate: `VACUUM INTO` copies the source schema verbatim, so a snapshot of a revision-1
index is still revision 1. Rebuild the write index once, then snapshots inherit it.

**On removal:** delete `facetDocIndexDdl()`'s `$schemaVersion` parameter and the branch,
inline the revision-2 DDL, drop the `$this->schemaVersion >= 2` term in
`collectFacetCounts()`, and reject files reporting `schemaVersion < 2` at open.

---

## Schema revision 2: `strip_tags()` for `stripHtml` indexes

**Since:** 1.6.0. **Remove in:** 2.0.

Revisions 1–2 (any index created before 1.6.0) tokenise `stripHtml` fields after plain
`strip_tags()`. That glues the words of adjacent blocks together (`<p>foo</p><p>bar</p>` →
`foobar`), indexes the contents of `<script>`/`<style>`, and leaves entities encoded, so
`&amp;` and `&nbsp;` become the words `amp` and `nbsp`. Revision 3 converts HTML to text
with `HtmlText::toText()` instead.

**The mechanism:**

- `indexFieldColumn()` picks the converter by `$this->schemaVersion`, so documents written
  to an older file keep the old tokenisation and a file never mixes both.
- `selectIndex()` reports a revision-2 file **without** `stripHtml` as revision 3, since
  revision 3 changed nothing for it. Without that, every 1.5.0 index would claim that a
  `rebuild()` would help.

**Migration:** `Index::rebuild($path)`. Indexes without `stripHtml` need nothing.

**On removal:** call `HtmlText::toText()` unconditionally in `indexFieldColumn()`, drop the
revision-2 promotion in `selectIndex()`, and reject files reporting `schemaVersion < 3` at
open, together with revision 1.

---

## The `schema_version` key fallback

**Since:** 1.5.0. **Remove in:** 2.0.

```php
$this->schemaVersion = (int) ($infoRows['schema_version'] ?? 1);
```

Indexes created before 1.5.0 have no `schema_version` row; absence means revision 1.

**Migration:** same as above — `rebuild()`.

**On removal:** read the key directly and treat a missing value as an unsupported file.

---

## Vestigial: `??` defaults for `info` keys in `selectIndex()`

**Since:** pre-release. **Remove in:** 2.0.

```php
$lang      = ($infoRows['language'] ?? '') !== '' ? $infoRows['language'] : null;
$facets    = self::decodeStringList($infoRows['facet_fields'] ?? '[]');
$sfRaw     = $infoRows['searchable_fields'] ?? '';
$stripHtml = ($infoRows['strip_html'] ?? '0') === '1';
```

`createIndex()` writes `language`, `facet_fields`, `searchable_fields`, and `strip_html`
unconditionally, so these fallbacks are unreachable for any index created by a 1.x
release. They only matter for pre-release files.

**Migration:** none needed — no such files should exist in the wild.

**On removal:** read the keys directly. Expect a missing key to be an error.

### Do not remove: `has_document_store ?? '0'`

Looks like the same pattern but is load-bearing. The key is written *only when the store
is enabled*, so its absence is the encoding for `store: false`. Deleting this `??` breaks
every store-less index. If the encoding is ever cleaned up, write the key explicitly as
`'0'` in `createIndex()` first, and that becomes its own compatibility entry.

---

## Planned for 2.0: reject undeclared field references

Not compatibility debt — a contract gap deferred to 2.0 because closing it is breaking.

`sort`, `filter`, `facets`, and `distinct` in `SearchOptions`, and `facetName` / `filter` in
`FacetSearchQuery`, only work on fields declared in `facetFields`; their values live in
`facet_values`, which nothing else populates. Since 1.6.0 an undeclared reference is reported
in `SearchResult::$warnings` / `FacetSearchResult::$warnings`, and has this effect:

| Option | 1.6 behaviour |
|---|---|
| `sort` | Spec dropped; the remaining specs apply, otherwise the normal order |
| `filter` | Matches nothing (fails closed) |
| `facets` | No counts for that field |
| `distinct` | No deduplication |
| `facetName` | No values |

**Fix in 2.0:** throw `QueryException` (an error tied to index configuration, per the
exception convention) naming the field and the declared facet fields, from one shared check
(`checkDeclaredFields()` and the `facetSearch()` equivalent). Check the schema declaration,
*not* `lookupFacetKeyId()` — a declared facet field that no document has populated yet also
yields a null key, and using it is legitimately "no values", not a contract violation.
Meilisearch behaves the same way: `invalid_search_sort`, `invalid_search_filter`,
`invalid_search_facets`, `invalid_search_distinct`, and `invalid_facet_search_facet_name`
for attributes outside `sortableAttributes` / `filterableAttributes`, while a declared but
absent attribute is accepted silently.

**Why not sooner:** these options are usually user-controlled (a `sort[]=` query parameter),
so throwing turns arbitrary user input into an uncaught exception for consumers that
reasonably assume this call does not throw. That needs a major-version signal.

**Meanwhile:** `$index->facetFields` is public, so consumers can validate before calling,
and the warnings make the problem visible without extra code.

**On change:** replace the warning branches with the throw; keep `$warnings` on both result
classes for the degraded-but-valid cases (approximate counts and the like).

---

## Planned for 2.0: case-insensitive string sort

Not compatibility debt — a behaviour change deferred to 2.0 because it reorders results.

String sort values compare by raw bytes (`strcmp`), so `"Zebra"` sorts before `"apple"`.
Meilisearch compares strings byte-wise too, but case-insensitively ("uppercase letters are
sorted as if they were lowercase"); it does not fold accents (`á` sorts after `z`).

**Change in 2.0:** match Meilisearch — case-folded byte order. The SQL browse path walks
`facet_values` in index order, so the folded key must be stored rather than computed per
comparison: a `sort_value` column (or equivalent) populated at write time, plus its index.
That is a physical schema change, so it takes a `CURRENT_SCHEMA_VERSION` bump and a
`rebuild()`. Accent folding stays opt-in via a normalized facet field such as `title_sort`
holding `Tokenizer::sortKey($title)` (added in 1.6.0).

**Why not sooner:** an index rebuilt under 1.x would silently change the order of every
string sort, which is a breaking change without a major version.

---

## Planned for 2.0: remove `Config::$filterMaxDocs`

Not compatibility debt — a dead option kept only so existing `new Config(filterMaxDocs: …)`
calls keep working.

Since 1.6.0 facet filters are always evaluated exactly: `search()` / `searchBoolean()` test
them against their candidate set, while a browse and `facetSearch()` without a query run them
in SQL over the whole index. The option no longer caps anything.

**Fix in 2.0:** delete the constructor parameter and its row in `docs/tuning.md`.

**Why not sooner:** removing a named constructor parameter breaks every caller that passes it.

---

## Planned for 2.0: tombstone deletes

Not compatibility debt — a write-path redesign found while profiling 1.6.0 with SPX.

Deleting a document removes its rows from every posting list it appears in: `doclist`,
`doclist_term_hitcount`, `positions`, and `field_hits`, all clustered by term. Each deleted
document therefore dirties a page in each of its terms' B-trees. Measured on the 44k ecom
set: deleting 50 documents dirties 5,225 pages — **82 MB of WAL**, about 1.6 MB per
document — and the automatic checkpoint that `COMMIT` triggers copies them back into the
database file, so a 50-document `delete()` takes 400–700 ms. `update()` pays the same cost
for its remove step. The layout is the right one for reads; the cost lands on deletes.

**Change in 2.0:** delete by tombstone, as Lucene and Meilisearch do. `delete()` writes the
id to a small `deleted_docs` table and removes only the per-document rows (`doc_lengths`,
`documents`, `facet_values`); search subtracts tombstoned ids from its candidates; posting
lists are purged in bulk later (by `rebuild()`, or an explicit `optimize()`/purge pass that
walks each term once). A new table is a physical schema change: bump
`CURRENT_SCHEMA_VERSION`, migrate with `rebuild()`.

**Why not sooner:** it touches every read path (candidate filtering), the stats in `info`
(`total_documents`, `avg_doc_length`, and per-term `num_docs` used by BM25 would include
tombstoned rows until purged), and the write paths. That is a design change, not a fix.

---

## Planned for 2.0: bounded memory for bulk inserts and `rebuild()`

Not compatibility debt — a memory ceiling found while profiling 1.6.0 with SPX.

`insertMany()` starts with `iterator_to_array($documents)`, so a generator passed to a
single `insert()` call is materialised in full, and Phase 1 (`buildBatchBuffer()`) then holds
the term, position, field-hit, and facet buffers of **every** document at once before Phase 2
writes anything. Measured on the 44k ecom set (75 MB of JSONL): a single bulk `insert()` and
`rebuild()` both peak at ~2 GB of PHP memory. Callers who control the input can bound it by
calling `insert()` several times with smaller batches — slower, since each call pays the
per-call setup and loses cross-batch term aggregation, but memory stays proportional to the
batch. `rebuild()` without a callback offers no such choice: it passes
`$existing->stream(500)` to one `insert()`, so the whole store is loaded regardless of the
batch size, and a rebuild of a few thousand documents can exceed a typical 256 MB
`memory_limit`.

**Change in 2.0:** process an iterable in chunks (a few thousand documents) inside
`insertMany()`'s single transaction — Phase 1 and Phase 2 per chunk, indexes dropped once for
the whole load, stats adjusted once at the end — so memory is bounded by the chunk and a
generator is never materialised. `rebuild()` then streams for free. Benchmark the chunk size
against the one-pass load: per-chunk doclist inserts append to each term's B-tree range more
than once, which may cost some of the sorted-insertion speedup.

**Why not sooner:** the bulk path is the most tuned code in the library; changing it needs a
benchmark pass of its own, not a pre-release fix.

---

## Planned for 2.0: range filter and sort on the same field

Not compatibility debt — a known slow case in the browse index walk.

A browse that filters a numeric range and sorts on the same field (`filter: ['price' =>
FacetRange::between(500, 1500)], sort: ['price:desc']`) takes ~27 ms on the 44k ecom set,
against ~2 ms for other filtered, sorted pages. The result is correct; it is only slow.
The walk starts at the top of the price index and probes past every price above the range
before it reaches a match, and the exact count of a range needs `DISTINCT` over ~20k rows.

**Change in 2.0:** push the range bounds into the walk's `WHERE`, so SQLite seeks straight
to the range. This changes semantics for multi-value fields: today a document sorts by its
smallest (asc) or largest (desc) value overall, and with the bounds pushed down it would sort
by its smallest or largest value *inside* the range. The latter is arguably what a user
expects (sizes [36, 44] filtered to 40–46 sort as 44), but it has to change in `search()`
too so both paths keep ordering identically — decide the rule, then change both.

**Why not sooner:** the multi-value ordering rule is a behaviour change.

---

## Planned for 2.0: skip re-indexing unchanged searchable fields on update

Not compatibility debt — a write-path saving found in the 1.6.0 SPX pass.

`update()` / `upsert()` always remove every posting-list row of a document and insert it
again, even when no searchable field changed. The ecom price-sync bench (`update()` of 200
products, only `price` changed) writes 8 100 WAL pages, and about 89% of them belong to
`doclist`, `positions`, `field_hits`, and `doclist_term_hitcount` — tables whose content is
identical before and after. Only `facet_values`, `documents`, and `doc_lengths` actually
change.

**Change in 2.0:** when the document store is enabled, compare the incoming document's
searchable fields (after `stripHtml` conversion) with the stored one; if they are equal,
rewrite only the stored document and its facet rows. That cuts this workload's page writes
to about 11% and, since write amplification dominates the update cost (see the tombstone
entry), most of its time. Without a store there is nothing to compare against, so the full
path stays.

**Why not sooner:** it adds a store read and a comparison to every update and changes which
rows a write touches; it needs its own tests and a benchmark of the no-change and all-change
cases.

---

## Planned for 2.0: facet query cost trims

Not compatibility debt — smaller read-path savings measured in the 1.6.0 SPX pass (44k
ecom set). Each is independent.

- **Browse with filters and facets evaluates the matching set twice.** `countBrowseDocs()`
  and `browseFacetDocIds()` both run `matchingDocsSql()`. Fetching the ids once with
  `LIMIT maxFacetCountDocs + 1` and using the list length as the total whenever it stays
  under the cap measured 6.2 ms instead of 10.7 ms — about 4.5 ms, ~10% of a "2 filters +
  3 facets" browse, same results.
- **Whole-key facet counts compute numeric stats for text fields.** `fetchFacetCountsForKey()`
  always aggregates `MIN/MAX/SUM(CASE…)` over `num_value`: 11.0 ms per key against 4.9 ms
  for `COUNT(*)` only (brandName, baseColour, articleType alike). Probing
  `facet_numeric_index` for any numeric row of the key costs 0.02 ms. Skipping the stats for
  text-only keys takes a facets-only browse from ~22 to ~10 ms. Needs a test that tie order
  among equal counts is unchanged.
- **`DISTINCT` in exact counts is only needed for multi-valued fields.**
  `matchingDocsSql()` adds `DISTINCT` whenever the driving condition can match several rows
  per document (a value list or a range). Over 40 792 rows that costs 16 ms against 2 ms
  without it (a range: 6.6 vs 0.6 ms) — 15.7 of 17 ms of a `gender: [Women, Men]` + price
  sort browse. It is only necessary when a document can hold several values for the field;
  the ecom set has none.

The last item and the range-filter-and-sort entry above both need to know whether a facet
field is single-valued. Record that per key at write time (e.g. a flag on `facet_keys`,
cleared the first time a document stores a second value) — a physical schema change, so it
takes a `CURRENT_SCHEMA_VERSION` bump and a `rebuild()`, and both improvements can land
together.

**Why not sooner:** none is a regression; each needs its own benchmark pass and, for the
last one, a schema change.

---

## Planned for 2.0: `schemaVersion` wording

Not compatibility debt — a documentation inaccuracy.

`Index::$schemaVersion` and `docs/compatibility-debt.md`'s introduction say a
`schemaVersion < Index::CURRENT_SCHEMA_VERSION` means `rebuild()` "would speed the index
up". That holds for revision 2 (covering facet index). Revision 3 changes `stripHtml`
tokenisation: it improves correctness, halves the term dictionary, and makes full-text
queries 8–23% faster, but writes about 5% slower (+3.5% positions per document). Reword to
"`rebuild()` would bring the index up to date" and let each revision's entry state its
trade-off. This is a docs-only change and can ship in any release.

---

## Note for 2.0

Bumping `CURRENT_SCHEMA_VERSION` is the mechanism for any future physical schema change.
When 2.0 drops support for older revisions, `selectIndex()` should reject them with a
clear message naming `rebuild()` rather than failing later on an obscure SQL error.

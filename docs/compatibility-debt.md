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

Everything listed here degrades gracefully — an older file works, it is just slower.

---

## Schema revision 1: narrow `facet_doc_id_index`

**Since:** 1.5.0. **Remove in:** 2.0.

Revision 1 (any index created before 1.5.0) has `facet_doc_id_index ON facet_values
(doc_id)`. Revision 2 widens it to `(doc_id, key_id, value, num_value)` so the facet count
join in `fetchAllFacetCountsJoin()` is satisfied index-only rather than seeking into the
table once per row. Measured on the 45k-document ecom set: **~29% faster faceted search**
(p50 15.0 ms → 10.8 ms), for +0.2% file size and no measurable write cost.

**The mechanism:** none, in PHP. The query is identical for both revisions; SQLite simply
picks the covering plan when the index supports it. The only version-aware code is
`facetDocIndexDdl(int $schemaVersion)`, which the bulk-load teardown calls with
`$this->schemaVersion` so that dropping and recreating indexes around a bulk write cannot
silently change the shape of an older file.

**Migration:** `Index::rebuild($path)` — it builds a fresh index through `createIndex()`,
so the new shape and version key come for free. Note that `snapshotTo()` does *not*
migrate: `VACUUM INTO` copies the source schema verbatim, so a snapshot of a revision-1
index is still revision 1. Rebuild the write index once, then snapshots inherit it.

**On removal:** delete `facetDocIndexDdl()`'s `$schemaVersion` parameter and the branch,
inline the revision-2 DDL, and reject files reporting `schemaVersion < 2` at open.

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

## Note for 2.0

Bumping `CURRENT_SCHEMA_VERSION` is the mechanism for any future physical schema change.
When 2.0 drops support for older revisions, `selectIndex()` should reject them with a
clear message naming `rebuild()` rather than failing later on an obscure SQL error.

# Performance

## Read/write index split

The most impactful production pattern is separating the write index from the read index. Search endpoints open the read index in `readonly` mode and never block on writes.

```
write index          snapshotTo()         read index
products.db  ──────────────────────►  products-read.db
insert/update/delete  (periodic)       search only
```

**Write side** — mutate normally, push snapshots on your staleness budget:

```php
use Fuzor\Index;
use Fuzor\SchemaConfig;

$write = new Index('/var/db/products.db', schema: new SchemaConfig(language: 'en'));

$write->upsert($updatedProducts);
$write->delete($removedId);

// Push whenever your staleness allows — every minute, every N writes, on a cron, etc.
$write->snapshotTo('/var/db/products-read.db');
```

`snapshotTo()` uses SQLite's `VACUUM INTO` under a read transaction. WAL mode means writes are never blocked while the snapshot runs.

**Read side** — open the snapshot `readonly`:

```php
$read = new Index('/var/db/products-read.db', readonly: true);
$results = $read->search('electric bike');
```

`readonly: true`:
- Uses a read-only file descriptor — no lock acquisition per query
- Lets the OS share memory-mapped pages across all PHP-FPM workers for that file
- Skips WAL and checkpoint overhead entirely (snapshot files have no WAL)

**Long-lived readers (Swoole, FrankenPHP worker mode)** — a per-request `new Index(...)`
picks up each new snapshot automatically, but a worker that holds one instance across
requests keeps reading the *old* file forever: the rename swaps the inode at the path,
not the file the connection has open. Call `reopenIfChanged()` once per request:

```php
// Worker startup:
$read = new Index('/var/db/products-read.db', readonly: true);

// Per request:
$read->reopenIfChanged();   // one stat(); reopens only after a snapshot rotation
$results = $read->search($query);
```

When nothing changed it costs a single `stat()` and every cache stays warm; after a
rotation it reopens the connection and releases the old snapshot's disk space.

## Memory profile for many-worker fleets

SQLite's page cache is **private to each connection**. The default 64 MB is sized for a
single writer process; a fleet of 32 PHP-FPM workers holding the read index open can
allocate 2 GB of duplicated cache for one file. Memory-mapped pages have the opposite
property — the OS shares them across every process mapping the same file — so read
fleets should shrink the private cache and lean on `mmap_size`:

```php
$read = new Index('/var/db/products-read.db', readonly: true, config: new Config(
    cacheSizeKb:   8_192,          // 8 MB private per worker (default 64 MB)
    mmapSizeBytes: 2_147_483_648,  // 2 GB shared mapping (default 512 MB)
));
```

Size `mmapSizeBytes` at or above the index file so the whole thing can stay resident;
it costs address space, not committed memory, and pages are only faulted in on access.
Keep the default `cacheSizeKb` on the write side, where the private cache absorbs
B-tree splits during indexing. Bulk loads temporarily raise `cache_size` to 512 MB
regardless, then restore the configured value.

## Rebuild as publisher (no write tracking)

When tracking individual writes is impractical — a CMS like WordPress, where content
changes come from too many code paths to hook — skip the write index entirely.
Rebuild the live index on a cron and let the rebuilt file *be* the read index:

```php
// Cron job, e.g. every 15 minutes:
Index::rebuild('/var/db/site-search.db', function (Index $new): void {
    foreach (loadAllPosts() as $batch) {
        $new->insert($batch);
    }
});

// Request handlers:
$read = new Index('/var/db/site-search.db', readonly: true);
```

The swap is atomic: requests in flight keep reading the old file, new requests
get the new one. A failed rebuild leaves the live index untouched. See
[Index files after a rebuild or snapshot](indexing.md#index-files-after-a-rebuild-or-snapshot)
for the file layout this creates.

## At larger sizes

Measured on a 533k-product catalog (the 44k test catalog copied twelve times with varied titles,
prices, years and brands; 3.8 GB index), Fuzor 2.0, one PHP process:

| Operation | 44k products | 533k products |
|---|---|---|
| Bulk `insert()` from a generator | 17 s, 138 MB peak | ~150 s, 136 MB peak |
| Search, one to three words (incl. typos) | 1–6 ms | 1–13 ms |
| Search + filter + sort + 6 facets | 6.4 ms | 8.6 ms |
| Browse page, sorted or filtered | 0.15–8 ms | 0.2–15 ms |
| Listing: 2 genders + 6 facets + sort | 13 ms (exact counts) | 23 ms |
| Facets of the whole index | 0.5 ms | 1.9 ms |
| Shop page: 5 facets, excluding 9% of products | 13 ms | 170 ms |
| `facetSearch()` with a filter only | 9.6 ms | 120 ms |
| `distinct` browse | 20–44 ms | 0.3–0.7 s |

Searches stay in single-digit milliseconds because each word is capped at `Config::$maxDocs`
best matches; their totals are then estimates (`exhaustive: false`). Facet counts over a filter
that matches more than `Config::$maxFacetCountDocs` (50,000) documents are approximate
(`approximateFacets`). Counting costs about 0.6–1 µs per counted document, whatever the number
of facet fields: each document's facet values are stored together as one packed row. Some
shapes grow with the index rather than the page: facet counts on a page whose only filter
excludes documents (`FacetExclude`, `-word`) cost about that much per excluded document,
`facetSearch()` with a filter but no query, and `distinct`. Keep them off hot paths on large
indexes, or cache their results.

A value filter that selects most documents of a field every document has exactly once (such as
`gender: [Women, Men]`) is counted through the remaining values when those hold at most
`maxFacetCountDocs` documents: its page then gets exact facet counts at the cost of the excluded
documents. On the 533k catalog the remaining 43k documents are above the cap, so the listing's
counts stay approximate there.

## Index files from another version

The on-disk format has a revision (`Index::CURRENT_SCHEMA_VERSION`, stored in each file and
exposed as `$index->schemaVersion`). Fuzor 2.0 writes revision 4 and does not open files
written by 1.x (revisions 1–3) or by a newer version: opening one throws a `QueryException`.
Recreate such an index from your source data with `new Index($path, schema: ..., force: true)`
and insert the documents again, or with `Index::rebuild($path, $callback, schema: ...)`, which
replaces it without opening it. `snapshotTo()` copies the file as it is, so a snapshot keeps
its source's revision.

## Returning less per hit

Every hit is fetched from the `documents` table and JSON-decoded. When the endpoint does
not need the stored bodies — an autocomplete that only renders titles, or a search that
hands IDs to another system — `attributesToRetrieve` skips that work:

```php
// IDs only: no documents SELECT, no json_decode. ~7% faster on a faceted request.
$ids = $read->search($query, new SearchOptions(attributesToRetrieve: []))->getIds();

// Or trim each hit to the fields the UI actually renders:
$results = $read->search($query, new SearchOptions(attributesToRetrieve: ['title', 'price']));
```

Trimming to a field list still hydrates and decodes each document, so it saves response
size rather than server time. The empty list is the one that removes work.

Filtering happens after highlighting and cropping, so a field can drive `_formatted`
without being returned itself:

```php
$results = $read->search($query, new SearchOptions(
    attributesToHighlight: ['body'],   // highlight computed from the full body
    attributesToRetrieve:  ['title'],  // but body is not in the response
));
```

## Writer maintenance: keeping the WAL bounded

In WAL mode every commit appends to a `-wal` sidecar, which SQLite folds back into the
database at an automatic checkpoint. That checkpoint can only reclaim space up to the
oldest *active reader* — so on an index that is being read continuously, automatic
checkpointing can be starved indefinitely and the `-wal` file grows without bound until
it dwarfs the index itself.

A long-running writer process should checkpoint on its own schedule:

```php
$stats = $write->checkpoint();          // TRUNCATE: empties the -wal file

// Or observe pressure without blocking on readers:
$stats = $write->checkpoint('PASSIVE');
if ($stats['checkpointed'] < $stats['log']) {
    // Readers are holding the checkpointer back — the WAL is still growing.
}
```

This is not a concern for the read/write split above: the read side never writes, and
snapshot files carry no WAL at all. It matters when one live file serves both reads and
writes, which is also where `busyTimeoutMs` and cross-process cache invalidation apply.

## Restoring from a snapshot

The snapshot is a plain SQLite file behind a symlink. To promote it to a write index — after data loss or a botched migration — stop every process that writes the old index, then install it at the write path and open normally:

```php
Index::install('/var/db/products-read.db', '/var/db/products.db', check: true);
$write = new Index('/var/db/products.db');
```

`install()` copies the file (following the symlink), checks it, and publishes it like a rebuild, so it never pairs the copy with the old file's `-wal`; see [Installing an index file](indexing.md#installing-an-index-file). Writers still have to be stopped first: one that keeps the old file open goes on writing to the old version. The `readonly` flag is a connection-mode choice, not a property of the file.

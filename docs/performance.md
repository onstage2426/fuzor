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

The rename is atomic: requests in flight keep reading the old file, new requests
get the new one. A failed rebuild leaves the live index untouched.

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

The snapshot is a plain SQLite file. To promote it to a write index — after data loss or a botched migration — rename it over the write path and open normally:

```php
rename('/var/db/products-read.db', '/var/db/products.db');
$write = new Index('/var/db/products.db');
```

No library involvement required. The `readonly` flag is a connection-mode choice, not a property of the file.

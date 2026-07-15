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

## Restoring from a snapshot

The snapshot is a plain SQLite file. To promote it to a write index — after data loss or a botched migration — rename it over the write path and open normally:

```php
rename('/var/db/products-read.db', '/var/db/products.db');
$write = new Index('/var/db/products.db');
```

No library involvement required. The `readonly` flag is a connection-mode choice, not a property of the file.

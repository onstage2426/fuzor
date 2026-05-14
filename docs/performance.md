# Read/write index split

Separating the write path from the search path eliminates write contention on search endpoints entirely.

## The pattern

Keep two files: a **write index** that absorbs all mutations, and a **read index** that is periodically refreshed from it via `snapshotTo()`. The search endpoint opens the read index in `readonly` mode and never touches the write index.

```
write index          snapshotTo()         read index
products.db  ──────────────────────►  products-read.db
insert/update/delete  (periodic)       search only
```

## Write side

```php
use Fuzor\Index;

$write = new Index('/var/db/products.db', language: 'en');

// Normal mutations
$write->upsertMany($updatedProducts);
$write->delete($removedId);

// Push a snapshot whenever your staleness budget allows —
// every minute, every N writes, on a cron, etc.
$write->snapshotTo('/var/db/products-read.db');
```

`snapshotTo()` uses SQLite's `VACUUM INTO` under a read transaction. WAL mode means writes to the write index are never blocked while the snapshot runs, even for a large index.

## Read side

```php
$read = new Index('/var/db/products-read.db', readonly: true);
$results = $read->search('electric bike');
```

Opening with `readonly: true`:
- Uses a read-only file descriptor — no lock acquisition per query
- Lets the OS share memory-mapped pages across all PHP-FPM workers for that file
- Skips WAL and checkpoint overhead entirely (the snapshot file has no WAL)

## Restoring from a snapshot

The snapshot is a plain SQLite file with no metadata tying it to its origin. To promote it back to a write index — after data loss, a botched migration, or any other reason — rename it over the write path and open normally:

```php
rename('/var/db/products-read.db', '/var/db/products.db');
$write = new Index('/var/db/products.db');
```

No library involvement needed. The `readonly` flag is a connection-mode choice, not a property of the file.

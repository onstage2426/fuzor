# Operations

Running an index in production: readers and writers, rebuilding, snapshots, moving an index
between servers, and the files on disk.

## Readers and writers

An index is a SQLite file in WAL mode. Any number of processes can search it while one writes, and
a commit is visible to the next search in every process, which notices it and drops its caches.
Writes from several processes take turns: a writer waits up to `Config::$busyTimeoutMs` (5,000) for
another's transaction, then throws a `\PDOException`.

That is enough for most sites: search and write the same file, from every PHP worker. The patterns
below help when writes are heavy or must never slow searches down.

### A separate read index

Write to one index and publish snapshots of it for the searches:

```php
// The writer: your application, a queue worker
$write = new Index('/var/db/products.db');
$write->upsert($changedProducts);
$write->delete(...$removedIds);
$write->snapshotTo('/var/db/products-read.db');  // as often as freshness requires

// The search endpoints
$read = new Index('/var/db/products-read.db', readonly: true);
$result = $read->search($query);
```

`snapshotTo()` copies the index under a read transaction, so writes go on while it runs. A
read-only connection takes no write locks and does no WAL bookkeeping, and every worker shares the
operating system's cached pages of the file.

### Long-lived workers

A `new Index()` per request opens the newest snapshot or rebuild by itself. A worker that keeps one
`Index` across requests (Swoole, RoadRunner, FrankenPHP worker mode) keeps reading the file it
opened; call `reopenIfChanged()` at the start of each request:

```php
$read = new Index('/var/db/products-read.db', readonly: true);  // at worker start

// per request
$read->reopenIfChanged();  // one stat(); reopens only when a new version was published
$result = $read->search($query);
```

Writes into the same file need no reopen; they are noticed anyway.

### Memory with many workers

SQLite's page cache belongs to each connection, so 32 workers with the default 64 MB cache can
hold 2 GB of copies of the same pages. Memory-mapped pages are shared by every process mapping the
file, so read workers do better with a small cache and a large map:

```php
$read = new Index('/var/db/products-read.db', readonly: true, config: new Config(
    cacheSizeKb:   8_192,          // 8 MB per worker (default 64 MB)
    mmapSizeBytes: 2_147_483_648,  // 2 GB, shared (default 512 MB)
));
```

Set `mmapSizeBytes` at or above the file size; it reserves address space, not memory. Keep the
default cache on the writer, where it absorbs the page splits of indexing.

### Keeping the WAL small

Every commit appends to the `-wal` file, which SQLite folds back into the database at a
checkpoint. A checkpoint cannot pass the oldest open read, so on an index that is read without
pause the `-wal` file can grow far beyond the index. A long-running writer should checkpoint on its
own schedule:

```php
$write->checkpoint();  // TRUNCATE: write everything back and empty the -wal file

$stats = $write->checkpoint('PASSIVE');  // without waiting for readers
if ($stats['checkpointed'] < $stats['log']) {
    // readers hold the checkpoint back
}
```

A separate read index does not have this problem: snapshots have no WAL.

## Rebuilding

`Index::rebuild()` builds a complete new index in a temporary file next to the old one, and then
switches the path to it atomically. Searches go on against the old index while it runs, and if
anything throws, the old index is left as it was.

```php
// From your own data
Index::rebuild('/var/db/products.db', function (Index $new): void {
    $new->insert(loadAllProducts());  // a generator
});

// From the index's own document store: no callback needed
Index::rebuild('/var/db/products.db');
```

Without a callback, the documents are streamed from the old index's store (`\InvalidArgumentException`
when it has none). `rebuild()` also purges deleted documents and recomputes every statistic.

### A new schema

By default the new index has the old one's schema. Pass a `SchemaConfig` to change it; it is used
as given, not merged, so include every setting you want to keep:

```php
Index::rebuild('/var/db/products.db', schema: new SchemaConfig(
    searchableAttributes: ['title', 'body'],
    filterableAttributes: ['brand', 'price', 'category'],
    sortableAttributes:   ['price'],
));
```

With a callback **and** a schema, `rebuild()` does not need to open the old file, so it also
replaces an index of another format: one written by Fuzor 1.x or by a newer version. That is the
way to upgrade in place:

```php
Index::rebuild($path, fn (Index $new) => $new->insert(loadAllProducts()), new SchemaConfig(/* … */));
```

Synonyms are copied from the old index before the callback runs, so the callback can read, extend
or replace them (not from a file of another format). After a change of stemmer they are stored as
the old stems: set them again in the callback.

```php
Index::rebuild('/var/db/articles.db',
    function (Index $new) use ($docs): void {
        $new->setSynonyms(equivalences: [['auto', 'automobil', 'fahrzeug']]);
        $new->insert($docs);
    },
    schema: new SchemaConfig(stemmer: \App\Search\GermanStemmer::class),
);
```

### Writes during a rebuild

The rebuild replaces the whole file, so a write made to the old index while it runs (an `upsert()`
from another request) is **lost** when the new file takes its place:

- **With a callback**, the new index holds what the callback read from your source: a change
  that reached the source before the callback read that record is in it, a later one is not.
- **Without a callback**, documents are copied from the old store in batches: an update to a
  document already copied is lost, and a document deleted after it was copied comes back.

If you combine writes on save with periodic rebuilds, either run both from the same queue or under
one lock, or replay the changes afterwards:

```php
$startedAt = time();
$index     = Index::rebuild($path, fn (Index $new) => $new->insert(loadAllProducts()));

foreach (productIdsChangedSince($startedAt) as $id) {
    $product = loadProduct($id);
    $product === null ? $index->delete($id) : $index->upsert([$product]);
}
```

`upsert()` and `delete()` are idempotent, so replaying a change the rebuild already has is harmless.

### Rebuilding instead of tracking writes

When changes come from too many places to hook each one (a CMS, imports), skip single writes and
rebuild on a schedule; the rebuilt file is the read index:

```php
// cron, every 15 minutes
Index::rebuild('/var/db/site-search.db', fn (Index $new) => $new->insert(loadAllPosts()));

// request handlers
$read = new Index('/var/db/site-search.db', readonly: true);
```

## Snapshots and moving indexes

`snapshotTo()` writes a point-in-time copy of an index to another path, published like a rebuild.
It only reads the source, and writes go on while it runs. Never write to a snapshot path yourself:
the next `snapshotTo()` replaces the file.

```php
$index->snapshotTo('/backup/products.db');
```

`Index::install()` puts an index file made elsewhere at a path, for example one built on another
server and copied over:

```php
Index::install('/tmp/received-products.db', '/var/db/products.db');
Index::install($file, $path, check: true);  // also run SQLite's integrity check
```

The file is copied next to the path, written to disk and checked before anything changes: it must
be a complete Fuzor index (a file cut short in transfer is refused) of a format this version opens.
`check: true` also reads the whole file with SQLite's `quick_check` (about 2 s for 300 MB). It is
then published like a rebuild, and the source file is left in place. A bad file throws and leaves
the index at the path as it was. Install snapshots, not live indexes: a file with a non-empty
`-wal` next to it holds commits that are not in the file yet, and is refused.

To restore a write index from a snapshot, stop the processes that write it, install the snapshot at
its path, and open it as usual:

```php
Index::install('/var/db/products-read.db', '/var/db/products.db', check: true);
$write = new Index('/var/db/products.db');
```

`readonly` is a way of opening a file, not a property of it.

## Index files and versions

`rebuild()`, `snapshotTo()`, `install()` and `new Index($path, force: true)` over an existing index
write each new file under a name of its own, `{path}.v-{8 hex}`, and turn `{path}` into a symlink
to it, swapped atomically:

```
products.db           -> products.db.v-3f9a01c2   (relative symlink)
products.db.v-3f9a01c2                            current
products.db.v-77d0e4b1                            previous, deleted by the next publish
```

Open `{path}` as always; `new Index()`, `exists()`, `lastModified()` and `reopenIfChanged()`
follow the link. Renaming a new file over the old one would not be safe: SQLite pairs a database
with its `-wal` by file name, so a process that still has the old file open could write its WAL
into the new one. With one name per version, two files never share a `-wal`.

What this means for your tooling:

- **Copy and back up** with a tool that follows symlinks (`cp`, PHP's `copy()`, `rsync -L`);
  `rsync -a` copies the link, not the data. Or use `snapshotTo()`.
- **Delete** with `Index::remove($path)`, not by unlinking the path, which leaves the version files
  behind. `remove()` deletes the link, every version with its `-wal` and `-shm`, and the leftovers of
  builds that no longer run, and returns the deleted paths. Stop the writers first.
- **Watch files** through `realpath($path)`: the `-wal` and `-shm` files are named after the
  version (`products.db.v-3f9a01c2-wal`). A cache validator (ETag, Last-Modified) built from
  `stat($path . '-wal')` finds no file and misses writes still in the WAL.
- **Move** the version file together with the link, since the link is relative, or copy instead.
- On a filesystem without symlinks (Windows without the privilege, some network mounts), the new
  file is renamed over `{path}`, which is only safe when no other process writes the old one.

`Index::lastModified($path)` returns the file's modification time, which changes at each
checkpoint and publish, without opening a connection.

### Temporary files

A rebuild or snapshot builds into `{path}.tmp-{8 hex}` and removes it when it fails. A process
killed during the build (time limit, out of memory, a container restart) leaves it behind. Every
rebuild and snapshot sweeps such leftovers first, and you can sweep them yourself:

```php
$deleted = Index::cleanupTempFiles('/var/db/products.db');  // the removed paths
```

A build that is still running, in any process, is never touched: each holds a lock on
`{tmp}.lock`, which the operating system releases when the process dies, and a group of files is
only deleted when its lock is free and its newest file is older than `$minAgeSeconds` (one hour by
default).

### Files from another version

The file format has a revision, stored in the file (`$index->schemaVersion`, 4 in Fuzor 2.x).
Fuzor 2 does not open files written by 1.x or by a newer version; opening one throws a
`QueryException`. Recreate it from your data with `rebuild()` with a callback and a schema (above),
or with `new Index($path, schema: …, force: true)` and an `insert()`. A snapshot keeps its source's
revision.

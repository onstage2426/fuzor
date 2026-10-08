# Indexing

## Creating and opening an index

Pass a path to the constructor. If the file exists it is opened; if it does not, a new index is created.

```php
use Fuzor\Index;

$index = new Index('/path/to/articles.db');
```

### Parameters

Pass `force: true` to overwrite an existing index file:

```php
$index = new Index('/path/to/articles.db', force: true);
```

The empty index is published the same way as a rebuild, so processes that still have the old file open are not affected: see [Index files after a rebuild or snapshot](#index-files-after-a-rebuild-or-snapshot).

Pass a BCP 47 `language` tag to enable stopword filtering and stemming at creation time:

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/articles.db', schema: new SchemaConfig(language: 'en'));
```

Pass `readonly: true` to open an existing index in read-only mode. All write methods throw `IOException`; searches work normally:

```php
$index = new Index('/path/to/articles-read.db', readonly: true);
```

The document store is enabled by default: raw documents are stored as JSON inside the same SQLite file and search results are automatically hydrated. Pass `store: false` inside a `SchemaConfig` to opt out. See [document-store.md](document-store.md) for details.

```php
use Fuzor\SchemaConfig;

// Store off — smaller file, no document retrieval
$index = new Index('/path/to/articles.db', schema: new SchemaConfig(store: false));
```

The facet index is always enabled. Declare at creation time which fields can be used to filter (`filterableFields`: `filter`, `facets`, `distinct`, `facetSearch()`) and which to sort by (`sortableFields`). A field may be in both lists. Their values are stored in a separate index table; using any other field in those options throws a `QueryException`. See [search.md](search.md) for querying and filtering by facets.

By default every field except `id` and the filterable and sortable fields is tokenised for full-text. Pass `searchableFields` to restrict FTS to an explicit list of fields — any field not in either list is stored but not indexed.

```php
use Fuzor\SchemaConfig;

// Watches index: four filterable fields, two of them also sortable, two searchable fields;
// image_url and sku are stored-only automatically.
$index = new Index('/path/to/watches.db', schema: new SchemaConfig(
    filterableFields: ['brand', 'price', 'category', 'gender'],
    sortableFields:   ['price', 'brand'],
    searchableFields: ['title', 'body'],
));
```

Pass `stripHtml: true` when documents contain HTML markup. Each field value is converted to the text a reader would see before tokenisation, so tag names, attributes, and entities never enter the FTS index:

- block-level tags (`<p>`, `<div>`, `<li>`, `<td>`, `<h1>`–`<h6>`, `<br>`, …) separate words — `<p>foo</p><p>bar</p>` indexes `foo` and `bar`, not `foobar`;
- inline tags (`<b>`, `<a>`, `<span>`, …) are removed without a separator, so `fo<b>o</b>` stays one word;
- the contents of `<script>`, `<style>`, `<template>`, and `<noscript>` are dropped;
- entities are decoded — `Fit &amp; Flare caf&eacute;` indexes `fit`, `flare`, `café`, not `amp` or `eacute`.

The raw HTML is still stored unchanged in the document store, so hits, `get()`, and `stream()` return HTML. To store and return the text instead, convert the fields before inserting — see [Storing text instead of HTML](#storing-text-instead-of-html). Ignored when opening an existing index.

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/articles.db', schema: new SchemaConfig(stripHtml: true));
```

Pass a `Config` object to tune BM25, typo tolerance, and other search behaviour. See [tuning.md](tuning.md) for details.

```php
use Fuzor\Config;

$index = new Index('/path/to/articles.db', config: new Config(maxDocs: 200));
```

### Schema

Schema settings control how the index is structured at creation time. They are persisted inside the index file and cannot be changed without rebuilding. All schema settings are grouped in a `SchemaConfig` value object and passed as the `schema:` named argument.

Passing `schema:` when opening an existing file throws `QueryException`. Use `Index::rebuild()` to change the schema without losing data, or `force: true` to overwrite the file entirely.

| `SchemaConfig` property | Default | Effect |
|-------------------------|---------|--------|
| `language` | `null` | BCP 47 language tag; `null` disables stopwords and stemming |
| `store` | `true` | Enable the document store |
| `filterableFields` | `[]` | Fields usable in `filter`, `facets`, `distinct`, and `facetSearch()` |
| `sortableFields` | `[]` | Fields usable in `sort` |
| `searchableFields` | `null` | Fields tokenised for FTS; `null` = all fields that are neither filterable nor sortable |
| `stripHtml` | `false` | Convert HTML field values to text before tokenisation |

## Inserting

Every document must have a unique integer `id` field; all other fields are indexed as full-text.

`insert()` always takes a list of document arrays. Wrap a single document in an outer array. A single-element list uses an internal fast path; larger batches use a two-phase bulk load in one transaction — significantly faster than looping.

Throws if any ID already exists. Use `update()` to replace an existing document, or `upsert()` for create-or-replace semantics.

```php
// One document
$index->insert([
    ['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.'],
]);

// Multiple documents
$index->insert([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions, instant torque, sporty design.'],
]);

// Generator
$index->insert((function () use ($db) {
    foreach ($db->query('SELECT id, title, body FROM articles') as $row) {
        yield $row;
    }
})());
```

A multi-document `insert()` works through the documents in chunks of `Config::$insertChunkSize` (default 2,000): each chunk is tokenised and written before the next one is read, and all chunks commit in one transaction. Memory therefore follows the chunk, not the input, when you pass a generator: on a 44k-product catalog with HTML descriptions, a single `insert()` peaks at 127 MB (it used to load everything and peak at 2 GB), and `rebuild()` at about 150 MB. An array you pass is already in memory, of course. Lower the chunk size under a tight `memory_limit`; larger chunks did not load faster in our measurements.

```php
$index = new Index($path, config: new Config(insertChunkSize: 500));
```

If any document is invalid (no `id`, an `id` repeated in the input or already in the index), the insert throws and nothing is written, also when the problem is in a later chunk.

Pass a `progress` callback to track indexing progress. It is called after each document is tokenised, with the number of documents done and the total. The total is `0` when the input is a generator or another `Traversable` that is not `Countable`, since it is not read ahead:

```php
$index->insert($docs, progress: function (int $done, int $total): void {
    echo $total > 0 ? "$done / $total\n" : "$done\n";
});
```

### Durability during bulk loads

Multi-document `insert()`/`update()`/`upsert()` calls run with `PRAGMA synchronous=OFF` for speed (`Config::$bulkSynchronousOff`, default `true`). This is safe if the PHP process itself crashes mid-load — the transaction rolls back and the index is left exactly as it was before the call. It is **not** safe against an OS crash or power loss during the write: SQLite's own documentation notes that `synchronous=OFF` can leave the database file itself corrupted in that case, not just roll back the in-progress transaction.

Two ways to avoid that risk:

- **Prefer `Index::rebuild()`** for loading into a live, already-serving index. It writes to a disposable temp file and only swaps it in at the real path on success — a corrupted temp file from a power loss never touches the index readers are using.
- **Set `Config::$bulkSynchronousOff = false`** if you bulk-load directly into a live index outside of `rebuild()` and want full durability, at the cost of bulk-load speed.

### Facet values

Fields listed in `filterableFields` or `sortableFields` at creation time are automatically routed to the facet index. They are never tokenised for full-text — `search()` and `searchBoolean()` will not match against their contents — unless the field is also listed in `searchableFields`.

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/watches.db', schema: new SchemaConfig(
    filterableFields: ['brand', 'gender', 'category', 'price'],
    sortableFields:   ['price'],
));

$index->insert([
    [
        'id'       => 1,
        'title'    => 'Casio G-Shock GA-2100',
        'body'     => 'Shock resistant, 200m water resistant.',
        'brand'    => 'Casio',
        'gender'   => ['men', 'unisex'],  // array = multi-value facet
        'category' => 'Watches',
        'price'    => 129.99,             // int/float = numeric facet
    ],
    [
        'id'       => 2,
        'title'    => 'Seiko Presage',
        'body'     => 'Automatic mechanical movement.',
        'brand'    => 'Seiko',
        'gender'   => 'men',
        'category' => 'Watches',
        'price'    => 295.00,
    ],
]);
```

| Value type | Example | Behaviour |
|------------|---------|-----------|
| String | `'brand' => 'Casio'` | Exact-match string facet |
| Array of strings | `'gender' => ['men', 'unisex']` | Multi-value; contributes one count per value |
| Integer or float | `'price' => 129.99` | Numeric facet; per-value counts in `$facetDistribution` (stringified keys) + min/max in `$facetStats` |

Documents that omit a filterable or sortable field are indexed normally for full-text but contribute nothing to the facet index for that field.

### Stored-only fields

A field that is in none of `filterableFields`, `sortableFields`, and `searchableFields` (when `searchableFields` is set) is stored in the document store but never indexed — neither for full-text nor for facets. This is the right place for URLs, image paths, internal SKUs, and timestamps you want to retrieve but not search on.

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/watches.db', schema: new SchemaConfig(
    filterableFields: ['brand', 'price'],
    searchableFields: ['title', 'body'],
    // image_url and sku are stored-only automatically
));

$index->insert([[
    'id'        => 1,
    'title'     => 'Casio G-Shock GA-2100',
    'body'      => 'Shock resistant.',
    'brand'     => 'Casio',
    'price'     => 129.99,
    'image_url' => 'https://example.com/ga2100.jpg',
    'sku'       => 'GA-2100-1A1ER',
]]);
```

### Storing text instead of HTML

`stripHtml` only changes what is indexed; the document store keeps the HTML. When hits should carry the visible text, convert those fields yourself with `HtmlText::toText()` — the conversion `stripHtml` uses — and leave `stripHtml` off. Keep the original in a stored-only field if you still need it:

```php
use Fuzor\HtmlText;
use Fuzor\SchemaConfig;

$index = new Index('/path/to/posts.db', schema: new SchemaConfig(
    searchableFields: ['title', 'content'], // content_html is stored-only
));

$index->insert([[
    'id'           => 1,
    'title'        => HtmlText::toText($post['title']),
    'content'      => HtmlText::toText($post['content']),
    'content_html' => $post['content'],
]]);
```

Hits, `get()`, and `stream()` then return text, and `_formatted` highlights and crops that text directly; with `escapeFormatted` it only needs escaping.

- **Convert once, from the original HTML.** The conversion decodes entities, so it is not idempotent: `use the &lt;b&gt; tag` becomes `use the <b> tag`, and a second pass would strip that `<b>` as a tag and leave `use the tag`. Never run stored text through it again — for example when you `get()` a document, change it, and `update()` it.
- **Do not combine it with `stripHtml`** on the same field. `stripHtml` converts again at index time, with the same loss in the index.
- **The result is plain text, not HTML.** Escape it before rendering it into a page.

## Updating

Replaces existing documents. Old index data is removed and each document is re-indexed in a single transaction. All IDs are checked for existence before any writes — the transaction is never partially applied. Throws `QueryException` if any ID does not exist — use `upsert()` for create-or-replace semantics.

With the document store enabled (the default), a document whose searchable fields are unchanged — the same values, of the same type, in the same order — keeps its word index entries: only the stored document and its filterable / sortable values are rewritten. A price sync of 200 products on a 44k catalog takes 46 ms instead of 360 ms this way. `upsert()` does the same. Without a store there is nothing to compare with, so every document is re-indexed.

```php
use Fuzor\Exceptions\QueryException;

$index->update([
    ['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.'],
]);

// Bulk — throws QueryException listing all missing IDs upfront
$index->update([
    ['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.'],
    ['id' => 2, 'title' => 'Updated SUV',   'body' => 'More new content.'],
]);
```

## Upserting

Creates each document if its ID does not exist; replaces it if it does.

```php
$index->upsert([
    ['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.'],
]);

$index->upsert([
    ['id' => 1, 'title' => 'Fast sedan',   'body' => 'Comfortable city car.'],
    ['id' => 4, 'title' => 'New document', 'body' => 'Did not exist before.'],
]);
```

## Deleting

Removes one or more documents and updates the document count. No-op for IDs that do not exist.

```php
$index->delete(1);          // single
$index->delete(1, 2, 3);    // multiple (variadic)
```

A deleted document is gone from every search, browse, facet count, `get()`, and `count()` as
soon as `delete()` returns. Its rows in the word index are only marked deleted and removed
later, in bulk: rewriting them costs far more than the rest of the delete (on a 44k-product
catalog, 50 documents: ~13 ms instead of 250–700 ms, and a 0.5 ms single delete instead of
3.4 ms). Until they are removed, the per-word document counts BM25 uses still include the
deleted documents, which can reorder close results slightly (with 10% of a catalog deleted, the
first hit was unchanged and 97% of top-20 sets kept the same documents), and searches pay a small
extra filter (0.1–1.3 ms).

They are removed:

- by `delete()` itself, once deleted documents make up 10% of the index (that call takes longer:
  ~1.4 s for 4,000 documents on the catalog above);
- by `optimize()`, which returns the number of documents it purged;
- by `rebuild()` and `clear()`;
- one at a time when a deleted ID is inserted or upserted again.

```php
$index->delete(...$discontinued);
$index->optimize();   // exact word statistics again, e.g. after a large cleanup
```

### Deleting by filter

`deleteByFilter()` removes every document that matches a filter and returns how many it removed. The filter has the same shape and meaning as `SearchOptions::$filter` (values, lists, `FacetRange`, `FacetExclude`, combined with AND) and may name filterable fields only. Deleted documents go the same way as with `delete()`.

```php
$removed = $index->deleteByFilter(['brand' => 'Acme']);
$index->deleteByFilter(['status' => 'discontinued', 'stock' => new FacetRange(lte: 0)]);
```

An empty filter throws an `\InvalidArgumentException`; use `clear()` to remove everything. A filter of only exclusions keeps its search meaning: `['brand' => new FacetExclude('Acme')]` deletes every document that is not Acme. On a 44k-product catalog, deleting one brand takes 9 ms for 57 products and 120 ms for 2,203.

## Check existence

`has()` checks a single ID and returns `bool`. `hasMany()` checks multiple IDs and returns an `id => bool` map.

```php
$index->has(1);                // bool
$index->hasMany(1, 2, 3);      // [1 => true, 2 => false, 3 => true]
$index->hasMany();             // []
```

## Document count

Returns the total number of indexed documents. Reads from the cached `info` table — no extra database round-trip if a search or write has already warmed the cache.

```php
$index->count(); // int
```

## Streaming all documents

Iterates over every document in the store in ascending `id` order. Returns a generator that yields `doc_id => document` pairs one at a time. Requires the document store to be enabled.

```php
foreach ($index->stream() as $id => $doc) {
    echo $id . ': ' . $doc['title'] . "\n";
}
```

`$batchSize` controls how many rows are fetched from SQLite per round-trip (default `100`). Increase it when throughput matters more than memory:

```php
foreach ($index->stream(batchSize: 500) as $id => $doc) {
    // ...
}
```

The cursor uses `WHERE doc_id > :last LIMIT :n` against the clustered primary key, so each fetch is O(1) regardless of how deep into the dataset you are.

Throws `QueryException` if the document store is not enabled. Throws `\InvalidArgumentException` if `$batchSize` is less than 1.

## Last modified

Returns a Unix timestamp reflecting the most recent write to the index. Reads the main database file's mtime — updated on each WAL checkpoint — no database connection required. Returns `0` if the file does not exist.

```php
Index::lastModified('/path/to/articles.db'); // int — Unix timestamp
```

## Snapshots

Writes an atomic point-in-time copy of the index to a new path. Safe to call while writes are in progress.

```php
$index->snapshotTo('/path/to/articles-snapshot.db');
```

The snapshot is published the same way as a rebuild: see [Index files after a rebuild or snapshot](#index-files-after-a-rebuild-or-snapshot).

## Installing an index file

`Index::install()` puts an index file made elsewhere at a path, for example a snapshot built on another server and transferred to this one:

```php
Index::install('/tmp/received-articles.db', '/var/db/articles.db');
Index::install($file, $path, check: true);   // also run SQLite's integrity check
```

The file is copied next to the path under a temporary name, written to disk, and checked before anything changes: it must be a complete Fuzor index (a file cut short in transfer is rejected) of a schema revision this version opens. With `check: true` it must also pass SQLite's `quick_check`, which reads the whole file (about 2 s for 300 MB, against 0.4 s for the copy). It is then published like a rebuild, so processes that have the old index open keep reading it until they reopen. The source file is left in place. A bad file throws (`QueryException`, or `IOException` when it cannot be read) and leaves the index at the path untouched.

Install snapshots, not live indexes: a file with a non-empty `-wal` next to it holds commits that are not in the file yet and is refused. Use `snapshotTo()` to copy an index that is in use.

## Atomic rebuild

Replaces the entire contents of an index in one atomic operation. If anything throws, the original file is left completely untouched.

Pass a callback to populate the new index yourself:

```php
Index::rebuild('/path/to/articles.db', function (Index $new) use ($docs) {
    $new->insert($docs);
});
```

When the existing index has the **document store enabled**, the callback can be omitted — all stored documents are streamed into the new index automatically. This lets you re-index with a different schema without maintaining a separate copy of the source data:

```php
// Re-index with the same schema — no callback, no external data needed
Index::rebuild('/path/to/articles.db');

// Re-index with new filterable and sortable fields
use Fuzor\SchemaConfig;

Index::rebuild('/path/to/articles.db', schema: new SchemaConfig(
    language:         'en',
    filterableFields: ['brand', 'price', 'category'],
    sortableFields:   ['price'],
    searchableFields: ['title', 'body'],
));
```

Throws `\InvalidArgumentException` if the callback is omitted and the existing index has no document store.

Internally, `rebuild` writes to a temporary file alongside the target, then publishes it with an atomic swap — see below.

### Index files after a rebuild or snapshot

`rebuild()`, `snapshotTo()`, `Index::install()`, and `new Index($path, force: true)` over an existing index publish each new file under a name of its own, `{path}.v-{8 hex}`, and turn `{path}` into a symlink to it, swapped atomically:

```
articles.db          -> articles.db.v-3f9a01c2      (symlink, relative)
articles.db.v-3f9a01c2                              current
articles.db.v-77d0e4b1                              previous, kept until the next publish
```

Open `{path}` as always; `new Index()`, `Index::exists()`, `lastModified()`, and `reopenIfChanged()` all follow the link. The previous version stays for connections that are still opening it and is deleted by the next publish, together with its `-wal`/`-shm`.

Replacing the file itself (a `rename()` over the path) is not safe while another process has the old file open read-write: SQLite pairs a database with its `-wal` by name, so the new file would be read through the old file's write-ahead log — including commits made through the old connection after the swap. With one file name per version, two files never share a `-wal`.

Things to know about the layout:

- Copy or back up `{path}` with a tool that follows symlinks (`cp`, `copy()`, `rsync -L`); `rsync -a` alone copies the link, not the data.
- Moving `{path}` to another directory breaks the relative link; move the version file with it, or copy instead.
- On a filesystem without symlinks (Windows without the privilege, some network mounts), both methods fall back to renaming the new file over `{path}` and deleting its sidecars, which is only safe when no other process writes the old file.

### Writes during a rebuild

A rebuild replaces the whole file, so any write made to the index at that path while the rebuild runs — `insert()`, `update()`, `upsert()`, `delete()` from another request or worker — is **lost** when the new file takes its place:

- **With a callback**, the new index contains what your callback read from your source. A change that reached the source before the callback read that record is included; a change made after it is not, even though it was written to the old index.
- **Without a callback**, documents are streamed from the old index's store in batches. An update to a document that was already copied is lost, and a document deleted after it was copied comes back.

If you combine live writes with periodic rebuilds (for example updates on save plus a nightly rebuild), use one of these:

- **Serialize them.** Run writes and rebuilds from the same queue or worker, or guard both with a lock of your own, so no write happens while a rebuild runs.
- **Replay afterwards.** Record which IDs change while the rebuild runs and re-apply them to the new index once `rebuild()` returns:

```php
$startedAt = time();
$index     = Index::rebuild($path, fn (Index $new) => $new->insert(loadAllProducts()));

// Anything written to the old index during the rebuild: apply it again.
foreach (productIdsChangedSince($startedAt) as $id) {
    $product = loadProduct($id);
    $product === null ? $index->delete($id) : $index->upsert([$product]);
}
```

`upsert()` and `delete()` are idempotent, so replaying a change the rebuild already picked up is harmless.

`snapshotTo()` has no such problem for the source index — it only reads it — but the snapshot is a point-in-time copy. Never write to a snapshot path directly: the next `snapshotTo()` replaces the file and those writes disappear.

### Temporary files

`rebuild()` and `snapshotTo()` build into `{path}.tmp-{8 hex}` (plus SQLite's `-wal` / `-shm` sidecars) next to the target, then publish it as `{path}.v-{8 hex}`, or remove it when they fail. A process that is killed mid-build — `max_execution_time`, out of memory, a PHP-FPM or container restart — never reaches that cleanup, so its files stay behind.

Both methods sweep such leftovers before they start. You can also do it from your own tooling, for example a cron job or an admin screen:

```php
$deleted = Index::cleanupTempFiles('/path/to/articles.db'); // list<string> of removed paths
```

The sweep never touches a build that is still running, in this process or another one. Each build holds an `flock()` on `{tmp}.lock` for its whole run, and the operating system releases that lock when the process dies. A group of temp files is only deleted when its lock is free **and** its newest file is older than `$minAgeSeconds` (default one hour). The age check covers leftovers from before 1.6.0, which have no lock file. Pass `0` to ignore age once you know nothing is running. Only names of exactly this shape are considered; other files next to the index are left alone.

### Synonyms and rebuild

Synonyms are copied from the existing index into the rebuilt one automatically. They are seeded before the callback runs, so the callback can inspect, extend, or replace them:

```php
Index::rebuild('/path/to/articles.db', function (Index $new) use ($docs) {
    $new->insert($docs);
    // Synonyms from the old index are already present; add more if needed.
    $new->setSynonyms(equivalences: [['car', 'automobile', 'vehicle']]);
});
```

If you rebuild with a different language, the copied synonyms are stored as stems from the old language and will no longer match anything. Clear and reconfigure them inside the callback:

```php
Index::rebuild('/path/to/articles.db',
    function (Index $new) use ($docs) {
        $new->clearSynonyms();
        $new->setSynonyms(equivalences: [['auto', 'automobil', 'fahrzeug']]);
        $new->insert($docs);
    },
    schema: new SchemaConfig(language: 'de'),
);
```

### Overriding the schema

`rebuild()` inherits the full schema from the existing index by default. Pass a `SchemaConfig` to use different settings in the rebuilt index. The object is used as-is — not merged with the existing index — so include every setting you want to keep. See [Schema](#schema) for the full parameter reference.

```php
use Fuzor\SchemaConfig;

// Switch to German
Index::rebuild('/path/to/articles.db',
    fn (Index $new) => $new->insert($docs),
    schema: new SchemaConfig(language: 'de'),
);

// Change searchable fields; turn the store off to shrink the rebuilt file
Index::rebuild('/path/to/articles.db',
    fn (Index $new) => $new->insert($docs),
    schema: new SchemaConfig(
        searchableFields: ['title', 'body'],
        store: false,
    ),
);
```

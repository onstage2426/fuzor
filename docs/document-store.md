# Document Store

The document store is an optional feature that persists the raw document array inside the same SQLite file as the inverted index. When enabled, search results are automatically hydrated — `$result->documents()` returns the full document data without any extra query to a separate database.

## Enabling

Pass `store: true` at index creation time:

```php
use Fuzor\Index;

$index = new Index('/path/to/articles.db', store: true);
```

The setting is persisted in the `info` table of the index file. Opening an existing index restores it automatically — you never need to re-specify it.

## Auto-hydration on search

When the store is enabled, every `search()` and `searchBoolean()` call automatically attaches the matching documents to the result:

```php
$results = $index->search('electric bike');

// array<int, array<string, mixed>> keyed by doc ID, in relevance order
$results->documents();

// The full document array for doc 3, or null if not in the result
$results->document(3); // ['id' => 3, 'title' => 'Electric bike', 'body' => '…']

// Check whether the store is active on this result
$results->hasDocuments(); // true
```

When the store is disabled, `$results->documents()` returns `null` and `$results->hasDocuments()` returns `false`. An empty result page (store enabled, no matches) returns an empty array `[]` from `documents()`, not `null`.

## Fetching documents by ID

Retrieve documents directly without a search:

```php
// Single document — returns null if not found
$doc = $index->get(42);

// Multiple documents — returns map<int, array>; missing IDs are silently omitted
$docs = $index->getMany([1, 2, 3]);
$docs[1]; // ['id' => 1, 'title' => '…', …]
```

Both methods throw `QueryException` if called on an index where the store was not enabled.

## Checking whether the store is active

```php
$index->documentStoreEnabled; // bool
```

## Storage format

Documents are stored as JSON (UTF-8) in a `documents` table in the same SQLite file as the index. Values are decoded with `json_decode($data, true)` on retrieval. Plain PHP arrays with scalar values round-trip perfectly. Nested objects will be returned as arrays — use only array-typed fields if round-trip fidelity matters.

## Atomic rebuild

`rebuild()` inherits the store setting from the existing index by default. Pass `store` to override:

| `$store` value | Effect |
|----------------|--------|
| `null` (default) | Inherit from the existing index |
| `true` | Enable the store in the rebuilt index |
| `false` | Disable the store in the rebuilt index |

```php
// Inherit (default) — store stays on if the existing index had it on
Index::rebuild('/path/to/articles.db', function (Index $new) use ($docs) {
    $new->insertMany($docs);
});

// Force the store on even if the existing index had it off
Index::rebuild('/path/to/articles.db', callback: $fn, store: true);
```

## Performance notes

- **Write overhead** — each `insert()` / `upsert()` / `update()` adds one `INSERT INTO documents`. Bulk operations (`insertMany`, `upsertMany`) batch these at 500 rows per statement, which is conservative for large JSON payloads.
- **Read overhead** — `documents()` triggers one chunked `SELECT` on the `documents` PK after scoring. For a typical `limit: 100` page this is a single indexed query.
- **File size** — the `documents` table adds roughly the size of `json_encode($doc)` per document to the SQLite file.
- **Snapshots** — `snapshotTo()` copies the entire SQLite file including the `documents` table. No extra step needed.

# Document Store

The document store persists the raw document array inside the same SQLite file as the inverted index. Search results are automatically hydrated — `$result->documents()` returns the full document data without any extra query to a separate database.

The store is **enabled by default**. Pass `store: false` to opt out — useful for embedding contexts where disk space is constrained or raw document retrieval is not needed.

Even if you never call `get()` or use `$result->documents()`, keeping the store on lets you use `Index::rebuild()` without a callback: the stored documents are streamed into the new index automatically, so you can re-index with a different schema at any time without maintaining a separate copy of your source data. See [indexing.md § Atomic rebuild](indexing.md#atomic-rebuild) for details.

```php
use Fuzor\Index;

// Store on (default)
$index = new Index('/path/to/articles.db');

// Store off
$index = new Index('/path/to/articles.db', store: false);
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

## Stored-only fields

Any field that is not in `facetFields` and not in `searchableFields` (when `searchableFields` is declared) is indexed in neither the FTS nor the facet index. It is stored as-is in the document store and returned unchanged by `document()`, `documents()`, and `get()`.

Use this for data you need at retrieval time — permalinks, image URLs, timestamps, internal SKUs — but do not want to appear in search results or facet filters.

```php
$index = new Index('/path/to/articles.db',
    searchableFields: ['title', 'body'],
    // permalink, image, published are stored-only automatically
);

$index->insert([[
    'id'        => 1,
    'title'     => 'Fast sedan',
    'body'      => 'Comfortable city car.',
    'permalink' => '/cars/fast-sedan',
    'image'     => 'https://example.com/sedan.jpg',
    'published' => '2026-05-14',
]]);
```

Stored-only fields are returned alongside the rest of the document:

```php
$index->search('sedan')->document(1)['permalink']; // '/cars/fast-sedan'
```

Stored-only fields are updated atomically with the rest of the document on `update()` and `upsert()`.

## Fetching documents by ID

Retrieve documents directly without a search:

```php
// Single document — returns null if not found
$doc = $index->get(42);

// Multiple documents — returns map<int, array>; missing IDs are silently omitted
$docs = $index->getMany(1, 2, 3);
$docs[1]; // ['id' => 1, 'title' => '…', …]
```

Both throw `QueryException` if called on an index where the store was not enabled.

## Checking whether the store is active

```php
$index->documentStoreEnabled; // bool
```

## Storage format

Documents are stored as JSON (UTF-8) in a `documents` table in the same SQLite file as the index. Values are decoded with `json_decode($data, true)` on retrieval. Plain PHP arrays with scalar values round-trip perfectly. Nested objects will be returned as arrays — use only array-typed fields if round-trip fidelity matters.

## Atomic rebuild

`rebuild()` inherits the store setting from the existing index by default. Pass a `SchemaConfig` with an explicit `store` value to override it in the rebuilt index:

```php
use Fuzor\SchemaConfig;

// Inherit (default) — store stays on if the existing index had it on
Index::rebuild('/path/to/articles.db', function (Index $new) use ($docs) {
    $new->insert($docs);
});

// Force the store off to shrink the rebuilt file
Index::rebuild('/path/to/articles.db',
    fn (Index $new) => $new->insert($docs),
    schema: new SchemaConfig(store: false),
);
```

See [indexing.md § Atomic rebuild](indexing.md#atomic-rebuild) for full details including the no-callback form.

## Performance notes

- **Write overhead** — each `insert()` / `upsert()` / `update()` adds one `INSERT INTO documents`. Bulk inserts and upserts batch these at 500 rows per statement, which is conservative for large JSON payloads.
- **Read overhead** — `documents()` triggers one chunked `SELECT` on the `documents` PK after scoring. For a typical `limit: 100` page this is a single indexed query.
- **File size** — the `documents` table adds roughly the size of `json_encode($doc)` per document to the SQLite file.
- **Snapshots** — `snapshotTo()` copies the entire SQLite file including the `documents` table. No extra step needed.

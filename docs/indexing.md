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

Pass a BCP 47 `language` tag to enable stopword filtering and stemming at creation time:

```php
$index = new Index('/path/to/articles.db', language: 'en');
```

Pass `readonly: true` to open an existing index in read-only mode. All write methods throw `IOException`; searches work normally:

```php
$index = new Index('/path/to/articles-read.db', readonly: true);
```

The document store is enabled by default: raw documents are stored as JSON inside the same SQLite file and search results are automatically hydrated. Pass `store: false` to opt out. See [document-store.md](document-store.md) for details.

```php
// Store off — smaller file, no document retrieval
$index = new Index('/path/to/articles.db', store: false);
```

The facet index is always enabled. Facet attribute values are stored in a separate index table and can be used to filter results and compute per-value counts at search time. See [search.md](search.md) for querying and filtering by facets.

Pass a `Config` object to tune BM25, typo tolerance, and other search behaviour. See [configuration.md](configuration.md) for details.

```php
use Fuzor\Config;

$index = new Index('/path/to/articles.db', config: new Config(maxDocs: 200));
```

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

Pass a `progress` callback to track indexing progress. It is called after each document is tokenised, with the number of documents done and the total:

```php
$index->insert($docs, progress: function (int $done, int $total): void {
    echo "$done / $total\n";
});
```

### Facet values

Add a `_facets` key to a document to supply attribute values for the facet index. The `_facets` key is never tokenised for full-text — `search()` and `searchBoolean()` will not match against its contents.

```php
$index->insert([
    [
        'id'      => 1,
        'title'   => 'Casio G-Shock GA-2100',
        'body'    => 'Shock resistant, 200m water resistant.',
        '_facets' => [
            'brand'    => 'Casio',
            'gender'   => ['men', 'unisex'],  // array = multi-value facet
            'category' => 'Watches',
            'price'    => 129.99,             // int/float = numeric facet
        ],
    ],
    [
        'id'      => 2,
        'title'   => 'Seiko Presage',
        'body'    => 'Automatic mechanical movement.',
        '_facets' => [
            'brand'    => 'Seiko',
            'gender'   => 'men',
            'category' => 'Watches',
            'price'    => 295.00,
        ],
    ],
]);
```

| Value type | Example | Behaviour |
|------------|---------|-----------|
| String | `'brand' => 'Casio'` | Exact-match string facet |
| Array of strings | `'gender' => ['men', 'unisex']` | Multi-value; contributes one count per value |
| Integer or float | `'price' => 129.99` | Numeric facet; aggregated as min/max/count at search time |

Documents without a `_facets` key are indexed normally for full-text but contribute nothing to the facet index.

## Updating

Replaces existing documents. Old index data is removed and each document is re-indexed in a single transaction. All IDs are checked for existence before any writes — the transaction is never partially applied. Throws `QueryException` if any ID does not exist — use `upsert()` for create-or-replace semantics.

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

Removes one or more documents from all index tables and updates the document count. No-op for IDs that do not exist.

```php
$index->delete(1);          // single
$index->delete(1, 2, 3);    // multiple (variadic)
```

## Check existence

Returns `true`/`false` for a single ID, or a `id => bool` map for multiple IDs.

```php
$index->has(1);          // bool
$index->has(1, 2, 3);    // [1 => true, 2 => false, 3 => true]
```

## Document count

Returns the total number of indexed documents. Reads from the cached `info` table — no extra database round-trip if a search or write has already warmed the cache.

```php
$index->count(); // int
```

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

## Atomic rebuild

Replaces the entire contents of an index in one atomic operation. The callback receives a fresh, empty handle to populate; if it throws, the original file is left completely untouched.

```php
Index::rebuild('/path/to/articles.db', function (Index $new) use ($docs) {
    $new->insert($docs);
});
```

Internally, `rebuild` writes to a temporary file alongside the target, then renames it over the original — a POSIX-atomic operation on the same filesystem.

### Language on rebuild

The `language` argument controls which language the rebuilt index uses:

| Value | Effect |
|-------|--------|
| *(omitted)* / `false` | Inherit the language from the existing index (default) |
| `null` | Build with no language, regardless of what the existing index has |
| `'en'`, `'de'`, … | Use this BCP 47 tag, overriding the existing index |

```php
// Inherit (default) — tokenisation stays consistent without extra config
Index::rebuild('/path/to/articles.db', callback: fn (Index $new) => $new->insert($docs));

// Clear language
Index::rebuild('/path/to/articles.db', callback: fn (Index $new) => $new->insert($docs), language: null);

// Override language
Index::rebuild('/path/to/articles.db', callback: fn (Index $new) => $new->insert($docs), language: 'de');
```

### Document store on rebuild

`rebuild()` inherits the store setting from the existing index. Pass `store` to override:

| `$store` value | Effect |
|----------------|--------|
| `null` (default) | Inherit from the existing index |
| `true` | Enable the store in the rebuilt index |
| `false` | Disable the store in the rebuilt index |

```php
// Inherit (default)
Index::rebuild('/path/to/articles.db', callback: fn (Index $new) => $new->insert($docs));

// Force the store off even if the existing index had it on
Index::rebuild('/path/to/articles.db', callback: fn (Index $new) => $new->insert($docs), store: false);
```

### Facets on rebuild

The rebuilt index always has the facet index enabled. Supply `_facets` values on documents during the rebuild callback to populate it.

```php
Index::rebuild('/path/to/articles.db', callback: fn (Index $new) => $new->insert($docs));
```

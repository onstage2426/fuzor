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

The facet index is always enabled. Declare which fields are facet fields at creation time using `facetFields`. Their values are stored in a separate index table and can be used to filter results and compute per-value counts at search time. See [search.md](search.md) for querying and filtering by facets.

By default every field (except `id` and any declared `facetFields`) is tokenised for full-text. Pass `searchableFields` to restrict FTS to an explicit list of fields — any field not in either list is stored but not indexed.

```php
// Watches index: two facetable fields, two searchable fields,
// image_url and sku are stored-only automatically.
$index = new Index('/path/to/watches.db',
    facetFields:      ['brand', 'price', 'category', 'gender'],
    searchableFields: ['title', 'body'],
);
```

Pass `stripHtml: true` when documents contain HTML markup. Each field value is passed through `strip_tags()` before tokenisation so tag names, attributes, and entity-like fragments never enter the FTS index. The raw HTML is still stored unchanged in the document store. Ignored when opening an existing index.

```php
$index = new Index('/path/to/articles.db', stripHtml: true);
```

Pass a `Config` object to tune BM25, typo tolerance, and other search behaviour. See [configuration.md](configuration.md) for details.

```php
use Fuzor\Config;

$index = new Index('/path/to/articles.db', config: new Config(maxDocs: 200));
```

### Schema

The schema settings control how the index is structured at creation time. They are persisted inside the index file and cannot be changed without rebuilding.

| Parameter | Default | Effect |
|-----------|---------|--------|
| `language` | `null` | BCP 47 language tag; `null` disables stopwords and stemming |
| `store` | `true` | Enable the document store |
| `facetFields` | `[]` | Fields routed to the facet index |
| `searchableFields` | `null` | Fields tokenised for FTS; `null` = all non-facet fields |
| `stripHtml` | `false` | Strip HTML tags before tokenisation |

These same settings are grouped by the `SchemaConfig` value object used when passing a schema override to `rebuild()` — see [Atomic rebuild](#atomic-rebuild).

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

Fields listed in `facetFields` at creation time are automatically routed to the facet index. They are never tokenised for full-text — `search()` and `searchBoolean()` will not match against their contents — unless the field is also listed in `searchableFields`.

```php
$index = new Index('/path/to/watches.db',
    facetFields: ['brand', 'gender', 'category', 'price'],
);

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
| Integer or float | `'price' => 129.99` | Numeric facet; aggregated as min/max/count at search time |

Documents that omit a declared facet field are indexed normally for full-text but contribute nothing to the facet index for that field.

### Stored-only fields

A field that is not in `facetFields` and not in `searchableFields` (when `searchableFields` is set) is stored in the document store but never indexed — neither for full-text nor for facets. This is the right place for URLs, image paths, internal SKUs, and timestamps you want to retrieve but not search on.

```php
$index = new Index('/path/to/watches.db',
    facetFields:      ['brand', 'price'],
    searchableFields: ['title', 'body'],
    // image_url and sku are stored-only automatically
);

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

// Re-index with new facet fields
use Fuzor\SchemaConfig;

Index::rebuild('/path/to/articles.db', schema: new SchemaConfig(
    language:         'en',
    facetFields:      ['brand', 'price', 'category'],
    searchableFields: ['title', 'body'],
));
```

Throws `\InvalidArgumentException` if the callback is omitted and the existing index has no document store.

Internally, `rebuild` writes to a temporary file alongside the target, then renames it over the original — a POSIX-atomic operation on the same filesystem.

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

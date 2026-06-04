# Document store

The document store persists the raw document array inside the same SQLite file as the inverted index. When enabled (the default), search results contain the full document data — no second database query needed.

```php
// Store on (default)
$index = new Index('/path/to/articles.db');

// Store off — smaller file, no document retrieval
use Fuzor\SchemaConfig;
$index = new Index('/path/to/articles.db', schema: new SchemaConfig(store: false));
```

The setting is persisted at creation time. Opening an existing index restores it automatically.

## Documents in search results

When the store is enabled, each hit in `$result->hits` is the full document array:

```php
$result = $index->search('electric bike');

foreach ($result->hits as $hit) {
    echo $hit['title'];    // 'Electric Mountain Bike'
    echo $hit['price'];    // 899.99
    echo $hit['image'];    // 'https://…'
}
```

When the store is disabled, hits are id-only stubs:

```php
$result->hits; // [['id' => 3], ['id' => 1], ['id' => 7]]
```

## Fetching documents by ID

Retrieve documents without a search:

```php
// Single document — returns null if not found
$doc = $index->get(42);

// Multiple documents — id => document map; missing IDs silently omitted
$docs = $index->getMany(1, 2, 3);
$docs[1]; // ['id' => 1, 'title' => '…', …]
```

## Iterating all documents

Stream the full document store in ascending ID order:

```php
foreach ($index->stream() as $id => $doc) {
    echo $id . ': ' . $doc['title'] . "\n";
}
```

Increase `$batchSize` (default `100`) for higher throughput:

```php
foreach ($index->stream(batchSize: 500) as $id => $doc) {
    // …
}
```

## Stored-only fields

A field that is not in `facetFields` and not in `searchableFields` is stored but never indexed — it won't appear in search or filter results, but it will be present in every hit and every `get()` response. Use this for URLs, image paths, timestamps, internal IDs, and anything you need at render time but not at search time.

```php
$index = new Index('/path/to/watches.db', schema: new SchemaConfig(
    facetFields:      ['brand', 'price'],
    searchableFields: ['title', 'body'],
    // image_url and sku are stored-only automatically
));

$index->insert([[
    'id'        => 1,
    'title'     => 'Casio G-Shock',
    'body'      => 'Shock resistant.',
    'brand'     => 'Casio',
    'price'     => 129.99,
    'image_url' => 'https://example.com/gshock.jpg',
    'sku'       => 'GA-2100-1A1ER',
]]);

$index->search('gshock')->hits[0]['image_url']; // 'https://…'
```

Stored-only fields are updated atomically when the document is updated or upserted.

## Rebuilding without source data

As long as the store is enabled, you can rebuild the index without keeping a separate copy of your data:

```php
// Re-index with a new schema — Fuzor streams from the store automatically
Index::rebuild('/path/to/articles.db', schema: new SchemaConfig(
    language:    'en',
    facetFields: ['category', 'price'],
));
```

See [indexing.md § Atomic rebuild](indexing.md#atomic-rebuild) for details.

## Checking whether the store is active

```php
$index->documentStoreEnabled; // bool
```

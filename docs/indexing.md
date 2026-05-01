# Indexing

Fuzor stores only the inverted index — you own the document layer. Every document must have a unique integer `id` field; all other fields are indexed as full-text.

## Creating and opening an index

```php
use Fuzor\Index;

$index = Index::create('/path/to/articles.db');
$index = Index::open('/path/to/articles.db');
```

Pass `force: true` to overwrite an existing index file:

```php
$index = Index::create('/path/to/articles.db', force: true);
```

## Single document

```php
$index->insert(['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.']);
```

Throws if the ID already exists. Use `update()` to replace an existing document.

## Bulk insert

```php
$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions, instant torque, sporty design.'],
]);
```

Significantly faster than calling `insert()` in a loop — all documents are indexed in a single transaction with one wordlist pass. Accepts any `iterable`, including generators:

```php
$index->insertMany(function () use ($db) {
    foreach ($db->query('SELECT id, title, body FROM articles') as $row) {
        yield $row;
    }
}());
```

## Update

Replaces an existing document in the index. Old index data is removed and the document is re-indexed in a single transaction. If the ID does not exist it is inserted (upsert semantics).

```php
$index->update(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);
```

## Update many

```php
$index->updateMany([
    ['id' => 1, 'title' => 'Updated sedan',   'body' => 'New content.'],
    ['id' => 2, 'title' => 'Updated SUV',      'body' => 'More new content.'],
]);
```

Replaces multiple documents in a single transaction with one stats update. More efficient than calling `update()` in a loop. Non-existent IDs are inserted (upsert semantics), exactly like `update()`.

## Delete

```php
$index->delete(1);
```

Removes the document from all index tables and updates the document count. No-op if the ID does not exist.

## Delete many

```php
$index->deleteMany([1, 2, 3]);
```

Removes all specified documents in a single transaction. More efficient than calling `delete()` in a loop. No-op for IDs that do not exist.

## Check existence

```php
$index->has(1); // true / false
```

Returns `true` if the document is present in the index, `false` otherwise. Does not touch the wordlist or scores.

## Check existence of many

```php
$index->hasMany([1, 2, 3]); // [1 => true, 2 => false, 3 => true]
```

Returns a map of `id => bool` for every requested ID. More efficient than calling `has()` in a loop — resolves all IDs in a single query. Keys follow the input order. Returns an empty array for empty input.

## Document count

```php
$index->count(); // int
```

Returns the total number of indexed documents. Reads from the cached `info` table — no extra database round-trip if a search or write has already warmed the cache.

## Atomic rebuild

Replaces the entire contents of an index in one atomic operation. The callback receives a fresh, empty handle to populate; if it throws, the original file is left completely untouched.

```php
$index = Index::rebuild('/path/to/articles.db', function (IndexHandle $new) use ($docs) {
    $new->insertMany($docs);
});
```

Internally, `rebuild` writes to a temporary file alongside the target, then renames it over the original — a POSIX-atomic operation on the same filesystem. The language configured on the existing index is read first and applied to the new one automatically, so tokenisation and stemming remain consistent without any extra configuration.

## Multi-field documents

All fields except `id` are concatenated and indexed together. There is no per-field weighting — the more a term appears across all fields, the higher it scores.

```php
$index->insert([
    'id'       => 1,
    'title'    => 'Fast sedan',
    'body'     => 'Comfortable city car.',
    'tags'     => 'automotive review',
    'category' => 'cars',
]);
```

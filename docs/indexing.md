# Indexing

Fuzor stores only the inverted index — you own the document layer.

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

Pass a BCP 47 language tag to enable stopword filtering and stemming at creation time:

```php
$index = new Index('/path/to/articles.db', language: 'en');
```

Pass a `Config` object to tune BM25 and fuzzy behaviour:

```php
use Fuzor\Config;

$index = new Index('/path/to/articles.db', config: new Config(maxDocs: 200));
```
## Inserting


Every document must have a unique integer `id` field; all other fields are indexed as full-text.

### Single insert

```php
$index->insert(['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.']);
```

Throws if the ID already exists. Use `update()` to replace an existing document, or `upsert()` for create-or-replace semantics.

### Bulk insert

Significantly faster than calling `insert()` in a loop — all documents are indexed in a single transaction with one wordlist pass. Accepts any `iterable`, including generators.

```php
$index->insertMany([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'Comfortable city car with great fuel economy.'],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure. Handles any terrain.'],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions, instant torque, sporty design.'],
]);
```

```php
$index->insertMany(function () use ($db) {
    foreach ($db->query('SELECT id, title, body FROM articles') as $row) {
        yield $row;
    }
}());
```

## Updating

Replaces an existing document. Old index data is removed and the document is re-indexed in a single transaction. Throws `QueryException` if the ID does not exist — use `upsert()` if you want create-or-replace semantics.

### Single update

```php
use Fuzor\Exceptions\QueryException;

$index->update(['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']);
```

### Bulk update

Replaces multiple documents in a single transaction. Throws `QueryException` listing all missing IDs before any writes are made — the transaction is never partially applied.

```php
$index->updateMany([
    ['id' => 1, 'title' => 'Updated sedan',   'body' => 'New content.'],
    ['id' => 2, 'title' => 'Updated SUV',      'body' => 'More new content.'],
]);
```

## Upserting

Creates the document if the ID does not exist; replaces it if it does.

### Single upsert

```php
$index->upsert(['id' => 1, 'title' => 'Fast sedan', 'body' => 'Comfortable city car.']);
```

### Bulk upsert

Create-or-replace for a batch of documents in a single transaction.

```php
$index->upsertMany([
    ['id' => 1, 'title' => 'Fast sedan',   'body' => 'Comfortable city car.'],
    ['id' => 4, 'title' => 'New document', 'body' => 'Did not exist before.'],
]);
```

## Deleting

Removes the document from all index tables and updates the document count. No-op if the ID does not exist.

### Single delete

```php
$index->delete(1);
```

### Bulk delete

```php
$index->deleteMany([1, 2, 3]);
```

## Check existence

Check if a document is present in the index using the document id.

### Single has

Returns `true` if the document is present in the index, `false` otherwise.

```php
$index->has(1); // true / false
```

### Bulk has

Returns a map of `id => bool` for every requested ID.

```php
$index->hasMany([1, 2, 3]);
```

## Document count

Returns the total number of indexed documents. Reads from the cached `info` table — no extra database round-trip if a search or write has already warmed the cache.

```php
$index->count(); // int
```

## Atomic rebuild

Replaces the entire contents of an index in one atomic operation. The callback receives a fresh, empty handle to populate; if it throws, the original file is left completely untouched.

```php
Index::rebuild('/path/to/articles.db', function (Index $new) use ($docs) {
    $new->insertMany($docs);
});
```

Internally, `rebuild` writes to a temporary file alongside the target, then renames it over the original — a POSIX-atomic operation on the same filesystem. The language configured on the existing index is read first and applied to the new one automatically, so tokenisation and stemming remain consistent without any extra configuration.

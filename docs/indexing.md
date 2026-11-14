# Indexing

## Creating and opening an index

`new Index($path)` opens the index at `$path`, or creates it when there is no file:

```php
use Fuzor\Index;
use Fuzor\SchemaConfig;

$index = new Index('/path/to/products.db', schema: new SchemaConfig(
    filterableAttributes: ['brand', 'price'],
    sortableAttributes:   ['price'],
));
```

| Argument | Effect |
|---|---|
| `schema:` | The schema of a new index (below). For an existing file it must be the stored schema, or a `QueryException` names what differs; change a schema with [`rebuild()`](operations.md#rebuilding). Lists of fields and stop words compare in any order, `searchableAttributes` in its order |
| `config:` | Search settings for this connection: limits, typo tolerance, BM25, cache sizes ([Performance](performance.md#settings)) |
| `readonly: true` | Open an existing index read-only; every write method throws an `IOException` |
| `force: true` | Replace an existing index with an empty one. Processes that have the old file open keep reading it ([Operations](operations.md#index-files-and-versions)) |

The directory must exist. `Index::exists($path)` tells whether a Fuzor index is there.

## Schema

The schema is stored in the file when it is created and read back on every open. The index exposes
it as `$index->filterableAttributes`, `$index->sortableAttributes`, `$index->searchableAttributes`,
`$index->stripHtml`, `$index->stopWords`, `$index->stemmer` and `$index->documentStoreEnabled`.

| `SchemaConfig` | Default | Effect |
|---|---|---|
| `filterableAttributes` | `[]` | Fields usable in `filter`, `facets`, `facetStatsOnly`, `distinct` and `facetSearch()` |
| `sortableAttributes` | `[]` | Fields usable in `sort` |
| `searchableAttributes` | `null` | Fields matched by the query text; `null`: every field that is neither filterable nor sortable |
| `store` | `true` | Store each document, so hits, `get()` and `stream()` return it |
| `stripHtml` | `false` | Index the visible text of HTML values |
| `stemmer` | `null` | Class implementing `Fuzor\Stemmer`; see [Language](language.md#stemming) |
| `stopWords` | `[]` | Words left out of the index and of queries; see [Language](language.md#stop-words) |

### Field roles

Filterable and sortable fields are stored in the facet index and are not matched by the query
text, unless they are also listed in `searchableAttributes`. A field may be in all three lists.

Without `searchableAttributes`, every other field is searchable, arrays and objects included. When
documents carry image URLs, attribute maps or other data nobody searches for, list the searchable
fields: indexing is faster and the file smaller, and the other fields are only stored.

```php
// title and body searchable; brand and price filterable, price sortable;
// image_url and sku only stored, returned with each hit
$index = new Index('/path/to/products.db', schema: new SchemaConfig(
    searchableAttributes: ['title', 'body'],
    filterableAttributes: ['brand', 'price'],
    sortableAttributes:   ['price'],
));
```

### Values

| Value | Searchable field | Filterable or sortable field |
|---|---|---|
| String | its words | one value, matched exactly |
| `int`, `float` | its digits as words (`129.5`: `129`, `5`) | a number: ranges, `facetStats`, numeric sort |
| `bool` | `true` / `false` | not stored |
| Array | each element, in order | one value per element (`'tags' => ['sale', 'new']`) |
| `null`, `''`, missing | nothing | no value: the document has no value for the field |

In a searchable array, elements are kept apart, so a quoted phrase does not join the end of one
element to the start of the next. Nested arrays and objects are indexed by their values, not their
keys.

### HTML

With `stripHtml: true`, each value is converted to the text a reader would see before it is
indexed, so tag names, attributes and entities never become words:

- block tags (`<p>`, `<div>`, `<li>`, `<td>`, `<h1>`, `<br>`, …) separate words: `<p>foo</p><p>bar</p>`
  gives `foo` and `bar`;
- inline tags (`<b>`, `<a>`, `<span>`, …) are removed, so `fo<b>o</b>` stays one word;
- the content of `<script>`, `<style>`, `<template>` and `<noscript>` is dropped;
- entities are decoded: `Fit &amp; Flare caf&eacute;` gives `fit`, `flare`, `café`.

The stored document keeps the HTML, so hits return HTML; with `escapeFormatted`, `_formatted`
shows the visible text ([Formatting](formatting.md#rendering-_formatted-as-html)).

To store and return text instead, convert the fields yourself with `HtmlText::toText()`, the same
conversion, and leave `stripHtml` off. Keep the original in a stored-only field if you need it:

```php
use Fuzor\HtmlText;

$index = new Index('/path/to/posts.db', schema: new SchemaConfig(
    searchableAttributes: ['title', 'content'],  // content_html is only stored
));

$index->insert([[
    'id'           => 1,
    'title'        => HtmlText::toText($post['title']),
    'content'      => HtmlText::toText($post['content']),
    'content_html' => $post['content'],
]]);
```

- **Convert once, from the original HTML.** The conversion decodes entities, so a second pass loses
  text: `use the &lt;b&gt; tag` becomes `use the <b> tag`, and again `use the tag`. Never run stored
  text through it again, for example after `get()` and before `update()`.
- **Do not combine it with `stripHtml`** on the same field, which would convert a second time.
- **The result is plain text, not HTML.** Escape it before you print it.

## Inserting

`insert()` takes a list of documents, or any iterable of them, such as a generator. Each document
needs a unique integer `id`:

```php
$index->insert([
    ['id' => 1, 'title' => 'Fast sedan',     'body' => 'Comfortable city car.'],
    ['id' => 2, 'title' => 'Off-road SUV',   'body' => 'Built for adventure.'],
    ['id' => 3, 'title' => 'Electric coupe', 'body' => 'Zero emissions, instant torque.'],
]);

$index->insert((function () use ($pdo) {
    foreach ($pdo->query('SELECT id, title, body FROM articles') as $row) {
        $row['id'] = (int) $row['id'];
        yield $row;
    }
})());
```

All documents are written in one transaction: if one is invalid (no `id`, an `id` that is repeated
or already in the index), `insert()` throws and writes nothing. Insert many documents in one call,
not one per call: a bulk insert is far faster.

The documents are processed in chunks of `Config::$insertChunkSize` (2,000): each chunk is
tokenized and written before the next is read, so with a generator memory follows the chunk, not
the input. On a 44k-product catalog with HTML descriptions an `insert()` from a generator peaks at
about 170 MB. Lower the chunk size under a tight `memory_limit`; larger chunks did not load faster.

A `progress` callback is called after each document with the number done and the total, which is
`0` for a generator, since it is not read ahead:

```php
$index->insert($docs, progress: function (int $done, int $total): void {
    echo $total > 0 ? "$done / $total\n" : "$done\n";
});
```

### Durability during bulk loads

Writes of several documents (`insert()`, `update()`, `upsert()`) skip SQLite's sync to disk for
speed (`Config::$bulkSynchronousOff`, default `true`). If the PHP process dies during the load, the
transaction rolls back and the index is unchanged. If the operating system crashes or the power
fails during the write, the file can be damaged. For loads into an index that is serving searches,
either use [`rebuild()`](operations.md#rebuilding), which writes a separate file and only switches
to it when it is complete, or set `bulkSynchronousOff: false` at the cost of load speed.

## Updating and upserting

`update()` replaces existing documents; if any ID does not exist, it throws a `QueryException`
naming the missing IDs and writes nothing. `upsert()` creates the documents that do not exist and
replaces the others:

```php
$index->update([['id' => 1, 'title' => 'Updated sedan', 'body' => 'New content.']]);
$index->upsert([
    ['id' => 1, 'title' => 'Fast sedan',   'body' => 'Comfortable city car.'],
    ['id' => 4, 'title' => 'New document', 'body' => 'Did not exist before.'],
]);
```

Both replace the whole document: a field you leave out is gone. Both run in one transaction and
work in chunks like `insert()`.

Only what changed is rewritten. With the document store, a document whose searchable fields are
unchanged (the same values, of the same type, in the same order) keeps its word index entries, and
only its stored copy and filterable and sortable values are written: a price update of 200 products
on a 44k-product catalog takes 33 ms. When the text changes, only the words that changed are
written.

## Deleting

```php
$index->delete(1);        // one
$index->delete(1, 2, 3);  // several
```

IDs that do not exist are ignored. A deleted document is gone from every search, browse, facet
count, `get()` and `count()` once `delete()` returns: 50 documents take about 11 ms on a
44k-product catalog.

Its entries in the word index are only marked as deleted, and removed later in bulk, since
rewriting them is the expensive part of a delete. Until then the word statistics that relevance
uses still count the deleted documents, which can reorder close results slightly (with 10% of a
catalog deleted, the first hit stayed the same and 97% of the top-20 sets were unchanged). They are
removed:

- by `delete()` itself, once deleted documents make up 10% of the index (that call takes longer);
- by `optimize()`, which returns the number of documents it purged;
- by `rebuild()` and `clear()`;
- one by one, when a deleted ID is inserted again.

`clear()` removes every document and keeps the schema and synonyms.

### Deleting by filter

`deleteByFilter()` deletes the documents that match a filter, of the same shape as
`SearchOptions::$filter` (values, lists, ranges, exclusions, or a `Filter`), and returns how many
it deleted:

```php
use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\Filter;

$removed = $index->deleteByFilter(['brand' => 'Acme']);
$index->deleteByFilter(['status' => 'discontinued', 'stock' => FacetRange::max(0)]);
$index->deleteByFilter(Filter::any(['status' => 'discontinued'], ['brand' => 'Acme']));
```

An empty filter, or one that matches every document (`Filter::all()`), throws an
`\InvalidArgumentException`; use `clear()` to delete everything. A filter of only exclusions keeps
its meaning: `['brand' => new FacetExclude('Acme')]` deletes every document that is not Acme.

## Reading documents

With the document store (the default), the documents come back as they were inserted:

```php
$index->get(42);           // the document, or null
$index->getMany(1, 2, 3);  // [1 => [...], 3 => [...]]: missing IDs are left out

foreach ($index->stream() as $id => $doc) {
    // every document, by ascending ID
}
$index->stream(batchSize: 500, afterId: 10_000);  // 500 rows per read, from ID 10,001
```

`get()`, `getMany()` and `stream()` throw a `QueryException` when the index has no store. Turning
the store off (`store: false`) gives a smaller file, and hits that are only `['id' => 3]`, to look
up in your own database.

```php
$index->has(1);            // bool
$index->hasMany(1, 2, 3);  // [1 => true, 2 => false, 3 => true]
$index->count();           // number of documents; count($index) does the same
```

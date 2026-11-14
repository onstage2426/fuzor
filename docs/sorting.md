# Sorting

## Sort fields

`sort` orders the results by field values instead of relevance. Fields must be declared in
`sortableAttributes` when the index is created:

```php
$index = new Index('/path/to/products.db', schema: new SchemaConfig(
    filterableAttributes: ['brand'],
    sortableAttributes:   ['price', 'brand', 'released'],
));

use Fuzor\Sort;

$index->search('watch', new SearchOptions(sort: [Sort::asc('price')]));
$index->search('watch', new SearchOptions(sort: [Sort::asc('brand'), Sort::desc('price')]));  // brand, then price
$index->search('', new SearchOptions(sort: [Sort::desc('released')]));                       // newest products
```

The first spec decides, the next breaks its ties. A spec can also be written as a string,
`'price:asc'` or `'price:desc'` (direction case-insensitive), as it comes in a request parameter;
`SearchOptions` turns it into a `Sort`, so `$options->sort` is always a list of `Sort` objects. A
malformed string throws an `\InvalidArgumentException` when the `SearchOptions` is built; a field
outside `sortableAttributes` throws a `QueryException` when the search runs, since only the index
knows its schema. When the sort comes from a request parameter, map it to your own list of allowed
specs, as in [Getting started](getting-started.md#4-the-search-page).

In a search with a query, `sort` orders the documents within each words bucket and typo tier (see
[Concepts](concepts.md#how-a-search-ranks)): a document matching every word still comes before a
cheaper one matching fewer. Relevance breaks the ties of the sort fields, then the document ID.
`searchBoolean()` breaks them by document ID.

### How values compare

- **Numbers before strings**, in both directions. Numbers are values indexed as PHP `int` or
  `float`; a numeric-looking string is a string, so `"10"` sorts before `"9"`.
- **Strings** ignore case: `"apple"` comes before `"Zebra"`, and `"Apple"` and `"apple"` tie.
  Accents are not folded: accented letters sort after `z` (see below).
- **Several values** (an array): a document sorts by its smallest value ascending, its largest
  descending.
- **Documents without the field** come last, in both directions.

To sort accented text alphabetically, store a sort key as its own field. `Tokenizer::sortKey()`
lowercases, folds Latin accents to their base letter (`é` → `e`, `ß` → `ss`) and collapses
whitespace, with a built-in table so every server makes the same key:

```php
use Fuzor\Tokenizer;

$index = new Index('/path/to/products.db', schema: new SchemaConfig(
    searchableAttributes: ['title'],
    sortableAttributes:   ['title_sort'],
));
$index->insert([[
    'id'         => 1,
    'title'      => 'Éclair au chocolat',
    'title_sort' => Tokenizer::sortKey('Éclair au chocolat'),  // "eclair au chocolat"
]]);

$index->search('', new SearchOptions(sort: [Sort::asc('title_sort')]));
```

### Sorting by ID

A browse without `sort` returns the newest documents first (highest ID). To sort by ID otherwise,
declare `id` in `sortableAttributes` like any field; it then stores one value per document:

```php
$index = new Index('/path/to/products.db', schema: new SchemaConfig(sortableAttributes: ['id', 'price']));
$index->search('', new SearchOptions(sort: [Sort::asc('id')]));  // lowest ID first
```

### Cost

A sorted browse walks the sort field's index in order and stops when the page is full, so its cost
follows the page, not the catalog: on a 44k-product catalog a page at offset 20,000 by price takes
0.01 ms, and a price range sorted by price 0.4 ms. A sorted search sorts its matches, which is part
of the search's own cost.

**Compared with Meilisearch:** the same value order (numbers first, case-insensitive strings
without accent folding, missing values last), and sort fields declared up front, the primary key
included. Meilisearch's default rules also rank typo, proximity and attribute before `sort`;
Fuzor ranks words and typos before `sort`, and relevance after it.

## Distinct

`distinct` returns at most one hit per value of a field, for example one per product group when
each colour or size is its own document:

```php
$index->search('shirt', new SearchOptions(distinct: 'product_group'));
$index->search('shirt', new SearchOptions(distinct: 'brand', distinctCount: 2));  // up to two per brand
```

The field must be in `filterableAttributes`. The best hit of each group is kept: the most relevant,
or with `sort`, the first in sort order:

```php
// The cheapest item per brand
$index->search('shirt', new SearchOptions(sort: [Sort::asc('price')], distinct: 'brand'));
```

Documents without a value for the field are never collapsed. A document with several values is
grouped by its smallest (numbers before strings).

`totalHits` counts the hits left after collapsing, so pagination stays right; `limit: 0` gives the
number of groups alone:

```php
$groups = $index->search('shirt', new SearchOptions(limit: 0, distinct: 'brand'))->totalHits;
```

Counting the groups means looking at every match, so a `distinct` request costs time in proportion
to the number of matches: on a 44k-product catalog a `distinct` browse of everything takes 18 ms,
with a filter and sort 35 ms. Facet counts are not collapsed: they count documents.

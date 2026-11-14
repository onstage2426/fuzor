# Filtering and facets

Filters restrict a search or browse to documents with given values; facets count the values of the
matching documents for a sidebar. Both work on the fields declared in `filterableAttributes` when
the index was created ([Indexing](indexing.md#schema)). Any other field throws a `QueryException`
that names the field and the declared list:

```php
$index->search('watch', new SearchOptions(filter: ['colour' => 'Black']));
// QueryException: Filter field 'colour' is not filterable. Declare it in
// SchemaConfig::$filterableAttributes when creating the index (declared: brand, price).
```

When filter fields come from user input, check them against `$index->filterableAttributes` first.
A declared field that no document has a value for yet is not an error: it has no values.

## Filters

A filter map combines its fields with AND, and a list of values with OR:

```php
$index->search('watch', new SearchOptions(filter: ['brand' => 'Casio']));
$index->search('watch', new SearchOptions(filter: ['brand' => ['Casio', 'Seiko']]));  // either
$index->search('watch', new SearchOptions(filter: ['brand' => 'Casio', 'category' => 'Watches']));
```

Values match exactly, case included. A number matches the values stored as that number or as the
same text: `['stars' => 5]` finds `5`, `5.0` and `'5'`.

A malformed filter throws an `\InvalidArgumentException` where it is passed: in the `SearchOptions`
or `FacetSearchQuery` constructor, or in `deleteByFilter()`. That covers a list instead of a map of
fields, and a value no document can hold (`true` or `false`, `null`, NaN, an infinite number). An
undeclared field is found when the search runs, against the schema.

A document with several values for a field (`'tags' => ['sale', 'new']`) matches a filter on any of
them.

### Numeric ranges

```php
use Fuzor\FacetRange;

$index->search('watch', new SearchOptions(filter: ['price' => FacetRange::between(50, 200)]));
```

| Factory | Matches |
|---|---|
| `FacetRange::between($gte, $lte)` | from `$gte` up to `$lte`, both included |
| `FacetRange::min($gte)` | `$gte` and above |
| `FacetRange::max($lte)` | up to `$lte` |
| `FacetRange::gt($gt)` | above `$gt` |
| `FacetRange::lt($lt)` | below `$lt` |

`new FacetRange(gte:, lte:, gt:, lt:)` takes any combination. Ranges match values indexed as PHP
`int` or `float`; a numeric string is a string.

### Excluding values

`FacetExclude` keeps the documents that do **not** match a value, a list of values or a range:

```php
use Fuzor\FacetExclude;

$index->search('shirt', new SearchOptions(filter: [
    'visibility' => new FacetExclude('hidden'),  // hide one value
    'brand'      => ['Nike', 'Adidas'],          // combined by AND, as usual
]));

new FacetExclude(['discontinued', 'outofstock']);  // hide any of these
new FacetExclude(FacetRange::max(0));               // hide free items
```

- A document passes when **none** of its values matches. A document tagged `shoes` and `sale` is
  removed by `new FacetExclude('sale')`, which listing every other tag cannot do.
- A document without the field passes: it has nothing to exclude.
- An empty list, or a declared field no document has yet, excludes nothing.

A browse whose only filters are exclusions is counted from the excluded documents: its total and
facet counts are exact at any index size, and its cost follows the number of excluded documents.

### Combining filters

The map covers "these values of these fields". For anything else (two values of one field that
must both be present, an OR across fields, a NOT around several conditions) build a `Filter`:

```php
use Fuzor\Filter;

Filter::all(['tags' => 'sale'], ['tags' => 'new']);           // tagged both
Filter::any(['brand' => 'Casio'], ['category' => 'Watches']); // either condition
Filter::not(['colour' => 'Black', 'stock' => 'in']);          // not (black and in stock)

// In stock, and either discounted or under 20
Filter::all(
    ['stock' => 'in'],
    Filter::any(['tags' => 'discount'], ['price' => FacetRange::max(20)]),
);
```

A `Filter` goes wherever a filter map does: `SearchOptions::$filter`, `FacetSearchQuery::$filter`
and `deleteByFilter()`.

- Each entry is another `Filter` or a filter map with its usual meaning. Inside `any()`, a map with
  several fields is one entry: all of its fields must match.
- `not()` takes one entry. As with `FacetExclude`, a document without the field passes `not()` of a
  condition on it: `Filter::not(['brand' => 'Casio'])` is `['brand' => new FacetExclude('Casio')]`.
- `Filter::all()` without entries matches every document, `Filter::any()` without entries none.
- A malformed entry (a list instead of a field map, an unsupported value, more than 32 levels)
  throws an `\InvalidArgumentException` when the `Filter` is built; an undeclared field throws a
  `QueryException` when it is used, wherever it sits in the tree.

Two ways of writing the same condition give the same result: `Filter::all($map)` is `$map`, nested
`all()` and `any()` are flattened, `any()` of values of one field is a value list.

## Facet counts

`facets` lists the fields to count; each value gets the number of matching documents:

```php
$result = $index->search('watch', new SearchOptions(facets: ['brand', 'category', 'price']));

$result->facetDistribution;
// ['brand' => ['Casio' => 12, 'Seiko' => 8], 'category' => [...], 'price' => ['49.5' => 2, ...]]

$result->facetStats;
// ['price' => ['min' => 29.99, 'max' => 499.0]]
```

`facetStats` has the lowest and highest value of each counted field with numbers. In
`facetDistribution` a number is its text as the key (`'49.5'`); PHP turns whole numbers into
integer keys (`129`). With `distinct`, facets count documents, not groups.

### Counts while a value is selected

A field that is filtered and counted in the same request is counted without its own filter, so all
its values stay visible while one is selected:

```php
$result = $index->search('watch', new SearchOptions(
    filter: ['brand' => 'Casio'],
    facets: ['brand', 'category'],
));

$result->facetDistribution['brand'];     // ['Casio' => 12, 'Citizen' => 5, 'Seiko' => 8]: all brands
$result->facetDistribution['category'];  // ['Accessories' => 2, 'Watches' => 10]: Casio only
```

This holds for a field with one condition of its own at the top level of the filter: a value, a
list or a range, also in `Filter::all()`, where `any()` of values of one field counts as a list.
Every other field is counted over the filtered result, in particular:

- a field whose values are combined by AND (`Filter::all(['tags' => 'sale'], ['tags' => 'new'])`):
  each added value narrows the result, so the counts show what it would leave;
- a field that appears only inside `any()` or `not()` of several conditions;
- an exclusion, which always applies: excluded documents never show up in the counts.

**Compared with Meilisearch:** Meilisearch counts every field over the filtered result, and gives
counts without a field's own filter through one extra request per selected field in a multi-search.
Fuzor does it in the same request.

### Value order

Values are ordered alphabetically: numbers first by value, then strings ignoring case.
`sortFacetValuesBy` orders fields by count instead, most documents first (`'*'` sets the order for
every field not listed):

```php
use Fuzor\FacetOrder;

$index->search('watch', new SearchOptions(
    facets:            ['brand', 'category', 'size'],
    sortFacetValuesBy: ['*' => FacetOrder::Count, 'size' => FacetOrder::Alpha],
));
```

A field returns at most `Config::$maxValuesPerFacet` (100) values, cut after ordering: with
`Alpha`, a field with more values returns the first ones alphabetically, so ask for `Count` to keep
the most used. `0` returns every value.

### Stats without counts

A price slider needs the lowest and highest price, not a count per price. List such fields in
`facetStatsOnly`:

```php
$result = $index->search('', new SearchOptions(
    filter:         ['category' => 'tiles'],
    facets:         ['brand'],
    facetStatsOnly: ['price'],
));

$result->facetStats['price'];                // ['min' => 12.75, 'max' => 114.35]
isset($result->facetDistribution['price']);  // false
```

The stats are those `facets: ['price']` would give, over the same matches and without the field's
own filter, but much cheaper: instead of counting a value per document, the field's numbers are
read from both ends until the first matching document, often ten times faster or more. They are
exact even when the facet counts would be approximate. A field may not be in both lists.

### Exact and approximate counts

Counts are exact when no filter or query applies, and when the only filters are exclusions.
Otherwise they are counted over at most `Limits::$facetCountDocs` (50,000) matching documents; above
that they cover the newest matches (highest IDs), and the field is listed in
`$result->approximateFacets`. A value filter on a field that every document has exactly once is
counted from the values it leaves out when those hold at most `facetCountDocs` documents, so
`gender: ['Women', 'Men']` on a catalog with few children's products is exact too.

## Facet value search

`facetSearch()` lists the values of one field with their counts, for a filter dropdown that is
searched as the visitor types:

```php
use Fuzor\FacetSearchQuery;

$result = $index->facetSearch(new FacetSearchQuery(facetName: 'brand', facetQuery: 'sam'));

foreach ($result as $hit) {
    echo "{$hit['value']} ({$hit['count']})\n";  // "Samsonite (12)", "Samsung (31)"
}
```

`facetQuery` matches the start of any word of a value, ignoring case and Latin accents; spaces,
`-`, `_` and `/` separate words, so `rosa cl` and `CLARA` both find "rosa-clara" and "Rosa Clará".
There is no typo tolerance on values. Values come in alphabetical order, or by count with
`sortFacetValuesBy: FacetOrder::Count`, applied before `limit`.

`q` and `filter` count only the documents of a search:

```php
$result = $index->facetSearch(new FacetSearchQuery(
    facetName:  'genre',
    facetQuery: 'sc',
    q:          'adventure',                         // words, phrases and -exclusions as in search()
    filter:     ['year' => FacetRange::min(2000)],
    limit:      10,
));
```

| `FacetSearchQuery` | Default | Description |
|---|---|---|
| `facetName` | (required) | A field in `filterableAttributes` |
| `facetQuery` | `''` | Start of a word of the value; empty lists every value |
| `q` | `''` | Query whose documents are counted; every word is required |
| `filter` | `[]` | Filter applied before counting, as in `SearchOptions` |
| `limit` | `100` | Most values returned |
| `sortFacetValuesBy` | `FacetOrder::Alpha` | `Alpha` or `Count` |

The `FacetSearchResult` holds `facetHits` (`list<array{value: string, count: int}>`), `facetQuery`,
`warnings` and `exhaustive`, which is `false` when the documents of `q` were cut at
`Limits::$facetCountDocs`. A filter without `q` is always exact.

**Compared with Meilisearch:** Meilisearch matches the start of a value's first word; Fuzor
matches any word, so slugs and multi-word names are found by each of their words.

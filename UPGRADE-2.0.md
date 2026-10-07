# Upgrading from 1.x to 2.0

## Recreate every index from your source data

2.0 does not open index files written by 1.x (schema revisions 1 to 3). Opening one, also
read-only or through `rebuild()`, throws a `QueryException`. An index is not a permanent store:
recreate it and insert your documents again.

```php
$index = new Index($path, schema: new SchemaConfig(/* ... */), force: true);
$index->insert($documentsFromYourSource);
```

`force: true` replaces the old file safely while other processes still have it open. Files
written by a newer Fuzor are rejected the same way.

The sections below list every change that needs a change in calling code.

## `facetFields` is split into `filterableFields` and `sortableFields`

`SchemaConfig::$facetFields` and `$index->facetFields` are gone. Declare fields used in `filter`,
`facets`, `distinct`, and `facetSearch()` in `filterableFields`, and fields used in `sort` in
`sortableFields`. A field may be in both. Neither kind is tokenised for full-text search unless
it is also in `searchableFields`.

```php
// 1.x
new SchemaConfig(facetFields: ['brand', 'color', 'price']);

// 2.0
new SchemaConfig(filterableFields: ['brand', 'color', 'price'], sortableFields: ['price']);
```

## Undeclared fields throw instead of warning

A `sort` field that is not in `sortableFields`, or a `filter`, `facets`, `distinct`, or
`FacetSearchQuery` `facetName`/`filter` field that is not in `filterableFields`, now throws a
`QueryException`. In 1.x it was ignored (or matched nothing) and reported in `$warnings`. When
these options come from user input, check them against `$index->sortableFields` /
`$index->filterableFields` or catch `QueryException`. `$warnings` now only reports caps.

## `Config::$filterMaxDocs` is removed

It has had no effect since 1.6.0. Remove it from `new Config(...)` calls.

## String sort ignores case

`sort` on a string field now orders uppercase letters as if they were lowercase: `"apple"`
before `"Zebra"` (1.x put `"Zebra"` first). Values that differ only in case tie. Accents are
still not folded. Results sorted on mixed-case string fields can come back in a different order.

## A leading `-` in `search()` excludes

In 1.x `search('shirt -formal')` searched for both words. In 2.0 a word or quoted phrase with a
leading `-` (at the start of the query or after a space) removes the documents that contain it,
and a query of only such words returns all other documents. Hyphens inside words (`t-shirt`)
are unchanged. If your users type a leading `-` meaning something else, strip it before calling
`search()`.

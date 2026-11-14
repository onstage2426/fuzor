# Getting started

This page builds a product search page: an index, a search box with typo tolerance, brand and
category filters with counts, a price range, sorting, highlighted results and pagination. Each step
links to the page that covers it in full.

## Install

```bash
composer require onstage2426/fuzor
```

Fuzor needs PHP 8.5+ (64-bit) with `pdo_sqlite` and `mbstring`, and SQLite 3.46.0 or newer.

## 1. Create the index

An index is one SQLite file. Its schema says what each field is for, and is fixed when the file is
created:

```php
use Fuzor\Index;
use Fuzor\SchemaConfig;

$index = new Index(__DIR__ . '/var/products.db', schema: new SchemaConfig(
    searchableAttributes: ['title', 'description'],  // matched by the search box
    filterableAttributes: ['brand', 'category', 'price'],  // filters and facet counts
    sortableAttributes:   ['price'],                 // sort options
    stripHtml:            true,                      // descriptions are HTML
));
```

The first `new Index()` creates the file; later ones open it and read the schema from it, so they
pass no `schema:`. Fields in none of the lists (an image URL, a product URL) are only stored and
come back with each hit. [Concepts](concepts.md) explains the field roles, [Indexing](indexing.md)
every schema setting.

## 2. Add documents

Every document is an array with a unique integer `id`:

```php
$index->insert([
    ['id' => 1, 'title' => 'Waterproof hiking boots', 'description' => '<p>Full-grain leather …</p>',
     'brand' => 'Northpeak', 'category' => 'Boots', 'price' => 129, 'url' => '/p/1'],
    ['id' => 2, 'title' => 'Trail running shoes', 'description' => '<p>Light and grippy …</p>',
     'brand' => 'Fellwalk', 'category' => 'Shoes', 'price' => 89, 'url' => '/p/2'],
    // …
]);
```

For a whole catalog, pass a generator: documents are written in chunks inside one transaction, so
memory stays flat however many there are.

```php
$index->insert((function () use ($pdo) {
    foreach ($pdo->query('SELECT id, title, description, brand, category, price, url FROM products') as $row) {
        $row['id']    = (int) $row['id'];
        $row['price'] = (float) $row['price'];  // a number, so ranges and price sorting work
        yield $row;
    }
})());
```

Numbers must be PHP `int` or `float` to work as numbers in range filters, facet stats and sorting;
a numeric string is a string.

## 3. Search

```php
$result = $index->search('waterprof boots');

$result->totalHits;  // matches over all pages
foreach ($result->hits as $hit) {
    echo $hit['title'], "\n";  // "Waterproof hiking boots": the typo still matches
}
```

The last word is matched as a prefix while it is typed (`hik` finds "hiking"), and words of five
letters or more may have a typo. See [Searching](searching.md).

## 4. The search page

One `search()` call returns the page, the total and the facet counts. Here the request parameters
become options; anything a visitor sends is checked against the schema first, since an undeclared
field throws a `QueryException`:

```php
use Fuzor\FacetRange;
use Fuzor\SearchOptions;

$perPage = 24;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$filter = [];
if (!empty($_GET['brand'])) {
    $filter['brand'] = array_values((array) $_GET['brand']);  // several brands: any of them
}
if (!empty($_GET['category'])) {
    $filter['category'] = (string) $_GET['category'];
}
if (isset($_GET['min'], $_GET['max'])) {
    $filter['price'] = FacetRange::between((float) $_GET['min'], (float) $_GET['max']);
}

$sorts = ['price-asc' => ['price:asc'], 'price-desc' => ['price:desc']];

$result = $index->search((string) ($_GET['q'] ?? ''), new SearchOptions(
    filter:                $filter,
    facets:                ['brand', 'category'],
    facetStatsOnly:        ['price'],  // min and max for the price slider
    sort:                  $sorts[$_GET['sort'] ?? ''] ?? [],  // default: relevance
    limit:                 $perPage,
    offset:                ($page - 1) * $perPage,
    attributesToHighlight: ['title', 'description'],
    attributesToCrop:      ['description'],
    cropLength:            160,
    escapeFormatted:       true,  // _formatted is safe HTML
));
```

An empty query is a browse: every document that passes the filters, newest first unless sorted,
which is a category page without a search word.

**Results.** With `escapeFormatted`, `_formatted` holds the title and an excerpt of the
description's text around the matches, with the matched words in `<mark>`, both safe to print. The
other fields are the stored values, so escape them yourself:

```php
foreach ($result->hits as $hit) {
    printf(
        '<a href="%s">%s</a><p>%s</p><span>€ %.2f</span>',
        htmlspecialchars($hit['url']),
        $hit['_formatted']['title'],
        $hit['_formatted']['description'],
        $hit['price'],
    );
}
```

**Filters.** `facetDistribution` gives each brand and category with its number of matching
products. A selected field is counted without its own filter, so the other brands stay visible
while one is selected and a visitor can add a second:

```php
foreach ($result->facetDistribution['brand'] ?? [] as $brand => $count) {
    // checkbox "$brand ($count)"
}
['min' => $lowest, 'max' => $highest] = $result->facetStats['price'] ?? ['min' => 0, 'max' => 0];
```

See [Filtering and facets](filtering-and-facets.md) and [Sorting](sorting.md).

**Pagination.** `totalHits` is exact, counted up to 1,000 (`Limits::$totalHits`); past that
`totalHitsCapped` is `true` and pages stop at the cap:

```php
$pages = (int) ceil($result->totalHits / $perPage);
$label = $result->totalHitsCapped ? "{$result->totalHits}+ results" : "{$result->totalHits} results";
```

## 5. Keep it in sync

Write each change when it happens in your application:

```php
$index->upsert([$product]);      // created or changed
$index->delete($product['id']);  // removed
```

`upsert()` replaces the whole document, so pass every field. Changes are visible to the next
search, also in other PHP processes. To reindex everything, for example after changing the schema,
`Index::rebuild()` builds a new file next to the old one and swaps it in atomically while searches
go on. [Operations](operations.md) covers rebuilds, snapshots and servers with many workers.

## Next

- [Concepts](concepts.md): how ranking, filters and the limits fit together.
- [Searching](searching.md): phrases, exclusions, matching strategies, synonyms, boolean queries.
- [Language](language.md): CJK and Thai, stemming, stop words.
- [Performance](performance.md): what each request costs, and the settings that change it.
- [Debugging](debugging.md): why a document matched, or did not.

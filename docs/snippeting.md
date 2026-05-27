# Snippeting

Fuzor can automatically add cropped excerpts to search hits via `SearchOptions`. For advanced use cases, a standalone `Snippeter` is also available.

## Integrated excerpts (recommended)

Set `attributesToCrop` in `SearchOptions` and each hit will include a `_formatted` key with cropped text for the requested fields:

```php
$result = $index->search('fast connections', new SearchOptions(
    attributesToCrop: ['body'],
    cropLength: 200,
));

foreach ($result->hits as $hit) {
    echo $hit['_formatted']['body'];
    // "… offers fast broadband connections for …"
}
```

Use `['*']` to crop all string fields:

```php
$result = $index->search('fast connections', new SearchOptions(
    attributesToCrop: ['*'],
));
```

Fields not found in the document, or that are not strings, are silently skipped.

### Options

| `SearchOptions` property | Default | Description                           |
|--------------------------|---------|---------------------------------------|
| `attributesToCrop`       | `null`  | Fields to crop; `['*']` for all string fields; `null` disables cropping |
| `cropLength`             | `200`   | Max characters per excerpt            |
| `cropMarker`             | `'…'`   | Inserted at clip boundaries           |

### Combining with highlighting

When both `attributesToCrop` and `attributesToHighlight` are set, cropping runs first and highlighting is applied to the cropped text:

```php
$result = $index->search('fast connections', new SearchOptions(
    attributesToCrop:      ['body'],
    cropLength:            200,
    attributesToHighlight: ['body'],
    highlightPreTag:       '<mark>',
    highlightPostTag:      '</mark>',
));

foreach ($result->hits as $hit) {
    echo $hit['_formatted']['body'];
    // "… offers <mark>fast</mark> broadband <mark>connections</mark> for …"
}
```

See [search.md § Formatting](search.md#formatting) for the full option reference.

## Standalone snippeter

For cases not covered by the integrated approach — extracting multiple windows per field, processing text outside of a search result, or building a custom rendering pipeline — use `$index->snippeter()` directly:

```php
$snip = $index->snippeter();

echo $snip->snippet('fast connections', $doc['body']);
// "… offers fast broadband connections for …"
```

### Multiple fields at once

Prefer `snippetMany()` over calling `snippet()` in a loop — it avoids redundant tokenisation:

```php
['title' => $title, 'body' => $body] = $snip->snippetMany('fast connections', [
    'title' => $doc['title'],
    'body'  => $doc['body'],
]);
```

### Options

| Parameter     | Default | Description                                    |
|---------------|---------|------------------------------------------------|
| `windowSize`  | `200`   | Max characters per excerpt                     |
| `maxSnippets` | `1`     | Max non-overlapping windows joined by ellipsis |
| `ellipsis`    | `'…'`   | String inserted at clip boundaries             |

The language is taken from the index automatically.

```php
$snip = $index->snippeter(windowSize: 300, maxSnippets: 2);
```

### Notes

- If the query is empty or produces no matches, the first `windowSize` characters are returned.
- If `$text` is empty, an empty string is returned.

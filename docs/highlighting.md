# Highlighting

Fuzor can automatically add highlighted text to search hits via `SearchOptions`. For advanced use cases, a standalone `Highlighter` is also available.

## Integrated highlighting (recommended)

Set `attributesToHighlight` in `SearchOptions` and each hit will include a `_formatted` key with matched terms wrapped in tags:

```php
$result = $index->search('fast sedan', new SearchOptions(
    attributesToHighlight: ['title', 'body'],
));

foreach ($result->hits as $hit) {
    echo $hit['_formatted']['title'];
    // "<em>Fast</em> <em>sedan</em> review"
}
```

Use `['*']` to highlight all string fields:

```php
$result = $index->search('fast sedan', new SearchOptions(
    attributesToHighlight: ['*'],
));
```

### Custom tags

```php
$result = $index->search('fast sedan', new SearchOptions(
    attributesToHighlight: ['title'],
    highlightPreTag:       '<mark>',
    highlightPostTag:      '</mark>',
));
```

### Options

| `SearchOptions` property | Default      | Description                                         |
|--------------------------|--------------|-----------------------------------------------------|
| `attributesToHighlight`  | `null`       | Fields to highlight; `['*']` for all string fields; `null` disables highlighting |
| `highlightPreTag`        | `'<em>'`     | Tag inserted before each match                      |
| `highlightPostTag`       | `'</em>'`    | Tag inserted after each match                       |

Matching is Unicode-aware, case-insensitive, and follows the same tokenisation as the index. The last token is matched as a prefix when `asYouType` is enabled (the default), so a query like `"merc"` highlights `"Mercedes"`.

### Combining with cropping

When both `attributesToHighlight` and `attributesToCrop` are set, cropping runs first and highlighting is applied to the cropped text:

```php
$result = $index->search('fast connections', new SearchOptions(
    attributesToCrop:      ['body'],
    cropLength:            200,
    attributesToHighlight: ['body'],
));

foreach ($result->hits as $hit) {
    echo $hit['_formatted']['body'];
    // "… offers <em>fast</em> broadband <em>connections</em> for …"
}
```

See [search.md § Formatting](search.md#formatting) for the full option reference.

## Standalone highlighter

For cases not covered by the integrated approach — highlighting text outside of a search result, or building a custom rendering pipeline — use `$index->highlighter()` directly:

```php
$hl = $index->highlighter();

echo $hl->highlight('fast sedan', $doc['title']);
// "<mark>Fast</mark> <mark>sedan</mark> review"
```

### Multiple fields at once

Prefer `highlightMany()` over calling `highlight()` in a loop — it builds the regex once and applies it to all fields:

```php
['title' => $title, 'body' => $body] = $hl->highlightMany('fast sedan', [
    'title' => $doc['title'],
    'body'  => $doc['body'],
]);
```

### Options

| Parameter   | Default      | Description                                              |
|-------------|--------------|----------------------------------------------------------|
| `open`      | `'<mark>'`   | Tag inserted before each match                           |
| `close`     | `'</mark>'`  | Tag inserted after each match                            |
| `asYouType` | `true`       | Last token matched as a prefix — `"merc"` highlights `"Mercedes"` |

The language is taken from the index automatically.

```php
$hl = $index->highlighter(open: '<b>', close: '</b>', asYouType: false);
```

### Notes

- Stemming is intentionally not applied — raw query terms are highlighted, not their stems.
- If the phrase produces no tokens, the text is returned unchanged.

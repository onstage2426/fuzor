# Formatting search results

Fuzor can attach highlighted text and cropped excerpts to every hit automatically. Pass `attributesToHighlight` and/or `attributesToCrop` in `SearchOptions` — each matching hit gains a `_formatted` key containing the processed fields.

## Highlighting

Wrap matched query terms in HTML tags:

```php
$result = $index->search('fast sedan', new SearchOptions(
    attributesToHighlight: ['title', 'body'],
));

foreach ($result->hits as $hit) {
    echo $hit['_formatted']['title'];
    // "<mark>Fast</mark> <mark>sedan</mark> review"
}
```

Use `['*']` to highlight every string field:

```php
new SearchOptions(attributesToHighlight: ['*'])
```

Change the tags with `highlightPreTag` and `highlightPostTag` (defaults are `<mark>` / `</mark>`):

```php
new SearchOptions(
    attributesToHighlight: ['title'],
    highlightPreTag:       '<strong>',
    highlightPostTag:      '</strong>',
)
```

The last query token is matched as a prefix when `asYouType` is on (the default), so `"merc"` highlights `"Mercedes"`.

## Cropping (excerpts)

Extract a short excerpt centred on where the query terms appear:

```php
$result = $index->search('fast connections', new SearchOptions(
    attributesToCrop: ['body'],
    cropLength:       200,
));

echo $result->hits[0]['_formatted']['body'];
// "… offers fast broadband connections for …"
```

Use `['*']` to crop all string fields. `cropMarker` controls the boundary string (default `'…'`):

```php
new SearchOptions(
    attributesToCrop: ['body'],
    cropLength:       150,
    cropMarker:       '[…]',
)
```

## Combining both

When a field appears in both lists, it is cropped first and then highlighted — you get a short excerpt with matched terms wrapped:

```php
$result = $index->search('fast connections', new SearchOptions(
    attributesToCrop:      ['body'],
    attributesToHighlight: ['title', 'body'],
    cropLength:            200,
));

// $hit['_formatted']['title'] — full title, matches highlighted
// $hit['_formatted']['body']  — short excerpt, matches highlighted
```

## When `_formatted` is absent

`_formatted` is not added to a hit when:

- Neither `attributesToHighlight` nor `attributesToCrop` is set (the default).
- The document store is disabled — hits contain only `['id' => n]` and there is no text to process.

Non-string fields and fields not in the requested list are excluded from `_formatted`.

## Option reference

| `SearchOptions` property | Default | Description |
|---|---|---|
| `attributesToHighlight` | `null` | Fields to highlight; `['*']` for all string fields; `null` disables |
| `highlightPreTag` | `'<mark>'` | Tag inserted before each match |
| `highlightPostTag` | `'</mark>'` | Tag inserted after each match |
| `attributesToCrop` | `null` | Fields to crop; `['*']` for all string fields; `null` disables |
| `cropLength` | `200` | Excerpt window size in characters |
| `cropMarker` | `'…'` | Inserted at crop boundaries |

## Standalone use

The `Highlighter` and `Snippeter` are also available directly — useful for highlighting text outside of a search result or building a custom rendering pipeline.

### Highlighter

```php
$hl = $index->highlighter();

// Single field
echo $hl->highlight('fast sedan', $doc['title']);
// "<mark>Fast</mark> <mark>sedan</mark> review"

// Multiple fields — builds the regex once, applies to all
['title' => $title, 'body' => $body] = $hl->highlightMany('fast sedan', [
    'title' => $doc['title'],
    'body'  => $doc['body'],
]);
```

Custom tags and prefix behaviour:

```php
$hl = $index->highlighter(open: '<b>', close: '</b>', asYouType: false);
```

Stemming is intentionally not applied — raw query terms are highlighted, not their stems.

### Snippeter

```php
$snip = $index->snippeter();

// Single field
echo $snip->snippet('fast connections', $doc['body']);
// "… offers fast broadband connections for …"

// Multiple fields — avoids redundant tokenisation
['title' => $title, 'body' => $body] = $snip->snippetMany('fast connections', [
    'title' => $doc['title'],
    'body'  => $doc['body'],
]);
```

Custom window and multiple non-overlapping excerpts:

```php
$snip = $index->snippeter(windowSize: 300, maxSnippets: 2);
```

If the query produces no matches, the first `windowSize` characters are returned.

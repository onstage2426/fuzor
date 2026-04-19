# Highlighting

`Highlighter` wraps matched query terms in document text with open/close tags. Matching is Unicode-aware, case-insensitive, and follows the same tokenisation as the index.

```php
$hl = $index->highlighter();

echo $hl->highlight('fast sedan', $doc['title']);
// "<mark>Fast</mark> <mark>sedan</mark> review"
```

You can also instantiate `Highlighter` directly if you need it without an index handle:

```php
use Fuzor\Highlighter;

$hl = new Highlighter();
```

## Multiple fields at once

Prefer `highlightMany()` over calling `highlight()` in a loop — it builds the regex once and applies it to all fields:

```php
['title' => $title, 'body' => $body] = $hl->highlightMany('fast sedan', [
    'title' => $doc['title'],
    'body'  => $doc['body'],
]);
```

## Options

| Parameter   | Default      | Description                                              |
|-------------|--------------|----------------------------------------------------------|
| `open`      | `'<mark>'`   | Tag inserted before each match                           |
| `close`     | `'</mark>'`  | Tag inserted after each match                            |
| `asYouType` | `true`       | Last token matched as a prefix — `"merc"` highlights `"Mercedes"` |
| `language`  | `null`       | BCP 47 tag for tokenisation; must match the index        |

```php
$hl = new Highlighter(open: '<b>', close: '</b>');

// Disable prefix matching on the last token
$hl = new Highlighter(asYouType: false);

// Direct construction with a language (matches CJK tokenisation)
$hl = new Highlighter(language: 'zh');
```

## Notes

- Stemming is intentionally not applied — raw query terms are highlighted, not their stems.
- If the phrase produces no tokens, the text is returned unchanged.

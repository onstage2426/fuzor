# Snippeting

`Snippeter` extracts the most relevant excerpt from document text for a given query. It finds the window where query terms are most densely clustered and returns it with ellipsis decoration.

```php
$snip = $index->snippeter();

echo $snip->snippet('fast connections', $doc['body']);
// "… offers fast broadband connections for …"
```

`$index->snippeter()` automatically passes the index language so stemmed query terms correctly match their surface forms in the text. You can also instantiate `Snippeter` directly if you need it without an index handle:

```php
use Fuzor\Snippeter;

$snip = new Snippeter(language: 'en');
```

## Multiple fields at once

Prefer `snippetMany()` over calling `snippet()` in a loop — it avoids redundant tokenisation:

```php
['title' => $title, 'body' => $body] = $snip->snippetMany('fast connections', [
    'title' => $doc['title'],
    'body'  => $doc['body'],
]);
```

## Options

| Parameter     | Default | Description                                    |
|---------------|---------|------------------------------------------------|
| `windowSize`  | `200`   | Max characters per excerpt                     |
| `maxSnippets` | `1`     | Max non-overlapping windows joined by ellipsis |
| `ellipsis`    | `'…'`   | String inserted at clip boundaries             |
| `language`    | `null`  | BCP 47 tag for stemming; must match the index  |

```php
$snip = new Snippeter(windowSize: 300, maxSnippets: 2, language: 'en');
```

## Fallback behaviour

- If the query is empty or produces no matches, the first `windowSize` characters are returned.
- If `$text` is empty, an empty string is returned.

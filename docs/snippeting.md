# Snippeting

`Snippeter` extracts the most relevant excerpt from document text for a given query. It finds the window where query terms are most densely clustered and returns it with ellipsis decoration.

```php
$snip = $index->snippeter();

echo $snip->snippet('fast connections', $doc['body']);
// "… offers fast broadband connections for …"
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

The language is taken from the index automatically.

```php
$snip = $index->snippeter(windowSize: 300, maxSnippets: 2);
```

## Notes

- If the query is empty or produces no matches, the first `windowSize` characters are returned.
- If `$text` is empty, an empty string is returned.

# Language

Pass a BCP 47 language tag to `Index::create()` to enable stopword filtering and Snowball stemming. If your documents are in a known language, setting it will significantly improve search quality — if unsure, leave it off.

The language is persisted in the index file and immutable after creation. It is restored automatically on `Index::open()` — you never need to re-specify it.

```php
$index = Index::create('/path/to/articles.db', language: 'en');
```

## What it does

- **Stopword filtering** — removes common words (e.g. "the", "and") from both indexed documents and search queries, reducing index noise.
- **Snowball stemming** — reduces words to their root form so "running" and "runs" match the same index entries. Applied automatically when a stemmer exists for the chosen language.

## CJK and Thai — built-in n-gram tokenisation

Chinese, Japanese, Korean, and Thai do not use spaces between words. Fuzor handles these languages automatically using n-gram tokenisation — no external segmenter required.

| Code | Language | N-gram size |
|------|----------|:-----------:|
| `zh` | Chinese  | 2 (bigram)  |
| `ja` | Japanese | 2 (bigram)  |
| `ko` | Korean   | 2 (bigram)  |
| `th` | Thai     | 3 (trigram) |

When one of these languages is set, Fuzor splits each CJK/Thai token into overlapping character windows at both index time and query time, so searching for `轿车` finds documents containing that sequence regardless of surrounding characters. ASCII tokens in the same document (e.g. brand names) are indexed normally.

### Tradeoffs

- **Index size** — a 10-character Chinese word produces ~9 bigrams instead of 1 token. Expect roughly N× growth in index size relative to a segmented approach.
- **False positives** — rare character combinations that span natural word boundaries may produce spurious matches. Precision is lower than a real segmenter.
- **No stemming** — no Snowball stemmer exists for these languages; stopword filtering still applies.

If you need higher precision for Chinese content at scale, you can pre-segment with a tool like jieba and index the space-separated output without setting a language.

## Changing language

Language cannot be changed on an existing index. Recreate the index from your document store:

```php
$index = Index::create($path, force: true, language: 'fr');
$index->insertMany($yourDocs);
```

## Reading the active language

```php
$index->language; // 'en', 'fr', null, …
```

## Supported languages

| Code | Language   | Stopwords | Stemmer |
|------|------------|:---------:|:-------:|
| `af` | Afrikaans  | ✓         |         |
| `ar` | Arabic     | ✓         | ✓       |
| `bg` | Bulgarian  | ✓         |         |
| `bn` | Bengali    | ✓         |         |
| `br` | Breton     | ✓         |         |
| `ca` | Catalan    | ✓         | ✓       |
| `cs` | Czech      | ✓         |         |
| `da` | Danish     | ✓         | ✓       |
| `de` | German     | ✓         | ✓       |
| `el` | Greek      | ✓         | ✓       |
| `en` | English    | ✓         | ✓       |
| `eo` | Esperanto  | ✓         | ✓       |
| `es` | Spanish    | ✓         | ✓       |
| `et` | Estonian   | ✓         | ✓       |
| `eu` | Basque     | ✓         | ✓       |
| `fa` | Persian    | ✓         |         |
| `fi` | Finnish    | ✓         | ✓       |
| `fr` | French     | ✓         | ✓       |
| `ga` | Irish      | ✓         | ✓       |
| `gl` | Galician   | ✓         |         |
| `gu` | Gujarati   | ✓         |         |
| `ha` | Hausa      | ✓         |         |
| `he` | Hebrew     | ✓         |         |
| `hi` | Hindi      | ✓         | ✓       |
| `hr` | Croatian   | ✓         |         |
| `hu` | Hungarian  | ✓         | ✓       |
| `hy` | Armenian   | ✓         | ✓       |
| `id` | Indonesian | ✓         | ✓       |
| `it` | Italian    | ✓         | ✓       |
| `ja` | Japanese   | ✓         |         |
| `ko` | Korean     | ✓         |         |
| `ku` | Kurdish    | ✓         |         |
| `la` | Latin      | ✓         |         |
| `lt` | Lithuanian | ✓         | ✓       |
| `lv` | Latvian    | ✓         |         |
| `mr` | Marathi    | ✓         |         |
| `ms` | Malay      | ✓         |         |
| `ne` | Nepali     |           | ✓       |
| `nl` | Dutch      | ✓         | ✓       |
| `no` | Norwegian  | ✓         | ✓       |
| `pl` | Polish     | ✓         | ✓       |
| `pt` | Portuguese | ✓         | ✓       |
| `ro` | Romanian   | ✓         | ✓       |
| `ru` | Russian    | ✓         | ✓       |
| `sk` | Slovak     | ✓         |         |
| `sl` | Slovenian  | ✓         |         |
| `so` | Somali     | ✓         |         |
| `sr` | Serbian    |           | ✓       |
| `st` | Sotho      | ✓         |         |
| `sv` | Swedish    | ✓         | ✓       |
| `sw` | Swahili    | ✓         |         |
| `ta` | Tamil      |           | ✓       |
| `th` | Thai       | ✓         |         |
| `tl` | Tagalog    | ✓         |         |
| `tr` | Turkish    | ✓         | ✓       |
| `uk` | Ukrainian  | ✓         |         |
| `ur` | Urdu       | ✓         |         |
| `vi` | Vietnamese | ✓         |         |
| `yi` | Yiddish    |           | ✓       |
| `yo` | Yoruba     | ✓         |         |
| `zh` | Chinese    | ✓         |         |
| `zu` | Zulu       | ✓         |         |

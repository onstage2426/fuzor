# Language

Pass a BCP 47 language tag at index creation time to enable stopword filtering and Snowball stemming. This significantly improves search quality — common words like "the" and "and" are ignored, and "running" and "runs" match the same entries.

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/articles.db', schema: new SchemaConfig(language: 'en'));
```

The language is persisted in the index and restored automatically when you reopen it. It cannot be changed without rebuilding.

## What it does

**Stopword filtering** removes high-frequency words (e.g. "the", "and", "is") from both the indexed documents and search queries. This reduces index noise and prevents common words from skewing relevance scores.

**Snowball stemming** reduces words to their root form so "running", "runs", and "ran" all match the same index entries. Applied automatically when a stemmer exists for the chosen language.

## CJK and Thai

Chinese, Japanese, Korean, and Thai don't use spaces between words. Fuzor handles them with n-gram tokenisation — no external segmenter required.

| Code | Language | N-gram |
|------|----------|:------:|
| `zh` | Chinese  | bigram |
| `ja` | Japanese | bigram |
| `ko` | Korean   | bigram |
| `th` | Thai     | trigram |

Fuzor splits each CJK/Thai string into overlapping character windows at both index and query time. ASCII tokens in the same document (e.g. brand names) are indexed normally alongside them.

**Tradeoffs vs. a real segmenter:**
- Index size grows roughly N× (one 10-character word becomes ~9 bigrams)
- Rare character sequences that span word boundaries may produce false positives
- No Snowball stemmer exists for these languages; stopword filtering still applies

For high-precision Chinese search at scale, pre-segment with jieba and index the space-separated output without setting a language.

## Changing language

Rebuild the index with a new `SchemaConfig`:

```php
use Fuzor\SchemaConfig;

Index::rebuild('/path/to/articles.db',
    fn (Index $new) => $new->insert($docs),
    schema: new SchemaConfig(language: 'fr'),
);
```

Or recreate from scratch:

```php
$index = new Index('/path/to/articles.db', force: true, schema: new SchemaConfig(language: 'fr'));
$index->insert($yourDocs);
```

## Reading the active language

```php
$index->language; // 'en', 'fr', null, …
```

## Supported languages

Get the full list at runtime — useful for building a language selector:

```php
$languages = \Fuzor\Language::all();
// ['af' => 'Afrikaans', 'ar' => 'Arabic', 'en' => 'English', …]
```

| Code | Language   | Stopwords | Stemmer |
|------|------------|:---------:|:-------:|
| `af` | Afrikaans  | ✓ | |
| `ar` | Arabic     | ✓ | ✓ |
| `bg` | Bulgarian  | ✓ | |
| `bn` | Bengali    | ✓ | |
| `br` | Breton     | ✓ | |
| `ca` | Catalan    | ✓ | ✓ |
| `cs` | Czech      | ✓ | |
| `da` | Danish     | ✓ | ✓ |
| `de` | German     | ✓ | ✓ |
| `el` | Greek      | ✓ | ✓ |
| `en` | English    | ✓ | ✓ |
| `eo` | Esperanto  | ✓ | ✓ |
| `es` | Spanish    | ✓ | ✓ |
| `et` | Estonian   | ✓ | ✓ |
| `eu` | Basque     | ✓ | ✓ |
| `fa` | Persian    | ✓ | |
| `fi` | Finnish    | ✓ | ✓ |
| `fr` | French     | ✓ | ✓ |
| `ga` | Irish      | ✓ | ✓ |
| `gl` | Galician   | ✓ | |
| `gu` | Gujarati   | ✓ | |
| `ha` | Hausa      | ✓ | |
| `he` | Hebrew     | ✓ | |
| `hi` | Hindi      | ✓ | ✓ |
| `hr` | Croatian   | ✓ | |
| `hu` | Hungarian  | ✓ | ✓ |
| `hy` | Armenian   | ✓ | ✓ |
| `id` | Indonesian | ✓ | ✓ |
| `it` | Italian    | ✓ | ✓ |
| `ja` | Japanese   | ✓ | |
| `ko` | Korean     | ✓ | |
| `ku` | Kurdish    | ✓ | |
| `la` | Latin      | ✓ | |
| `lt` | Lithuanian | ✓ | ✓ |
| `lv` | Latvian    | ✓ | |
| `mr` | Marathi    | ✓ | |
| `ms` | Malay      | ✓ | |
| `ne` | Nepali     | | ✓ |
| `nl` | Dutch      | ✓ | ✓ |
| `no` | Norwegian  | ✓ | ✓ |
| `pl` | Polish     | ✓ | ✓ |
| `pt` | Portuguese | ✓ | ✓ |
| `ro` | Romanian   | ✓ | ✓ |
| `ru` | Russian    | ✓ | ✓ |
| `sk` | Slovak     | ✓ | |
| `sl` | Slovenian  | ✓ | |
| `so` | Somali     | ✓ | |
| `sr` | Serbian    | | ✓ |
| `st` | Sotho      | ✓ | |
| `sv` | Swedish    | ✓ | ✓ |
| `sw` | Swahili    | ✓ | |
| `ta` | Tamil      | | ✓ |
| `th` | Thai       | ✓ | |
| `tl` | Tagalog    | ✓ | |
| `tr` | Turkish    | ✓ | ✓ |
| `uk` | Ukrainian  | ✓ | |
| `ur` | Urdu       | ✓ | |
| `vi` | Vietnamese | ✓ | |
| `yi` | Yiddish    | | ✓ |
| `yo` | Yoruba     | ✓ | |
| `zh` | Chinese    | ✓ | |
| `zu` | Zulu       | ✓ | |

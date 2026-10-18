# Language

Pass a BCP 47 language tag at index creation time to enable Snowball stemming, or n-gram
tokenisation for Chinese, Japanese, Korean and Thai. With stemming, "running" and "runs" match
the same entries.

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/articles.db', schema: new SchemaConfig(language: 'en'));
```

The language is persisted in the index and restored automatically when you reopen it. It cannot be changed without rebuilding.

## What it does

**Snowball stemming** reduces words to their root form so "running", "runs", and "ran" all match the same index entries. Applied automatically when a stemmer exists for the chosen language.

A language does not filter any words. Every word is indexed and searched, as in Meilisearch, so
product words such as "c" in "usb c cable" or "men" keep counting.

## Stop words

To leave words out, list them in the schema. They are left out of the index and of every query
(also quoted phrases and `-word` exclusions), matched case-insensitively before stemming:

```php
$index = new Index('/path/to/articles.db', schema: new SchemaConfig(
    language: 'en',
    stopWords: ['the', 'a', 'an', 'of'],
));
$index->stopWords; // ['the', 'a', 'an', 'of']
```

Each entry must be a single word (`\InvalidArgumentException` otherwise). The list is part of the
schema: change it with `rebuild()`. A query made only of stop words searches its words as typed.
Fuzor ships no word lists: general-purpose lists remove words that matter in many catalogs, so
choose the words for your own data.

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
- No Snowball stemmer exists for these languages

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
// ['ar' => 'Arabic', 'ca' => 'Catalan', 'en' => 'English', …]
```

Any other tag throws a `QueryException` at index creation. For a language not listed, leave
`language` at `null`: words are then split and indexed as they are.

| Code | Language | Applies |
|------|----------|---------|
| `ar` | Arabic | Snowball stemmer |
| `ca` | Catalan | Snowball stemmer |
| `da` | Danish | Snowball stemmer |
| `de` | German | Snowball stemmer |
| `el` | Greek | Snowball stemmer |
| `en` | English | Snowball stemmer |
| `eo` | Esperanto | Snowball stemmer |
| `es` | Spanish | Snowball stemmer |
| `et` | Estonian | Snowball stemmer |
| `eu` | Basque | Snowball stemmer |
| `fi` | Finnish | Snowball stemmer |
| `fr` | French | Snowball stemmer |
| `ga` | Irish | Snowball stemmer |
| `hi` | Hindi | Snowball stemmer |
| `hu` | Hungarian | Snowball stemmer |
| `hy` | Armenian | Snowball stemmer |
| `id` | Indonesian | Snowball stemmer |
| `it` | Italian | Snowball stemmer |
| `ja` | Japanese | bigrams |
| `ko` | Korean | bigrams |
| `lt` | Lithuanian | Snowball stemmer |
| `ne` | Nepali | Snowball stemmer |
| `nl` | Dutch | Snowball stemmer |
| `no` | Norwegian | Snowball stemmer |
| `pl` | Polish | Snowball stemmer |
| `pt` | Portuguese | Snowball stemmer |
| `ro` | Romanian | Snowball stemmer |
| `ru` | Russian | Snowball stemmer |
| `sr` | Serbian | Snowball stemmer |
| `sv` | Swedish | Snowball stemmer |
| `ta` | Tamil | Snowball stemmer |
| `th` | Thai | trigrams |
| `tr` | Turkish | Snowball stemmer |
| `yi` | Yiddish | Snowball stemmer |
| `zh` | Chinese | bigrams |

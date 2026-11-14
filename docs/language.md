# Language

Fuzor needs no language setting. Every word is indexed and searched as written (lowercased),
and Chinese, Japanese, Korean and Thai text is split into n-grams automatically. Two optional
schema settings change how words match: a **stemmer** and **stop words**.

## CJK and Thai

Chinese, Japanese, Korean and Thai don't use spaces between words. Fuzor splits each word written
in these scripts into overlapping character windows, at index and at query time, so no external
segmenter is needed:

| Script | N-gram |
|--------|:------:|
| Chinese characters, Japanese kana, Korean Hangul | bigrams, plus single characters |
| Thai | trigrams |

The script of each word decides, so a document that mixes scripts (a Japanese title next to an
English description, a brand name inside Chinese text) is indexed word by word, and a Latin word
at the end of the query is still matched as a prefix while it is typed. Chinese and Japanese
are treated the same, so nothing has to tell them apart.

**Tradeoffs against a real segmenter:**
- The index grows: a 10-character word becomes about 9 bigrams.
- Rare character sequences that span word boundaries can give false positives.
- There is no prefix matching or typo tolerance inside n-grams.

For high-precision Chinese search at scale, pre-segment the text (for example with jieba) and
index the space-separated output.

## Stemming

A stemmer reduces words to a common stem, so "boots" matches "boot" and "batteries" matches
"battery". Stemming is off by default and Fuzor ships no stemmers: name a class implementing
`Fuzor\Stemmer` in the schema.

```php
use Fuzor\SchemaConfig;

$index = new Index('/path/to/articles.db', schema: new SchemaConfig(stemmer: PluralStemmer::class));
$index->stemmer; // 'App\Search\PluralStemmer'
```

The class and its `id()` are stored in the index, and the stemmer is created again whenever the
index is opened, so readers pass nothing. Change it with `rebuild()`.

**When stemming helps, and when it hurts.** Stemming finds other forms of a word. Without a
stemmer, prefixes, typo tolerance and synonyms already cover many forms ("shoe" finds "shoes",
"womens" finds "women"). With a stemmer the index holds stems, so a word that is still being typed
or is misspelled is compared with stems: with a full English stemmer, which turns "hiking" into
"hike", "hikin" and "hikng" do not find "hiking". A light stemmer that only removes plural endings,
like the one below, keeps the start of each word and avoids most of that; a full stemmer finds more
in articles and other long text.

### Writing a stemmer

Implement `Fuzor\Stemmer`, with a constructor that takes no arguments. This one removes English
plural endings:

```php
namespace App\Search;

use Fuzor\Stemmer;

final class PluralStemmer implements Stemmer
{
    public function id(): string
    {
        return 'plural@1';
    }

    public function stem(string $word): string
    {
        return match (true) {
            // batteries → battery
            strlen($word) > 4 && str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            // boxes → box, glasses → glass
            strlen($word) > 4 && preg_match('/(s|x|z|ch|sh)es$/', $word) === 1 => substr($word, 0, -2),
            // boots → boot, but class stays class
            strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }
}
```

`stem()` receives one token at a time, already lowercased: a word, a number, or a CJK / Thai
n-gram. Return it unchanged when it has no stem, never an empty string, and always the same stem
for the same token. It runs for every indexed and every query word, so keep it fast; a cache of
recent words helps a slow algorithm.

To use a stemming library (a Snowball implementation for your language, for example), wrap it the
same way: create the library's stemmer in the constructor and call it in `stem()`. `id()` names the
library and its version, such as `'snowball-english@3.2.0'`.

### When the stems change

An index stores stemmed words. If a new version of a stemmer stems some words differently,
queries are stemmed the new way while the index holds the old stems, and those words stop
matching until the index is rebuilt. Fuzor notices it from the stored `id()`: the index keeps
working, `$index->stemmerChanged` is `true`, and every search result carries a warning until
`rebuild()`. So change `id()` whenever your stemmer's output changes. A stemmer class that cannot be
loaded (renamed, or its package removed) makes opening the index throw a `QueryException` that
names the class.

## Stop words

To leave words out, list them in the schema. They are left out of the index and of every query
(also quoted phrases, `-word` exclusions and the operands of `searchBoolean()`), matched
case-insensitively before stemming:

```php
$index = new Index('/path/to/articles.db', schema: new SchemaConfig(
    stopWords: ['the', 'a', 'an', 'of'],
));
$index->stopWords; // ['the', 'a', 'an', 'of']
```

Each entry must be a single word (`\InvalidArgumentException` otherwise). The list is part of the
schema: change it with `rebuild()`. A query made only of stop words searches its words as typed.
A stop word left out still keeps its place between the other words: the phrase `"bank of england"`
matches "bank of england" and "bank the england" (any one word between), `"bank england"` matches
only the two words side by side, and proximity ranking counts the stop word as a word between.
Fuzor ships no word lists: general-purpose lists remove words that matter in many catalogs (an
English list holds "c" as in "usb c cable" and "men"), so choose the words for your own data.

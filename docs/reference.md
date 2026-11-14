# API reference

Every public class, method and option, with links to the page that explains it. Everything is in
the `Fuzor` namespace. Classes and members marked `@internal` in the source are not part of the
API and may change in any release.

## `Index`

`final class Index implements \Countable`

### Opening

| Method | Returns | Description |
|---|---|---|
| `new Index(string $path, bool $force = false, ?Config $config = null, bool $readonly = false, ?SchemaConfig $schema = null)` | | Open the index at `$path`, or create it with `$schema`; an existing index must have that schema. `force` replaces an existing index with an empty one; `readonly` makes every write throw an `IOException` ([Indexing](indexing.md#creating-and-opening-an-index)) |
| `Index::exists(string $path)` | `bool` | Whether a Fuzor index is at `$path` |
| `Index::lastModified(string $path)` | `int` | Modification time of the file, `0` when there is none |
| `reopenIfChanged()` | `bool` | Reopen when a new version was published at the path ([Operations](operations.md#long-lived-workers)) |
| `close()` | `void` | Close the connection; any later call throws a `\LogicException` |
| `Index::MIN_SQLITE_VERSION` | `string` | `'3.46.0'` |
| `Index::CURRENT_SCHEMA_VERSION` | `int` | File format revision written by this version (4) |

### Properties (read-only)

| Property | Type | Description |
|---|---|---|
| `filterableAttributes` | `list<string>` | From the schema |
| `sortableAttributes` | `list<string>` | From the schema |
| `searchableAttributes` | `?list<string>` | From the schema; `null` = every field that is not filterable or sortable |
| `documentStoreEnabled` | `bool` | Whether documents are stored |
| `stripHtml` | `bool` | From the schema |
| `stopWords` | `list<string>` | From the schema, lowercased |
| `stemmer` | `?class-string<Stemmer>` | The stemmer class, `null` for none |
| `stemmerChanged` | `bool` | The stemmer's `id()` differs from the one the index was built with ([Language](language.md#when-the-stems-change)) |
| `schemaVersion` | `int` | File format revision of the open file |

### Writing

| Method | Returns | Description |
|---|---|---|
| `insert(iterable $docs, ?callable $progress = null)` | `void` | Add documents; throws on an existing or repeated ID, writing nothing ([Indexing](indexing.md#inserting)) |
| `update(iterable $docs)` | `void` | Replace existing documents; throws on a missing ID, writing nothing |
| `upsert(iterable $docs)` | `void` | Create or replace |
| `delete(int ...$ids)` | `void` | Delete documents; unknown IDs are ignored |
| `deleteByFilter(array\|Filter $filter)` | `int` | Delete the documents matching a filter; returns the number deleted |
| `clear()` | `void` | Delete every document |
| `optimize()` | `int` | Purge deleted documents from the word index; returns the number purged |
| `setSynonyms(array $equivalences = [], array $oneWay = [])` | `list<string>` | Replace all synonyms; returns the skipped multi-word entries ([Searching](searching.md#synonyms)) |
| `clearSynonyms()` | `void` | Remove all synonyms |
| `checkpoint(string $mode = 'TRUNCATE')` | `array{busy: int, log: int, checkpointed: int}` | Write the WAL back into the file; `PASSIVE`, `FULL`, `RESTART` or `TRUNCATE` ([Operations](operations.md#keeping-the-wal-small)) |

### Reading

| Method | Returns | Description |
|---|---|---|
| `search(string $phrase, SearchOptions $options = new SearchOptions())` | `SearchResult` | Ranked search; an empty phrase browses ([Searching](searching.md)) |
| `searchBoolean(string $phrase, SearchOptions $options = new SearchOptions())` | `SearchResult` | AND / OR / NOT search ([Searching](searching.md#boolean-search)) |
| `facetSearch(FacetSearchQuery $query)` | `FacetSearchResult` | Values of a field with counts ([Filtering and facets](filtering-and-facets.md#facet-value-search)) |
| `inspectQuery(string $phrase, bool $asYouType = true)` | `QueryInspection` | How the query's words were read ([Debugging](debugging.md)) |
| `get(int $id)` | `?array` | A stored document |
| `getMany(int ...$ids)` | `array<int, array>` | Stored documents by ID; missing IDs left out |
| `stream(int $batchSize = 100, int $afterId = 0)` | `\Generator<int, array>` | Every stored document after `$afterId`, by ascending ID |
| `has(int $id)` | `bool` | Whether the document exists |
| `hasMany(int ...$ids)` | `array<int, bool>` | Existence per ID |
| `count()` | `int` | Number of documents; also `count($index)` |
| `getSynonyms()` | `array<string, list<string>>` | Stored synonyms, source => targets |
| `highlighter(string $open = '<mark>', string $close = '</mark>', bool $asYouType = true, bool $escape = false)` | `Highlighter` | Highlighter for text outside a search ([Formatting](formatting.md#standalone-use)) |
| `snippeter(int $windowSize = 200, int $maxSnippets = 1, string $ellipsis = '…', bool $escape = false)` | `Snippeter` | Excerpts for text outside a search |

### Files

| Method | Returns | Description |
|---|---|---|
| `Index::rebuild(string $path, ?callable $callback = null, ?SchemaConfig $schema = null)` | `Index` | Build a new index and publish it atomically; without a callback from the document store ([Operations](operations.md#rebuilding)) |
| `snapshotTo(string $path)` | `void` | Publish a point-in-time copy at `$path` |
| `Index::install(string $file, string $path, bool $check = false)` | `void` | Check a file made elsewhere and publish it at `$path` ([Operations](operations.md#snapshots-and-moving-indexes)) |
| `Index::remove(string $path)` | `list<string>` | Delete an index with all its versions and sidecar files; returns the deleted paths |
| `Index::cleanupTempFiles(string $path, int $minAgeSeconds = 3600)` | `list<string>` | Delete files left by killed rebuilds and snapshots ([Operations](operations.md#temporary-files)) |

## `SchemaConfig`

`final readonly class SchemaConfig`, passed as `schema:` when an index is created ([Indexing](indexing.md#schema)).

| Property | Type | Default |
|---|---|---|
| `stemmer` | `?class-string<Stemmer>` | `null` |
| `store` | `bool` | `true` |
| `filterableAttributes` | `list<string>` | `[]` |
| `sortableAttributes` | `list<string>` | `[]` |
| `searchableAttributes` | `?list<string>` | `null` |
| `stripHtml` | `bool` | `false` |
| `stopWords` | `list<string>` | `[]` |

## `SearchOptions`

`final readonly class SearchOptions`, the options of `search()` and `searchBoolean()`. The
constructor checks what it can without the index (sort specs, the filter's shape and values, a field
in both `facets` and `facetStatsOnly`) and throws an `\InvalidArgumentException`; field names are
checked against the schema when the search runs.

| Property | Default | Description |
|---|---|---|
| `limit` | `20` | Hits per page |
| `offset` | `0` | Hits to skip |
| `asYouType` | `true` | Match the last word as a prefix |
| `matchingStrategy` | `MatchingStrategy::Last` | Which documents a query of several words returns ([Searching](searching.md#matching-strategy)) |
| `filter` | `[]` | `array<string, string\|int\|float\|list<string\|int\|float>\|FacetRange\|FacetExclude>`, or a `Filter` ([Filtering and facets](filtering-and-facets.md#filters)) |
| `facets` | `[]` | Fields to count values of |
| `facetStatsOnly` | `[]` | Numeric fields to return only min and max of |
| `sortFacetValuesBy` | `[]` | `array<string, FacetOrder>`, `'*'` for the rest; unlisted fields `Alpha` |
| `sort` | `[]` | `list<Sort\|string>`: `Sort::asc('price')` or `'price:asc'`; read back as `list<Sort>` ([Sorting](sorting.md)) |
| `distinct` | `null` | Field to keep one hit per value of |
| `distinctCount` | `1` | Hits kept per value |
| `attributesToRetrieve` | `null` | Fields of each hit; `null` or `['*']` all, `[]` only the ID ([Performance](performance.md#returning-less-per-hit)) |
| `attributesToHighlight` | `null` | Fields to highlight in `_formatted`; `['*']` for all ([Formatting](formatting.md)) |
| `highlightPreTag` / `highlightPostTag` | `'<mark>'` / `'</mark>'` | Highlight tags |
| `attributesToCrop` | `null` | Fields to crop to an excerpt in `_formatted`; `['*']` for all |
| `cropLength` | `200` | Excerpt length in characters |
| `cropMarker` | `'…'` | Marks where an excerpt was cut |
| `escapeFormatted` | `false` | Make `_formatted` safe HTML |
| `showRankingScoreDetails` | `false` | Add `_rankingScoreDetails` to each hit ([Debugging](debugging.md#why-a-hit-ranks-where-it-does)) |

## `SearchResult`

`final class SearchResult implements \Countable, \IteratorAggregate`, iterating over the hits.

| Member | Type | Description |
|---|---|---|
| `$hits` | `list<array>` | Documents of the page, with `_formatted` and `_rankingScoreDetails` when asked for |
| `$hitsCount` | `int` | Hits in the page; also `count($result)` |
| `$totalHits` | `?int` | Matches over all pages, up to `Limits::$totalHits` |
| `$totalHitsCapped` | `bool` | More matches than `Limits::$totalHits` |
| `$facetDistribution` | `array<string, array<string\|int, int>>` | Value => count per field |
| `$facetStats` | `array<string, array{min: float, max: float}>` | Lowest and highest number per field |
| `$exhaustive` | `bool` | `false` when the page comes from capped candidates ([Searching](searching.md#when-a-result-is-approximate)) |
| `$approximateFacets` | `list<string>` | Fields whose counts covered part of the matches |
| `$warnings` | `list<string>` | One line per cap that was hit; wording not stable |
| `$query` | `string` | The query as given |
| `$limit`, `$offset` | `?int` | The page asked for |
| `getHit(int $index, array $default = [])` | `array` | Hit at a position in the page |
| `getIds()` | `list<int>` | IDs of the page's hits |
| `toArray()` / `toJSON(int $flags = 0)` | `array` / `string` | Everything as an array or JSON |

## `Sort`

`final readonly class Sort implements \Stringable`, one spec of `SearchOptions::$sort` ([Sorting](sorting.md)).

| Member | Description |
|---|---|
| `Sort::asc(string $field)` / `Sort::desc(string $field)` | Lowest / highest values first |
| `Sort::fromString(string $spec)` | From `'field:asc'` / `'field:desc'`; `\InvalidArgumentException` for anything else |
| `$field`, `$ascending` | The field and the direction |
| `__toString()` | `'field:asc'` / `'field:desc'` |

## `FacetSearchQuery` and `FacetSearchResult`

`final readonly class FacetSearchQuery` ([Filtering and facets](filtering-and-facets.md#facet-value-search)):

| Property | Default | Description |
|---|---|---|
| `facetName` | (required) | A filterable field |
| `facetQuery` | `''` | Start of a word of the value |
| `q` | `''` | Query whose documents are counted |
| `filter` | `[]` | As `SearchOptions::$filter` |
| `limit` | `100` | Most values returned |
| `sortFacetValuesBy` | `FacetOrder::Alpha` | Value order |

`final class FacetSearchResult implements \Countable, \IteratorAggregate`, iterating over
`facetHits`:

| Member | Type |
|---|---|
| `$facetHits` | `list<array{value: string, count: int}>` |
| `$facetQuery` | `string` |
| `$exhaustive` | `bool` |
| `$warnings` | `list<string>` |
| `toArray()` / `toJSON(int $flags = 0)` | `array` / `string` |

## Filters

| Class | Description |
|---|---|
| `FacetRange` | `new FacetRange(?float $gte = null, ?float $lte = null, ?float $gt = null, ?float $lt = null)`, and `FacetRange::between($gte, $lte)`, `min($gte)`, `max($lte)`, `gt($gt)`, `lt($lt)` |
| `FacetExclude` | `new FacetExclude(string\|int\|float\|list<…>\|FacetRange $filter)`: documents that do not match |
| `Filter` | `Filter::all(array\|Filter ...$entries)`, `Filter::any(…)`, `Filter::not(array\|Filter $entry)`; `$operator`, `$entries`; `Filter::MAX_DEPTH` (32) |

See [Filtering and facets](filtering-and-facets.md).

## `Config`

`final readonly class Config`, passed as `config:` on every open ([Performance](performance.md#settings)).

| Property | Default | Description |
|---|---|---|
| `limits` | `new Limits()` | Caps on the work of a search |
| `typoTolerance` | `new TypoTolerance()` | When words may have typos |
| `fuzzyPrefixLength` | `3` | Leading characters a typo may not change |
| `fuzzyMaxExpansions` | `50` | Most indexed words a typo or a prefix expands to |
| `k1` | `1.2` | BM25 term frequency saturation |
| `b` | `0.75` | BM25 length normalisation (0–1) |
| `proximityBoost` | `1.0` | Weight of words close together; `0` off |
| `proxWindowSize` | `0` | Most documents the proximity boost is computed for; `0` as many as the page needs |
| `fieldBoosts` | `[]` | `array<string, float>` weight per field |
| `maxValuesPerFacet` | `100` | Most values per facet field; `0` all |
| `insertChunkSize` | `2000` | Documents per step of a bulk write |
| `bulkSynchronousOff` | `true` | Skip the sync to disk during bulk writes |
| `busyTimeoutMs` | `5000` | Wait for another process's write, in ms |
| `cacheSizeKb` | `65536` | SQLite page cache per connection |
| `mmapSizeBytes` | `536870912` | Memory-mapped window |

`final readonly class Limits`: `candidatesPerWord` (500), `facetCountDocs` (50,000), `totalHits`
(1,000); presets `Limits::fast()` (the defaults) and `Limits::exact(int $totalHits = 1_000)`
([Performance](performance.md#limits)).

`final readonly class TypoTolerance`: `enabled` (`true`), `minWordSizeForOneTypo` (5),
`minWordSizeForTwoTypos` (9), `disableOnNumbers` (`false`), `disableOnWords` (`[]`)
([Searching](searching.md#typo-tolerance)).

## Enums

| Enum | Cases |
|---|---|
| `MatchingStrategy` | `Last` (default), `All`, `Frequency` |
| `FacetOrder` | `Alpha` (default), `Count` |
| `MatchType` | `Exact`, `Prefix`, `Fuzzy`, `None` (in `QueryToken::$matchType`) |

All three are string-backed.

## `QueryInspection` and `QueryToken`

Returned by `inspectQuery()`; their properties are listed in [Debugging](debugging.md#how-the-query-was-read).

## `Stemmer`

`interface Stemmer`, implemented by your own class and named in `SchemaConfig::$stemmer`. It stays
the same for all of Fuzor 2.x ([Language](language.md#writing-a-stemmer)).

| Method | Contract |
|---|---|
| `id(): string` | Name and version of the stemmer's output; change it whenever a word gets another stem |
| `stem(string $word): string` | The stem of one lowercased token; the token itself when it has none, never `''`, always the same for the same token |

## `Highlighter` and `Snippeter`

Made by `Index::highlighter()` and `Index::snippeter()`, with the index's stemmer
([Formatting](formatting.md#standalone-use)).

| Method | Returns |
|---|---|
| `Highlighter::highlight(string $phrase, string $text)` | `$text` with the query's words in the tags |
| `Highlighter::highlightMany(string $phrase, array $fields)` | The same for several fields at once |
| `Snippeter::snippet(string $query, string $text)` | The best excerpt(s) of `$text` for the query |
| `Snippeter::snippetMany(string $query, array $fields)` | The same for several fields at once |

## Utilities

| Method | Description |
|---|---|
| `HtmlText::toText(string $html)` | The visible text of HTML, as `stripHtml` indexes it; convert once, it is not idempotent ([Indexing](indexing.md#html)) |
| `Tokenizer::sortKey(string $text)` | Lowercased, Latin accents folded, whitespace collapsed: a sort key for accented text ([Sorting](sorting.md#how-values-compare)) |

The other `Tokenizer` methods are `@internal`.

## Exceptions

Every exception Fuzor throws for its own reasons extends `Fuzor\Exceptions\FuzorException`, a
`\RuntimeException`:

| Exception | Thrown for |
|---|---|
| `Exceptions\QueryException` | Domain and state errors: a stemmer class that is missing or not a `Stemmer`, a missing, repeated or unknown document ID, a field outside the schema's lists, a disabled document store, a schema that differs from an existing index's, a file of another format |
| `Exceptions\IOException` | File problems: a missing directory or file, a write on a `readonly` index, a failed publish, a file that is not a Fuzor index |
| `Exceptions\FuzorException` | An SQLite library older than 3.46.0, or a 32-bit PHP; thrown by `new Index()` before any file is touched |

And from PHP and SQLite:

| Exception | Thrown for |
|---|---|
| `\InvalidArgumentException` | A malformed argument: a bad sort spec, an out-of-range setting, a stop word of several words, an empty `deleteByFilter()` filter, a malformed `Filter` entry, a filter value no document can hold, an unknown `checkpoint()` mode |
| `\LogicException` | A call on an index after `close()` |
| `\PDOException` | SQLite: a lock held longer than `busyTimeoutMs`, a full disk, a damaged file |
| `\JsonException` | A document that cannot be stored as JSON (invalid UTF-8), or a damaged stored document |

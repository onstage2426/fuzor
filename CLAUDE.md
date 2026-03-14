# Fuzor — Full-Text Search Library

PHP 8.5 · SQLite3 · MIT · namespace `Fuzor`

## Tooling

Fuzor is a lightweight full-text search library. It tokenises documents, stores an inverted index in a SQLite database, and scores search results with Okapi BM25. No external services required.

## Source layout

```
src/
├── Index.php              # Public API — static factory, search, CRUD
├── SqliteEngine.php       # All index and query logic, PDO/SQLite (private impl)
├── Tokenizer.php          # Static tokenize(): ASCII fast-path + Unicode fallback
├── Levenshtein.php        # Unicode-aware edit distance
└── Stopwords.php          # Opt-in stopword filter; one instance per Index handle

resources/
└── stopwords/
    ├── en.php             # English (and 57 other languages, one file each)
    └── {lang}.php         # BCP 47 code → PHP associative array ['word' => true, ...]
```

Stopword lists for 58 languages live in `resources/stopwords/{lang}.php` (PHP associative arrays, opcache-friendly). Filtering is opt-in via `$index->language`.

## Design decisions

- **BYOD** — Fuzor does not store your documents. It only stores the inverted index. You own your data layer.
- **SQLite only** — one file per index, zero infrastructure.
- **Four classes** — `Index` is the sole public API: static factory entry point, all search methods, and CRUD proxies. `SqliteEngine` is a private implementation detail that owns everything touching the database. `Tokenizer` is a stateless utility. `Stopwords` is an opt-in filter; one instance lives on the engine, created when `$index->language` is set.
- **PHP 8.5** — pipe operator `|>`, arrow functions, spread, `match`, first-class callables, union types throughout.
- **SQLite 3.37.0+** required for `STRICT` tables (type enforcement), `ON CONFLICT DO UPDATE … RETURNING` (wordlist upsert), `DELETE … RETURNING` (doc_lengths removal), and CTEs in DML (delete).

## SQLite schema (per index file)

| Table         | Purpose                                      |
|---------------|----------------------------------------------|
| `wordlist`    | One row per unique term: `id, term, num_hits, num_docs` |
| `doclist`     | Term→document mapping: `term_id, doc_id, hit_count`; `WITHOUT ROWID` clustered on `(term_id, doc_id)` |
| `doc_lengths` | Per-document token count for BM25 length normalisation: `doc_id, length` |
| `info`        | Key/value metadata (`total_documents`, `avg_doc_length`) |

WAL journal mode, `synchronous=NORMAL`, 16 MB page cache, and `temp_store=MEMORY` are applied on every connection.

## Usage

```php
use Fuzor\Index;

// --- Indexing ---
$index = Index::create('/path/to/articles.db');
$index->insert(['id' => 1, 'title' => 'Hello world', 'body' => 'Some content']);
$index->update(['id' => 1, 'title' => 'Updated title', 'body' => 'New content']);
$index->delete(1);

// --- Searching ---
$index = Index::open('/path/to/articles.db');

$results = $index->search('hello world');
// ['ids' => [3, 1, 7], 'hits' => 3, 'docScores' => [...]]

$results = $index->searchBoolean('hello & world | -php');
// ['ids' => [...], 'hits' => N]

// --- Stopwords (opt-in) ---
$index->language = 'en'; // filter English stopwords on insert + search
$index->language = null;  // disable (default)
// Throws \InvalidArgumentException for unknown language codes.
// If a search phrase consists entirely of stopwords, filtering is skipped so results are still returned.
```

## Search modes

### Standard (`search`)
Okapi BM25 scored with document-length normalisation. Returns `ids` sorted by relevance, total `hits`, and the raw `docScores` map. Tunable via `$index->k1` (term saturation) and `$index->b` (length normalisation).

### Boolean (`searchBoolean`)
Operators parsed via Shunting-Yard (postfix evaluation):

| Input syntax | Operator | Effect               |
|-------------|----------|----------------------|
| `space`     | `&`      | AND (intersect)      |
| ` or `      | `\|`     | OR (union)           |
| ` -term`    | `~`      | NOT (complement)     |

### Fuzzy search
Set properties on the index before searching:
```php
$index->fuzzyDistance      = 2;   // max Levenshtein distance
$index->fuzzyPrefixLength  = 2;   // prefix chars that must match exactly
$index->fuzzyMaxExpansions = 50;  // candidate terms to evaluate
```

### As-you-type prefix matching
```php
$index->asYouType = true; // default — last keyword becomes a prefix query
```

## Configuration reference

| Property               | Default | Effect                                         |
|------------------------|---------|------------------------------------------------|
| `asYouType`           | `true`  | Last keyword matched as prefix                  |
| `fuzzyPrefixLength`   | `2`     | Exact-match prefix length before fuzzy kicks in |
| `fuzzyMaxExpansions`  | `50`    | Max wordlist candidates for fuzzy evaluation    |
| `fuzzyDistance`       | `2`     | Max edit distance accepted                      |
| `maxDocs`             | `500`   | Max documents returned per keyword              |
| `k1`                  | `1.2`   | BM25 term frequency saturation parameter        |
| `b`                   | `0.75`  | BM25 document length normalisation weight (0–1) |
| `language`            | `null`  | BCP 47 language code for stopword filtering; `null` disables it |

## Public API (Index)

| Method / Property                        | What it does                                             |
|------------------------------------------|----------------------------------------------------------|
| `Index::open(string $path)`              | Opens an existing index file, returns `Index`            |
| `Index::create(string $path, bool $force = false)` | Creates a new index file + schema, returns `Index`; `$force` overwrites existing file |
| `insert(array $doc)`                     | Tokenises all fields, writes wordlist + doclist + doc_lengths |
| `insertMany(array $docs)`                | Bulk insert in a single transaction; one stats update for the whole batch |
| `update(array $doc)`                     | delete + insert (uses `$doc['id']`)                      |
| `delete(int $id)`                        | Removes doc from doclist + doc_lengths, decrements wordlist stats |
| `close()`                                | Closes the underlying database connection                |
| `search(string $phrase)`                 | BM25 ranked search (exact / as-you-type prefix)          |
| `searchFuzzy(string $phrase)`            | BM25 ranked search with Levenshtein fuzzy matching       |
| `searchBoolean(string $phrase)`          | Boolean search via Shunting-Yard postfix evaluation      |

## SqliteEngine — package-internal interface (called from Index)

| Method (public)                         | What it does                                        |
|-----------------------------------------|-----------------------------------------------------|
| `createIndex($name)`                    | Creates SQLite file + schema, returns `$this`       |
| `selectIndex($name)`                    | Opens an existing index file                        |
| `close()`                               | Closes the PDO connection                           |
| `insert(array $doc)`                    | Index document, update counts                       |
| `insertMany(array $docs)`               | Bulk index in one transaction, one stats update     |
| `update(array $doc)`                    | delete + insert                                     |
| `delete(int $id)`                       | Remove document, decrement wordlist + info stats    |
| `getDocumentsAndCount(...)`             | Combined wordlist lookup + document fetch           |
| `getAllDocumentsForWhereKeywordNot($kw)` | Boolean NOT: all docs not containing keyword       |
| `getInfoValues(array $keys)`            | Fetch values from the `info` metadata table         |
| `filterQueryTokens(array $tokens)`      | Apply stopword filter to query terms; falls back to original list if all tokens are stopwords |

## SqliteEngine — private implementation detail

| Method (private)                        | What it does                                        |
|-----------------------------------------|-----------------------------------------------------|
| `processDocument(array $row)`           | Tokenises fields, writes wordlist + doclist + doc_lengths; returns token count |
| `upsertWordlist(array $stems)`          | Batched upsert via `ON CONFLICT DO UPDATE RETURNING id, term` (chunked at 499 terms) |
| `saveDoclist(array $terms, int $docId)` | Batched insert of term→doc hit counts (chunked at 333 terms) |
| `saveDocLength(int $docId, int $length)` | Writes document token count for BM25 length norm  |
| `adjustStatsInsert(int $count)`         | Increments `total_documents`, recalculates `avg_doc_length` after insert |
| `adjustStatsDelete(int $count)`         | Decrements `total_documents`, recalculates `avg_doc_length` after delete |
| `adjustStatsReplace(int $old, int $new)` | Updates `avg_doc_length` for update (delete + insert) |
| `wrapInTransaction(callable $fn)`       | Executes callable in a transaction with rollback on exception |
| `getWordlistByKeyword(...)`             | Exact or prefix lookup, falls through to fuzzySearch |
| `getAllDocumentsForStrictKeyword(...)`   | Fetch doclist rows for exact/prefix wordlist matches |
| `getAllDocumentsForFuzzyKeyword(...)`    | Fetch doclist rows for fuzzy wordlist matches       |
| `fuzzySearch(string $kw)`              | Levenshtein scan over wordlist prefix candidates    |
| `stmt(string $key, string $sql)`       | Return a cached PDOStatement, preparing it on first use; cache reset on new connection |

## Conventions

- **PSR-12** coding standard. Line soft-limit 120 chars. All control structures use braces.
- PHP 8.5 features are used where they add clarity: `|>` for data pipelines, `fn` for short transforms, spread `[...$a, 'k'=>$v]` for immutable array extension, `match` for dispatch.
- Named placeholders (`:key`) are used for single-row statements; positional `?` params are used in batched multi-row inserts (`saveDoclist`). No string interpolation in WHERE clauses.
- Exceptions are thrown on unexpected errors; callers are responsible for wrapping in try/catch as needed.

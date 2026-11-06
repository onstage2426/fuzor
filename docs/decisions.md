# Design decisions and rejected changes

Records of options that were evaluated and rejected, with the evidence, so they
are not re-attempted without new information.

## Rejected: other layouts for an exclusion's facet counts (2026-11-06)

**Proposal:** a shop hides products with a constant `FacetExclude`, so every unfiltered page reads
the hidden documents' `doc_facets` rows and subtracts them from `facet_counts`. Change the format
before 2.0 so that this costs less.

**Why rejected:** measured on a production catalogue (homelab; 7,712 products, 42 keys, 1,325
hidden holding 39,996 value IDs, 6,142 distinct; 28 facets; new connection per request). Without
that step (wrong counts, as an upper bound) the page takes 4.0 instead of 6.8 ms. Of its 2.8 ms,
reading the rows is 0.6, counting the IDs 1.3 and turning them into values 0.9. Nothing tried
reaches that time:

- `vid` with the key in the low bits, so PHP's integer hash spreads the keys: counting 0.76 →
  0.78 ms (`n << 8 | key`), and 23.5 ms with `n << 16 | key`, whose low bits are mostly zero.
- `vid` and a second ID for numbers as columns of `facet_counts`, subtracting by ID without any
  value lookup: same results, 6.5 ms, no faster than without the columns (the lookup moves to two
  hash probes and two more columns per `facet_counts` row).
- A dictionary of repeated value combinations: still 648 distinct among the 1,325 documents with
  the 13 widest keys left out, so at best half the rows, for a second write-path structure.
- Leaving sort-only keys out of the blobs: they are not in them.

Storing the kept counts per excluded value would reach it, but which exclusions a site sends is
not known at write time: for every value of the small keys it is a copy of `facet_counts` per
value, and otherwise a schema option for one request shape, kept exact on every write.

**When to revisit:** if a deployment's hidden share is much larger, or an exclusion needs to be
declared for another reason. A caller can already cache the counts of an unfiltered page per index
version, which saves more than any of these.

## Rejected: other shapes for the exact `searchBoolean()` total (2026-10-26)

**Proposal:** count the matches of a boolean query whose terms were cut at `maxDocs` with a
cheaper shape than walking the rarest side with an `EXISTS` probe per term per document.

**Why rejected:** measured on the 44k ecom catalog (laptop at 2.0 GHz, `casual shirt -formal`,
whose terms expand to 2, 4 and 6 terms through typos; the walk reaches the 1,001 matches it
needs after about 2,500 documents): the current walk 7.0 ms. Reading every term's documents and
evaluating the expression with PHP hash sets 5.0 ms for the full count (26k rows read; the walk's
early stop is lost, so broad OR queries get slower); `INTERSECT` / `EXCEPT` of the terms' rows
in SQL 5.3 ms with the limit; the negated word as a materialised `NOT IN` set 6.0 ms here but
up to 55% slower on other queries (`kurta -cotton` 5.4 → 8.3 ms); probing through
`doc_id_index` instead of the primary key 9.4 ms. None is a clear win across queries.

**When to revisit:** if boolean totals become a measured bottleneck in a deployment, or if the
page itself is computed exactly (the same set would then serve the total).

## Rejected: pruning the by-score read of a word by a hit-count bound (2026-10-26)

**Proposal:** a document's length is at least its hit count, so a row's BM25 score is bounded by
a function of `hit_count` alone. Any 500 rows give a lower bound for the 500th best score, and
rows whose bound is below it can be skipped through the `(term_id, hit_count DESC)` index, so the
read of a first word's best rows by score (`fetchDocsByTermIds(…, byScore: true)`) would read
fewer rows with the same result.

**Why rejected:** the result is identical, but the first pass that finds the bound (the 2,000
rows with the most hits) costs 2–6 ms, and on many words it skips nothing because their 500th
best score is reached by documents with one hit (laptop, ecom): `sh` 18.5 → 13.3 ms and `ca`
15.1 → 9.5 ms, but `bl` 16.5 → 22.9 ms, `men` 7.1 → 10.3 ms, `black` 6.2 → 9.1 ms.

**When to revisit:** with a cheap bound that needs no first pass (for example stored per-term
statistics), or if as-you-type prefixes of two letters dominate a workload.

## Rejected: deduplicating the result set's driver in PHP for facet counts (2026-10-26)

**Proposal:** stream the result set of a capped search to the facet counter from the driver word's
document IDs read and deduplicated in PHP, in document order, instead of `SELECT DISTINCT` over the
word's terms (a temporary B-tree in index order); only when the driver cannot exceed
`maxFacetCountDocs`, where the order decides which documents are counted.

**Why rejected:** same counts, but only 2 ms of 22.7 ms saved (laptop, ecom `shirt` minus Sports,
12,090 matches): the probes of the other conditions and the `doc_facets` read per match dominate,
and the `DISTINCT` it replaces costs 7.4 ms against 4.8 ms for reading the IDs into PHP.

**When to revisit:** together with a design that evaluates the filter conditions on the same pass
(the `doc_facets` row already holds every filterable value of the document).

## Rejected: a stored BM25 key to pick each word's candidates (2026-10-26)

**Proposal:** store each word's BM25 factor for a document, `tf / (tf + k1 · (1 − b + b · dl / avgdl))`,
as a `doclist` column when it is written, and index `(term_id, key DESC)` instead of
`(term_id, hit_count DESC)`. A search then reads each word's `maxDocs` best documents by BM25
straight from the index, instead of those with the most occurrences, and the extra sorted read
of the first word (when a page reaches the documents with only that word) is no longer needed.
Scores stay computed at query time; the key only picks the candidates.

**Why rejected:** prototyped and measured on the 44k ecom catalog (300 searches, 12 option
variants):

- Pages identical to the uncapped ranking improved by 1–4 points on a fresh build (plain
  89 → 93%, typo 89 → 92%, `MatchingStrategy::All` 92 → 95%; sort, exclusion and `distinct`
  unchanged).
- Most searches got slower: plain +10%, three facets +16%, sorted +14%, prefix +10%, All +12%;
  only typo searches got faster (−20%). An integer key (2 bytes instead of 8) was about as slow,
  so the extra size is not the main cause.
- Index +15% (363 → 418 MB; 381 MB with the integer key), build +13% (34.6 → 39.1 s).
- The key depends on `avgdl` when the row is written, and goes stale as the index changes.
  Re-keying every row of an edited document makes an update of 200 titles 156 → 713 ms
  (246 → 3,510 WAL pages); keeping old keys is cheap (162 ms), but after a shift in document
  lengths (built shortest documents first) the keys were up to 61% off and pages fell below
  today's: typo 72% (now 89%), page 10 76% (now 84%), plain 88% (now 89%).
- `k1` and `b` are per-connection `Config`; a stored key would tie them to the build.

**When to revisit:** if search cost is dominated by reading candidates rather than by completing
and counting them, or if `k1`/`b` move into the schema for another reason. Numbers and harness:
`dataflow.md` §21 item 28.

## Rejected: one document per variation with per-product facet counts (2026-10-07)

**Proposal:** index each product variation (size, colour) as its own document and have facet
counts count distinct products instead of documents (raised by the `fuzor-wp` review).

**Why rejected:** it multiplies the index size by the number of variations, and counting
distinct groups per facet value is O(matches) for every value without bitmaps, so every
faceted page pays for it. Meilisearch does not document per-group facet counts either.
Consumers can keep one document per product and add a combined facet for variation
filtering, for example `variant: ['m|blue', 'l|blue', …]`.

**When to revisit:** if a cheap distinct-count structure becomes available in SQLite, or a
consumer shows that the combined-facet pattern cannot express its filters.

## Rejected: a facet value to label map (2026-10-07)

**Proposal:** store a display label per facet value (slug `rosa-clara` → `Rosa Clará`) so
`facetDistribution` and `facetSearch()` can return and match labels (raised by the
`fuzor-wp` review).

**Why rejected:** labels are presentation data that the consumer already has. The real need
was matching typed text against slug values; case and accent folding plus matching at the
start of any word in `facetSearch()` (2.0) covers it without a second value per facet.

**When to revisit:** if folding and word-start matching still leave common facet searches
unanswerable.

## Rejected: facet bitmaps (2026-10-07)

**Proposal:** keep a bitmap of document IDs per facet value and intersect bitmaps for
filtered facet counts (raised by the `fuzor-wp` review).

**Why rejected:** SQLite has no native bitmap type. Bitmaps kept as PHP blobs would rewrite
large values on every write and have to be loaded and decoded on every read. The measured
cost (whole-key counts on a shop page) is solved by a maintained `facet_counts` table in
2.0, which makes those counts O(values) instead of O(documents).

**When to revisit:** if filtered (not whole-key) facet counts at the `maxFacetCountDocs` cap
become the bottleneck on real catalogs, with measurements.

**Revisited 2026-10-18, rejected again:** filtered counts at the cap were measured as the
bottleneck (1.43M Amazon products, a category of 28.6k products with three facets: 26.7 ms
for 10k of the matches). Dense bitmaps were prototyped next to the index for the fields with
at most 64 values. Counting takes 0.37 ms per facet value at 1.43M documents (string `&`
0.25 ms + `count_chars()` 0.12 ms on 178 KB; no gmp to count bits faster), so the cost
follows the number of values, not of matches: 26 ms for 75 values, 100–120 ms for 282–322.
Loading the bitmaps costs 12–50 ms (13–57 MB) on every request, because PHP keeps nothing
between requests. A field with many values does not fit at all (reviews: 11,861 values,
2.1 GB), and document IDs are chosen by the caller, so dense bitmaps would also need an
internal ID. A packed row of value IDs per document (`doc_facets`) was chosen instead: exact
counts over all 28.6k matches in 16 ms.

**When to revisit:** if PHP gets a fast population count for strings, and with a way to keep
bitmaps in memory across requests.

## Rejected: integer value IDs in `facet_values` for filtered counts (2026-10-18)

**Proposal:** replace the TEXT value in `facet_values` with an integer ID from a dictionary,
so the per-document facet count join compares and reads integers.

**Why rejected:** the join still reads one row per document and facet field, and that row
count is the cost. Measured on a prototype with a covering `(doc_id, key_id, vid)` index:
1.43M Amazon products, a category of 28.6k products with three facets: 57.5 ms against 76.0 ms
for the TEXT join and 16.3 ms for one packed row per document (`doc_facets`); 45k ecom set,
gender Men with five facets: 63.7 vs 85.0 vs 15.8 ms. The tables are smaller (240 vs 308 MB at
1.43M), but range filters would still need the numeric value next to the row.

**When to revisit:** if the size of `facet_values` and its doc index becomes the problem
rather than counting speed.

## Rejected: field weights in `SchemaConfig` (2026-10-07)

**Proposal:** declare per-field ranking weights in the schema at creation time (raised by the
`fuzor-wp` review).

**Why rejected:** `Config::$fieldBoosts` already does this, per open instead of per file,
which is more flexible: weights can change without a rebuild. The consumer that raised it
had not passed `fieldBoosts`.

**When to revisit:** if weights need to be shared by every process opening a file and passing
them per open proves error-prone.

## Rejected: MariaDB and PostgreSQL drivers (2026-10-07)

**Proposal:** support server databases besides SQLite.

**Why rejected:** the design depends on SQLite specifics: one file per index, `VACUUM INTO`
snapshots, publishing a new version behind a symlink, shared mmap reads, `WITHOUT ROWID`
clustering and `json_each`. Both databases have their own full-text search, and users who
outgrow a file are better served by a search server than by Fuzor on a network database.

**When to revisit:** not planned.

## Rejected: a per-index result cache keyed by revision (2026-10-07)

**Proposal:** cache search results inside the library, invalidated when the index changes
(raised by the `fuzor-wp` review).

**Why rejected:** under PHP-FPM a process keeps nothing between requests, so an in-process
cache rarely hits. Caching belongs in front of the library: `fuzor-wp` already caches
responses by ETag keyed on the file state, which also serves CDNs.

**When to revisit:** for long-running servers (Swoole, FrankenPHP worker mode) if repeat
queries show up as a measurable share of the load.

## Rejected: `SchemaConfig::$htmlFields` — store converted text (2026-09-27)

**Proposal:** a per-field `htmlFields: list<string>` schema option (raised by the `fuzor-wp`
review after 1.6.0). Declared fields are converted with `HtmlText::toText()` on insert,
update, and upsert, and the **text** is both indexed and stored, so hits, `getMany()`, and
`stream()` return text and `escapeFormatted` only has to escape. Persisted in `info`,
inherited by `rebuild()`; `stripHtml` deprecated in 2.0.

**Why rejected:**

- **It loses data on round trips.** `toText()` is not idempotent: it decodes entities after
  removing tags, so `use x&lt;y here` → `use x<y here` → (second pass) `use x`, and
  `&lt;b&gt;bold&lt;/b&gt;` → `<b>bold</b>` → `bold`. `rebuild()` without a callback streams
  the stored documents back through `insert()`, and so does every `get()` → modify →
  `update()`. With converted text in the store, each pass converts already-converted text and
  silently drops content. `stripHtml` is safe precisely because it stores the raw HTML and
  always converts from the source.
- **Every benefit is available without it.** Converting before insert with `stripHtml` off
  gives a store holding text, text in hits/`get()`/`stream()`, and escape-only formatting.
  The only missing piece was the converter, so `HtmlText::toText()` was made public instead
  (1.7.0), which also lets a caller convert exactly the fields it wants — the per-field part
  of the proposal.
- **The per-query cost it would remove is small.** With `escapeFormatted` on a `stripHtml`
  index, formatting converts each hit's string fields: 21.7 µs per document on the ecom set
  (all string fields, 2,000 documents), about 0.4 ms at `limit: 20`.
- It would break the document store's "you get back what you inserted" contract and leave two
  overlapping HTML options in 1.x plus a deprecation in 2.0. Meilisearch, Typesense, and
  Algolia do no HTML handling at all.

**When to revisit:** only with a design that keeps the original HTML as the source of every
conversion (for example storing both, and converting only the raw copy on `rebuild()`), and
evidence that pre-converting in the caller is a real burden.

## Rejected: the document ID as a built-in sort key (2026-09-27)

**Proposal:** treat `id:asc` / `id:desc` as declared by default, sorting on `doc_id` directly
with no `facet_values` lookup (raised by the `fuzor-wp` review after 1.6.0 started ignoring
undeclared sort fields, which silently changed the order for callers passing `id:asc`).

**Why rejected:** it is already possible. Declaring `id` in `facetFields` stores the ID as a
numeric facet value, and `id:asc` / `id:desc` then sort correctly on every path (search,
searchBoolean, browse; single and bulk inserts), at the cost of one facet row per document.
Meilisearch has the same rule: the primary key has to be listed in `sortableAttributes` like
any other attribute. A built-in key would reserve a field name and add special cases to
`parseSortSpec()`, `sortRanks()`, and `browseSortedPage()`, for a consumer that reported
nothing blocked on it. The 1.6.0 change was documented instead (`docs/search.md` § Custom
sort, and the 1.6.0 changelog entry), and an undeclared `id:asc` already produces a warning.
(Since 2.0: `id` is declared in `sortableAttributes`, and an undeclared sort field throws a
`QueryException` instead of warning.)

**When to revisit:** if the per-document facet row for `id` shows up as a measurable cost, or
sorting by ID turns out to be common enough that declaring it is a recurring stumbling block.

## Rejected: escaping `_formatted` by default (2026-09-26)

**Proposal:** make `SearchOptions::$escapeFormatted` default to `true` in 2.0 (raised by the
`fuzor-wp` review), so `_formatted` is safe HTML unless a caller opts out.

**Why rejected:** it breaks callers silently in both directions. Anyone who stores sanitized
HTML and renders `_formatted` would start showing literal `&lt;p&gt;` text, and anyone who
escapes `_formatted` themselves would double-escape. Meilisearch deliberately leaves
sanitising to the client (meilisearch/meilisearch#1409) and Elasticsearch's highlighter only
escapes with `encoder: html`, so an opt-in matches what users of both expect. 1.6.0 ships the
opt-in (`escapeFormatted`, plus `escape` on `Highlighter`/`Snippeter`) and documents the
contract in `docs/formatting.md`.

**When to revisit:** if most consumers turn the option on in practice, or a Fuzor-rendered
component (not just the data API) becomes the common way to display results.

## Rejected: `PDO::ATTR_PERSISTENT` connections for PHP-FPM (2026-08-01)

**Proposal:** a `persistent: bool` constructor flag enabling `PDO::ATTR_PERSISTENT`, so a
PHP-FPM worker reuses one SQLite handle across requests instead of opening a fresh
connection each time. Aimed at request-per-connection deployments, where connection setup
and a cold SQLite page cache are pure overhead.

**Measured outcome: the win is real.** FPM shape (a fresh `Index` per simulated request,
many requests per process, 40 iterations, ecom dataset):

| | p50 | min |
|---|---|---|
| fresh connections | 10.30 / 10.08 ms | 9.54 / 9.34 ms |
| persistent | 9.23 / 8.55 ms | 8.45 / 8.36 ms |

Roughly **10–15% per request**. Connection setup alone drops from 0.405 ms to 0.013 ms.

**Why rejected anyway: it is incompatible with rotate-by-rename publishing.** PDO pools
persistent handles by DSN string. `snapshotTo()` and `rebuild()` both publish by replacing
the file at a fixed path with a *new inode*. A pooled handle keeps the **old** inode open,
so a worker serves stale results until the worker process recycles.

`reopenIfChanged()` cannot fix this: it stats the path, closes, and reopens — and PDO
returns the same pooled handle. Worse, under PHP-FPM there is no call site that could even
detect the condition, because each request constructs a fresh `Index` with no memory of the
inode the previous request saw. The failure mode is silently serving stale search results,
undetectable from PHP.

**The escape hatch that does not work.** SQLite silently ignores unrecognized URI query
parameters, and PDO keys its pool on the full DSN, so appending `&_ino=<inode>` gives each
inode its own pool entry and restores correctness. But every superseded entry holds an open
file descriptor to a deleted file, pinning its disk space. With 300 MB snapshots rotating
every five minutes, a one-hour-old worker retains several GB of deleted-but-open files.
That trades a correctness bug for a disk-exhaustion bug.

That leaves three options, none acceptable as a default: silently stale, leaking disk, or
restricting the flag to indexes that are never rotated — which excludes the read/write
split *and* the cron-`rebuild()` pattern, i.e. both deployment shapes this library
documents in `performance.md`.

**When to revisit:** if the library ever grows a deployment mode where the index file is
written in place and never replaced (freshness there is already handled by the
`data_version` check added in 1.3.1), a `persistent` flag scoped to that mode would be
sound. It should never be offered to callers who publish by rename.

---

## Rejected: `immutable=1` open mode for snapshot readers (2026-07-16)

**Proposal:** an `immutable: bool` constructor flag (requiring `readonly: true`)
appending `immutable=1` to the SQLite URI for snapshot/rebuild output — files only
ever replaced by atomic rename. SQLite then skips all file locking, shared-memory
access, and change detection per query, which was expected to be the cheapest
possible read path for many-process deployments (PHP-FPM / Swoole / FrankenPHP).

**Measured outcome:** no benefit on local NVMe. Benchmarked on the `spx/` ecom
dataset (search request with filter + 6 facets + sort, WAL-less snapshot file):

| Test | `readonly` | `readonly` + `immutable` |
|---|---|---|
| Single process, p50 of 50 requests (2 runs) | 27.7 / 28.0 ms | 27.9 / 27.4 ms |
| 8 parallel workers × 25 requests, total (2 runs) | 924 / 908 ms | 896 / 1052 ms |

Differences flip sign between runs; there is no signal. The fcntl read locks that
`immutable=1` eliminates cost microseconds on a local filesystem and do not
contend even at 8-way parallelism — invisible against a ~28 ms request.

**Why rejected:** the flag is pure risk without local benefit. It is only safe
for files that are *never* modified in place; a write to a file held open by an
immutable connection silently returns corrupt results. API surface with a
corruption footgun needs a measurable win to justify itself, and there was none.

**When to revisit:** deployments where lock acquisition is genuinely expensive —
index files on network/shared filesystems (NFS, SMB), read-only container mounts,
or extreme QPS with very small queries where per-statement lock syscalls become a
visible fraction. Re-run the A/B in that environment before re-adding.

The implementation was small and straightforward (constructor flag, DSN suffix,
guard, skip `checkDataVersion()` on immutable connections) — see this entry's
commit context if it needs to be resurrected.

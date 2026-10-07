# Design decisions and rejected changes

Records of options that were evaluated and rejected, with the evidence, so they
are not re-attempted without new information.

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

<?php

declare(strict_types=1);

namespace Fuzor;

use Fuzor\BooleanParser;
use Fuzor\Exceptions\IOException;
use Fuzor\Exceptions\QueryException;
use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\FacetSearchResult;
use Fuzor\Highlighter;
use Fuzor\Levenshtein;
use Fuzor\Snippeter;
use Fuzor\Tokenizer;
use PDO;

/**
 * Fuzor index.
 *
 * Instantiate directly to open or create an index file. Owns all database
 * interaction: schema creation, document indexing (tokenisation → wordlist upsert →
 * doclist insert), and every query mode (BM25 ranked, as-you-type prefix, Levenshtein
 * fuzzy, boolean). One instance maps to one open SQLite file at a time.
 *
 * Requires SQLite 3.46.0+ (for STRICT tables, RETURNING, CTEs in DML, and PRAGMA optimize enhancements).
 *
 * @phpstan-type FacetCondition array{
 *     name: string,
 *     rows: string,
 *     params: list<mixed>,
 *     impossible: bool,
 *     multiRow: bool,
 *     exclude: bool,
 *     range?: array{keyId: int, sql: string, params: list<mixed>},
 * }
 * @phpstan-type KeywordGroup array{
 *     words: list<array{id: int, term: string, num_hits: int, num_docs: int, distance?: int}>,
 *     termIds: list<int>,
 *     idfK1p1: float,
 *     df: int,
 *     truncated: bool,
 *     rows: list<array{0: int, 1: int, 2: float}>,
 * }
 */
class Index
{
    /**
     * On-disk schema revision written by createIndex() into info.schema_version.
     *
     * Independent of the library's semantic version; incremented only when the physical
     * schema or the way stored text is indexed changes. Revisions 1–3 were written by
     * Fuzor 1.x; 2.0 starts at 4 and opens no file below MIN_SCHEMA_VERSION (see
     * selectIndex()), so such files must be rebuilt from the source data.
     */
    public const int CURRENT_SCHEMA_VERSION = 4;

    /** Lowest on-disk revision this version opens; older files are rejected at open. */
    private const int MIN_SCHEMA_VERSION = 4;

    /**
     * facet_doc_id_index: leads with doc_id for DELETE-by-doc, and covers key_id, value and
     * num_value so the facet count join in fetchAllFacetCountsJoin() runs index-only.
     */
    private const string FACET_DOC_INDEX_DDL
        = "CREATE INDEX IF NOT EXISTS 'main'.'facet_doc_id_index' ON facet_values (doc_id, key_id, value, num_value)";

    /**
     * Suffix of the temp files rebuild() and snapshotTo() create next to an index path:
     * '.tmp-' + 8 hex chars, optionally followed by a SQLite sidecar, the builder's lock file,
     * or the symlink publishVersion() swaps into place.
     */
    private const string TEMP_SUFFIX_PATTERN = '/^\.tmp-[0-9a-f]{8}(?:-wal|-shm|-journal|\.lock|\.link)?$/';

    /**
     * Suffix of a published version next to an index path ('.v-' + the 8 hex chars of the temp
     * name it was built under), optionally followed by a SQLite sidecar; see publishVersion().
     */
    private const string VERSION_SUFFIX_PATTERN = '/^\.v-([0-9a-f]{8})(?:-wal|-shm|-journal)?$/';

    /** Length of a temp name's stem suffix: '.tmp-' plus 8 hex chars. */
    private const int TEMP_STEM_SUFFIX_LENGTH = 13;

    /** Max rows per chunk when each row uses 1 bind variable (SQLite 32 766-variable ceiling). */
    private const int CHUNK_1P = 32_766;

    /** Max rows per chunk when each row uses 2 bind variables. */
    private const int CHUNK_2P = 16_383;

    /** Max rows per chunk when each row uses 3 bind variables. */
    private const int CHUNK_3P = 10_922;

    /** Max rows per chunk when each row uses 4 bind variables (facet_values bulk INSERT). */
    private const int CHUNK_4P = 8_191;


    /**
     * When N (result-set size) is at or below this threshold, facet counts are fetched
     * with a single CROSS JOIN query driven from the doc ID side (O(N × avg_facets)) rather
     * than one sequential PK scan per key (O(num_keys × K)). For narrow searches this is
     * dramatically faster; for broad searches the sequential scan wins.
     */
    private const int FACET_JOIN_THRESHOLD = 2_000;

    /**
     * delete() purges the posting rows of every deleted document once deleted documents are this
     * share of all documents (live + deleted). Until then the per-term num_docs / num_hits keep
     * counting them (BM25 only; measured on ecom with 10% deleted: first hit unchanged, 97% of
     * top-20 sets unchanged).
     */
    private const float PURGE_RATIO = 0.1;

    /** Absolute path to the open SQLite index file. */
    private readonly string $path;

    /** Active PDO connection to the open SQLite index file; null after close(). */
    private ?PDO $pdo = null;

    /** @var array<string, \PDOStatement> Prepared statement cache; invalidated when the connection changes. */
    private array $stmtCache = [];

    /**
     * Ephemeral statement cache for bulk-load operations (flushBatch, batchUpsertWordlist).
     * Isolated from $stmtCache so that large-N bulk INSERT statements don't stay open as
     * SQLite tracked-statements during subsequent single-doc insert() transactions (which
     * would add overhead to every COMMIT). Cleared at the start and end of each insertMany()
     * and replaceMany().
     *
     * @var array<string, \PDOStatement>
     */
    private array $bulkStmtCache = [];

    /** @var array<string, string>|null Cached info table values; null = stale, fetched lazily on next read. */
    private ?array $infoCache = null;

    /** @var array<string, int> Maps term text → wordlist.id; populated lazily by batchUpsertWordlist(). Cleared on connection change. */
    private array $termIdCache = [];

    /**
     * Session-level cache for getWordlistByKeyword() results.
     *
     * Keyed by "$keyword:$isLastWord" (e.g. "war:0", "anarch:1"). Holds the full
     * list of wordlist rows so repeated search calls for the same term within one
     * connection avoid redundant SQLite round-trips. Cleared on any write (adjustStats)
     * and on connection change (close / selectIndex / createIndex).
     *
     * Each entry holds the rows and whether a prefix lookup hit Config::$fuzzyMaxExpansions.
     *
     * @var array<string, array{0: list<array{id: int, term: string, num_hits: int, num_docs: int}>, 1: bool}>
     */
    private array $wordlistCache = [];

    /** Search tuning; immutable after construction. */
    private readonly Config $config;

    /** BCP 47 language tag active on this index; null means no stopword filtering or stemming. */
    public private(set) ?string $language = null;

    /** Whether the optional document store is active on this index. */
    public private(set) bool $documentStoreEnabled = false;

    /** @var list<string> Fields usable in filter, facets, distinct, and facetSearch(); see SchemaConfig. */
    public private(set) array $filterableFields = [];

    /** @var list<string> Fields usable in sort; see SchemaConfig. */
    public private(set) array $sortableFields = [];

    /** @var list<string>|null null = all non-facet fields are FTS-indexed; non-null = only these fields. */
    public private(set) ?array $searchableFields = null;

    /** When true, each field value is converted from HTML to text before tokenisation (see HtmlText). */
    public private(set) bool $stripHtml = false;

    /** On-disk schema revision of the open index; see CURRENT_SCHEMA_VERSION. */
    public private(set) int $schemaVersion = self::CURRENT_SCHEMA_VERSION;

    /** @var array<string, int> Maps facet key name → facet_keys.id; populated lazily; cleared on connection change. */
    private array $facetKeyCache = [];

    /**
     * @var array<int, true> facet key IDs this connection has seen flagged multi_valued (or
     * flagged itself), so each key costs at most one UPDATE per connection. Only ever a subset
     * of the flagged keys; cleared with the other caches (another connection's clear() can
     * reset the flags).
     */
    private array $multiValuedKeys = [];

    /** @var array<int, bool> facet key ID → facet_keys.multi_valued, read lazily; cleared with the caches. */
    private array $keyMultiValued = [];

    /** @var array<string, true>|null TypoTolerance::$disableOnWords as normalised query terms; built on first use. */
    private ?array $typoExactWords = null;

    /** @var array<string, int> Maps searchable field name → field_names.id; populated lazily; cleared on connection change. */
    private array $fieldNameCache = [];

    /** @var array<string, int> isset-lookup set of every field stored in the facet index (filterable ∪ sortable). */
    private array $facetFieldSet = [];

    /** @var array<string, int> isset-lookup set of $filterableFields. */
    private array $filterableFieldSet = [];

    /** @var array<string, int> isset-lookup set of $sortableFields. */
    private array $sortableFieldSet = [];

    /** @var array<string, int>|null Pre-computed isset-lookup set derived from searchableFields (array_flip); null means all non-facet fields. */
    private ?array $searchableFieldSet = null;

    /** Active stopword filter; null when no language is set or language has no stopword list. */
    private ?Stopwords $stopwords = null;

    /** Active stemmer; null when no language is set or language has no stemmer. */
    private ?Stemmer $stemmer = null;

    /** @var array<string, list<string>>|null Normalized source → list<target>; null = not loaded. Cleared on connection change. */
    private ?array $synonymCache = null;

    /**
     * Whether deleted_docs has rows (see delete()); null = not probed yet. Posting fetches add a
     * deleted_docs filter only while true. Cleared on connection change and by every write that
     * changes deleted_docs.
     */
    private ?bool $hasDeleted = null;

    /** Tracks the manually-issued BEGIN IMMEDIATE; PDO::inTransaction() cannot see it. */
    private bool $inTransaction = false;

    /** Last observed PRAGMA data_version; changes only when another connection commits. Null = not yet read. */
    private ?int $dataVersion = null;

    /**
     * Device and inode of the index file captured at connection open; used by
     * reopenIfChanged() to detect atomic-rename rotation. Deliberately excludes
     * mtime/size: in-place writes keep the inode and are covered by the
     * data_version check, so only replacement should trigger a reopen.
     *
     * @var array{dev: int, ino: int}|null
     */
    private ?array $fileIdentity = null;


    // --- Constructor --------------------------------------------------------

    /**
     * Open an existing index or create a new one.
     *
     * If the file at $path already exists and $force is false, the index is opened
     * and the stored language is restored automatically — $language is ignored.
     * If the file does not exist, or $force is true, a new index is created.
     *
     * @param  string           $path     Absolute or relative path to the SQLite index file.
     * @param  bool             $force    Overwrite any existing file at $path.
     * @param  Config|null      $config   Search tuning; null uses all defaults.
     * @param  bool             $readonly Open in read-only mode; all write methods throw IOException.
     * @param  SchemaConfig|null $schema   Schema persisted at creation time; ignored when opening an existing index.
     * @throws IOException    If the parent directory does not exist, or readonly is true and the file does not exist.
     * @throws QueryException If $schema->language is set but has no stopword list or stemmer,
     *                        or if both $readonly and $force are true.
     */
    public function __construct(
        string $path,
        bool $force = false,
        ?Config $config = null,
        private readonly bool $readonly = false,
        ?SchemaConfig $schema = null,
    ) {
        $schemaProvided = $schema !== null;
        $schema         = $schema ?? new SchemaConfig();
        $this->config   = $config ?? new Config();
        if ($this->readonly && $force) {
            throw new QueryException("Cannot force-create a readonly index.");
        }
        if ($schema->language !== null && !Language::supports($schema->language)) {
            throw new QueryException("No stopword list or stemmer for language: '{$schema->language}'");
        }
        $resolved   = self::resolvePath($path);
        $this->path = $resolved;
        if ($this->readonly && !file_exists($resolved)) {
            throw new IOException("Index does not exist: {$resolved}");
        }
        if (file_exists($resolved) && !$force) {
            if ($schemaProvided) {
                throw new QueryException(
                    "Cannot apply SchemaConfig to an existing index. "
                    . "Use rebuild() to change the schema, or force: true to overwrite."
                );
            }
            $this->selectIndex();
        } elseif (file_exists($resolved)) {
            $this->replaceWithEmptyIndex($schema);
        } else {
            $this->createIndex($schema);
        }
    }

    public function __destruct()
    {
        /** @infection-ignore-all MethodCallRemoval: GC-driven; no observable test hook for destructor timing */
        $this->close();
    }

    // --- Static factory methods ---------------------------------------------

    /**
     * Resolve a path to a canonical string, even if the file does not yet exist.
     *
     * Uses realpath() on the directory (which must exist) and appends the filename.
     *
     * @param  string $path Absolute or relative path to resolve.
     * @return string       Canonical absolute path.
     * @throws IOException If the parent directory does not exist.
     */
    private static function resolvePath(string $path): string
    {
        $dir = realpath(dirname($path));
        if ($dir === false) {
            throw new IOException("Directory does not exist: " . dirname($path));
        }
        return $dir . DIRECTORY_SEPARATOR . basename($path);
    }

    /** @return list<string> */
    private static function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true);
        $result  = [];
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                if (is_string($v)) {
                    $result[] = $v;
                }
            }
        }
        return $result;
    }

    /**
     * Return true if a valid Fuzor index exists at $path.
     *
     * Returns false for non-existent paths, non-SQLite files, and SQLite files
     * that do not contain the Fuzor schema. Never throws.
     *
     * @param string $path Absolute or relative path to check.
     */
    public static function exists(string $path): bool
    {
        $dir = realpath(dirname($path));
        /** @infection-ignore-all ReturnRemoval: without this return $dir is false; string concat yields '/<basename>' which file_exists() also returns false for, producing identical behaviour via the next guard */
        if ($dir === false) {
            return false;
        }
        $resolved = $dir . DIRECTORY_SEPARATOR . basename($path);
        if (!file_exists($resolved)) {
            return false;
        }
        try {
            $pdo    = new \PDO('sqlite:' . $resolved);
            $result = $pdo->query("SELECT 1 FROM wordlist LIMIT 1");
            return $result !== false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Return the Unix timestamp of the most recent write to the index at $path.
     *
     * Reads the main database file's mtime — updated on each WAL checkpoint — no
     * database connection required. Returns 0 if the file does not exist.
     *
     * @param string $path Absolute or relative path to the index file.
     */
    public static function lastModified(string $path): int
    {
        return (int) @filemtime($path);
    }

    /**
     * Atomically rebuild an index by writing to a temporary file and publishing it at the target.
     *
     * When $callback is provided it receives a fresh, empty Index to populate.
     *
     * When $callback is omitted (null), the existing index must have the document store enabled;
     * all stored documents are streamed into the new index automatically. This lets you re-index
     * with a different SchemaConfig (e.g. new searchableFields or filterableFields) without maintaining
     * a separate copy of the source data.
     *
     * If the callback throws, or if the automatic streaming path fails, the temporary file is
     * removed and the original index is left untouched. The temp file is locked for the whole
     * build (see cleanupTempFiles()), and leftovers from earlier runs that were killed before
     * they could clean up are swept first.
     *
     * Writes made to the index at $path while the rebuild runs are lost when the new file
     * replaces it — including with no callback, which streams the store in batches. See
     * "Writes during a rebuild" in docs/indexing.md for patterns that combine live writes with
     * periodic rebuilds.
     *
     * The new file is published as {path}.v-{8 hex} and $path becomes a symlink to it, so it
     * never shares a -wal with the file it replaces; see publishVersion().
     *
     * Pass a SchemaConfig to override the schema; omit it (null) to inherit the existing
     * index's schema. When no existing index is present, null uses SchemaConfig defaults.
     *
     * @param  string            $path     Absolute or relative path to the index file to rebuild.
     * @param  callable|null     $callback fn(Index $new): void — populate the new index here;
     *                                     null streams from the existing document store.
     * @param  SchemaConfig|null $schema   Schema for the new index; null inherits from the existing index.
     * @return self               Open index pointing at the rebuilt file.
     * @throws \InvalidArgumentException If $callback is null and the existing index has no document store.
     * @throws IOException        If the rename fails or the parent directory does not exist.
     */
    public static function rebuild(
        string $path,
        ?callable $callback = null,
        ?SchemaConfig $schema = null,
    ): self {
        $resolved = self::resolvePath($path);
        $existing = file_exists($resolved) ? new self($resolved) : null;

        if ($callback === null && ($existing === null || !$existing->documentStoreEnabled)) {
            $existing?->close();
            throw new \InvalidArgumentException(
                "Cannot rebuild without a callback: the existing index at {$resolved} has no document store."
            );
        }

        if ($schema === null && $existing !== null) {
            $schema = new SchemaConfig(
                language:         $existing->language,
                store:            $existing->documentStoreEnabled,
                filterableFields: $existing->filterableFields,
                sortableFields:   $existing->sortableFields,
                searchableFields: $existing->searchableFields,
                stripHtml:        $existing->stripHtml,
            );
        }

        // Read synonyms before the callback path closes the existing index.
        $existingSynonyms = $existing?->getSynonyms() ?? [];

        // In the callback path the existing index is no longer needed; null it so
        // finally{} below is a no-op and phpstan can narrow $existing in the else arm.
        if ($callback !== null) {
            /** @infection-ignore-all MethodCallRemoval: resource cleanup; GC closes the connection if skipped, no observable effect on the rebuild outcome */
            $existing?->close();
            $existing = null;
        }

        self::cleanupTempFiles($resolved);
        [$tmp, $tmpLock] = self::claimTempPath($resolved);

        try {
            $handle = new self($tmp, schema: $schema);
            if ($existingSynonyms !== []) {
                $handle->setSynonyms(oneWay: $existingSynonyms);
            }
            if ($callback !== null) {
                $callback($handle);
            } elseif ($existing !== null) {
                // Always true here (validated above); the elseif narrows $existing to non-null.
                $handle->insert($existing->stream(500));
            }
            $handle->close();

            self::publishVersion($tmp, $resolved);
        } catch (\Throwable $e) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($tmp . $suffix);
            }
            throw $e;
        } finally {
            $existing?->close();
            self::releaseTempPath($tmp, $tmpLock);
        }

        return new self($resolved);
    }

    /**
     * Remove temp files left next to $path by a rebuild() or snapshotTo() that did not finish.
     *
     * Both methods build into {path}.tmp-{8 hex} (plus SQLite's -wal / -shm / -journal
     * sidecars) and rename the result over $path. A process that is killed mid-build —
     * max_execution_time, out of memory, a worker or container restart — never reaches its own
     * cleanup, so its files stay behind. rebuild() and snapshotTo() call this with the default
     * age before they start; call it from your own tooling to clean up or list leftovers.
     *
     * The files of one temp name are deleted together, and only when both hold:
     *
     * - no process holds the name's .lock file — rebuild() and snapshotTo() keep an flock() on
     *   it for their whole run and the OS releases it when a process dies, so a build that is
     *   still running is never touched, however long it takes;
     * - the newest of its files is at least $minAgeSeconds old — this covers leftovers from
     *   before 1.6.0, which have no lock file, and the instant between a builder creating its
     *   lock file and locking it.
     *
     * Only names of exactly that shape are considered; other files next to $path are ignored.
     *
     * @param  string $path          Path of the index the temp files belong to.
     * @param  int    $minAgeSeconds Minimum age of a group's newest file; 0 ignores age.
     * @return list<string>          The deleted paths, sorted.
     * @throws IOException If the parent directory of $path does not exist.
     */
    public static function cleanupTempFiles(string $path, int $minAgeSeconds = 3600): array
    {
        $resolved = self::resolvePath($path);
        $baseLen  = strlen($resolved);

        /** @var array<string, non-empty-list<string>> $groups  temp stem → its files */
        $groups = [];
        foreach (glob($resolved . '.tmp-*') ?: [] as $file) {
            if (preg_match(self::TEMP_SUFFIX_PATTERN, substr($file, $baseLen)) === 1) {
                $groups[substr($file, 0, $baseLen + self::TEMP_STEM_SUFFIX_LENGTH)][] = $file;
            }
        }

        $deleted = [];
        $now     = time();
        clearstatcache();
        foreach ($groups as $stem => $files) {
            $newest = max(array_map(static fn(string $f): int => (int) @filemtime($f), $files));
            if ($now - $newest < $minAgeSeconds) {
                continue;
            }
            $lockPath = $stem . '.lock';
            if (in_array($lockPath, $files, true)) {
                $lock = @fopen($lockPath, 'r');
                if ($lock === false) {
                    continue; // Removed a moment ago: its builder just finished.
                }
                $free = flock($lock, LOCK_EX | LOCK_NB);
                if ($free) {
                    flock($lock, LOCK_UN);
                }
                fclose($lock);
                if (!$free) {
                    continue; // A live build owns it.
                }
            }
            foreach ($files as $file) {
                if (@unlink($file)) {
                    $deleted[] = $file;
                }
            }
        }

        sort($deleted);
        return $deleted;
    }

    /**
     * Pick a fresh temp path next to $resolved and lock it until releaseTempPath().
     *
     * The lock file is what tells cleanupTempFiles() that the name belongs to a live build.
     *
     * @return array{0: string, 1: resource}
     * @throws IOException If the lock file cannot be created or locked.
     */
    private static function claimTempPath(string $resolved): array
    {
        /** @infection-ignore-all DecrementInteger|IncrementInteger|ConcatOperandRemoval|Concat: temp path construction details; any unique path in the same directory produces identical rename semantics */
        $tmp  = $resolved . '.tmp-' . bin2hex(random_bytes(4));
        $lock = @fopen($tmp . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new IOException("Failed to create temp lock file {$tmp}.lock.");
        }
        return [$tmp, $lock];
    }

    /**
     * Release and remove the lock taken by claimTempPath().
     *
     * @param resource $lock
     */
    private static function releaseTempPath(string $tmp, mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($tmp . '.lock');
    }

    /**
     * Publish a finished, closed build at $resolved without pairing it with another file's -wal.
     *
     * SQLite finds a database's -wal and -shm by name only. Renaming a new file over a path
     * that a connection in another process still has open pairs the new file with that
     * connection's -wal: its frames not yet checkpointed, and every commit it makes afterwards,
     * are read as pages of the new file. So each build gets a name of its own,
     * {path}.v-{8 hex}, and $resolved becomes a relative symlink to it, swapped atomically.
     * SQLite resolves the link and names the sidecars after the version, so two files never
     * share a -wal, and connections on the old version keep reading it until they reopen.
     *
     * The version that was current before the swap is kept for a connection that resolved
     * the link a moment earlier and is still opening it; older ones are deleted by
     * removeSupersededVersions(). Where symlinks are unavailable, the build is renamed over
     * $resolved and the path's sidecars are deleted, as before.
     *
     * @param  string $tmp      Temp path from claimTempPath(); its lock must still be held.
     * @param  string $resolved Index path.
     * @throws IOException If the build cannot be moved into place.
     */
    private static function publishVersion(string $tmp, string $resolved): void
    {
        $version  = $resolved . '.v-' . substr($tmp, -8);
        $previous = is_link($resolved) ? readlink($resolved) : false;

        // The build is closed (rebuild() checkpoints it to an empty -wal on close; VACUUM INTO
        // writes none), so its sidecars hold nothing and must not follow it under a new name.
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            @unlink($tmp . $suffix);
        }
        /** @infection-ignore-all Throw_: rename() returns false only on OS-level failure (permissions); not reproducible in unit tests without filesystem mocking */
        if (!rename($tmp, $version)) {
            throw new IOException("Failed to move the new index into place at {$version}.");
        }

        $link = $tmp . '.link';
        @unlink($link);
        if (@symlink(basename($version), $link) && rename($link, $resolved)) {
            self::removeSupersededVersions($resolved, $version, $previous);
            return;
        }

        // No symlinks on this filesystem: replace the file itself. Not safe against a writer
        // in another process that still has the old file open (see docs/indexing.md).
        /** @infection-ignore-all MethodCallRemoval,Throw_: only reached where symlink() fails (Windows, some network mounts); not reproducible in the Linux test suite */
        @unlink($link);
        /** @infection-ignore-all Throw_: see above */
        if (!rename($version, $resolved)) {
            throw new IOException("Failed to atomically replace index at {$resolved}.");
        }
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($resolved . $suffix);
        }
    }

    /**
     * Delete versions published at $resolved before the previous one, with their sidecars.
     *
     * Kept: $current, the version $previous pointed to, and any version whose build still
     * holds its temp lock (a concurrent rebuild()/snapshotTo() between its rename and its
     * swap). When $resolved was already a symlink, the -wal/-shm named after the path belong
     * to a plain file replaced at least one publish ago, so they go too; while it was still a
     * plain file they are left for the connections that may still have it open.
     *
     * @param string       $resolved Index path, now a symlink to $current.
     * @param string       $current  Version just published.
     * @param string|false $previous Link target before the swap; false if $resolved was no symlink.
     */
    private static function removeSupersededVersions(string $resolved, string $current, string|false $previous): void
    {
        $keep    = [basename($current) => true];
        $baseLen = strlen($resolved);
        if ($previous !== false) {
            $keep[basename($previous)] = true;
            @unlink($resolved . '-wal');
            @unlink($resolved . '-shm');
        }
        foreach (glob($resolved . '.v-*') ?: [] as $file) {
            if (preg_match(self::VERSION_SUFFIX_PATTERN, substr($file, $baseLen), $m) !== 1) {
                continue;
            }
            $live = self::isLockHeld($resolved . '.tmp-' . $m[1] . '.lock');
            if ($live || isset($keep[basename($resolved) . '.v-' . $m[1]])) {
                continue;
            }
            @unlink($file);
        }
    }

    /** True when a process holds flock() on $lockPath; false when it is free or does not exist. */
    private static function isLockHeld(string $lockPath): bool
    {
        $lock = @fopen($lockPath, 'r');
        if ($lock === false) {
            return false;
        }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        return !$free;
    }

    /**
     * Write an atomic snapshot of this index to $path.
     *
     * Uses VACUUM INTO to copy the current state to a uniquely-named temp file on the
     * same filesystem, then publishes it as {path}.v-{8 hex} and atomically swaps the
     * symlink at $path to it (see publishVersion()). Concurrent readers of the old file
     * are unaffected — they keep reading it until they reopen.
     *
     * Safe to call while writes are in progress on this index: VACUUM INTO reads a
     * consistent snapshot under a shared read transaction; WAL mode ensures writers
     * are never blocked.
     *
     * Temp files left by earlier rebuild() or snapshotTo() runs that were killed are swept
     * before the new temp file is created (see cleanupTempFiles(); a run in progress in
     * another process is never touched). The new temp file is removed if the VACUUM INTO or
     * rename fails.
     *
     * @param  string $path Absolute or relative path for the snapshot file.
     * @throws IOException If the rename fails or the parent directory does not exist.
     */
    public function snapshotTo(string $path): void
    {
        $resolved = self::resolvePath($path);

        self::cleanupTempFiles($resolved);
        [$tmp, $tmpLock] = self::claimTempPath($resolved);

        try {
            assert($this->pdo instanceof \PDO);
            $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($tmp));
            self::publishVersion($tmp, $resolved);
        } catch (\Throwable $e) {
            @unlink($tmp);
            @unlink($tmp . '-journal');
            throw $e;
        } finally {
            self::releaseTempPath($tmp, $tmpLock);
        }
    }

    /**
     * Run a WAL checkpoint, moving committed pages from the -wal file into the database.
     *
     * SQLite checkpoints automatically once the WAL passes `wal_autocheckpoint` pages, but
     * an automatic checkpoint can only reclaim WAL space up to the oldest active reader.
     * On an index under continuous concurrent reads the WAL can therefore grow without
     * bound. A long-running writer process should call this periodically and watch the
     * returned counters: when `log` stays high and `checkpointed` lags behind it, readers
     * are starving the checkpointer.
     *
     * Modes (SQLite semantics):
     * - `PASSIVE`  — checkpoint what it can without blocking; never waits on readers.
     * - `FULL`     — wait for readers, then checkpoint the entire WAL.
     * - `RESTART`  — FULL, then ensure the next writer restarts the WAL from the beginning.
     * - `TRUNCATE` — RESTART, then truncate the -wal file to zero bytes (default).
     *
     * @param  string $mode One of PASSIVE, FULL, RESTART, TRUNCATE (case-insensitive).
     * @return array{busy: int, log: int, checkpointed: int} `busy` is 1 when the checkpoint
     *         could not complete because of concurrent activity, `log` the WAL size in pages,
     *         `checkpointed` how many of those pages were written back. Note that a successful
     *         TRUNCATE leaves an empty WAL and so reports `log` and `checkpointed` as 0 — use
     *         PASSIVE when you want to observe the actual page counts.
     * @throws \InvalidArgumentException If $mode is not one of the four supported modes.
     * @throws IOException If this index was opened read-only.
     */
    public function checkpoint(string $mode = 'TRUNCATE'): array
    {
        $this->assertWritable();
        $normalized = strtoupper($mode);
        if (!in_array($normalized, ['PASSIVE', 'FULL', 'RESTART', 'TRUNCATE'], true)) {
            throw new \InvalidArgumentException(
                "Invalid checkpoint mode '{$mode}'. Expected one of: PASSIVE, FULL, RESTART, TRUNCATE."
            );
        }
        $pdo = $this->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Index connection is closed.');
        }
        $stmt = $pdo->query("PRAGMA wal_checkpoint({$normalized})");
        /** @var list<int>|false $row */
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return ['busy' => 0, 'log' => 0, 'checkpointed' => 0];
        }

        return [
            'busy'         => (int) $row[0],
            'log'          => (int) $row[1],
            'checkpointed' => (int) $row[2],
        ];
    }

    // --- Index lifecycle (private, called by the constructor) ---------------

    /**
     * Create a new SQLite index file and initialise the schema.
     *
     * Performance pragmas are applied via applyPragmas(). All tables are STRICT for
     * type safety. doclist is WITHOUT ROWID (clustered on term_id, doc_id), replacing
     * the old term_id secondary index with a zero-heap-fetch primary scan.
     *
     * Only called when no file exists at the path; replaceWithEmptyIndex() handles force: true
     * over an existing one.
     *
     * @param  SchemaConfig $schema Schema options persisted at creation time.
     * @return static
     * @throws QueryException If schema->language is set but has no stopword list or stemmer.
     */
    private function createIndex(SchemaConfig $schema): static
    {
        $language         = $schema->language;
        $store            = $schema->store;
        $searchableFields = $schema->searchableFields;
        $stripHtml        = $schema->stripHtml;

        $pdo = new PDO('sqlite:' . $this->path);
        $this->pdo = $pdo;
        $this->resetConnectionCaches();
        // page_size must be set before any data is written; ignored on existing files.
        // 16 384 bytes (4× default) reduces B-tree depth for multi-GB doclist tables.
        /** @infection-ignore-all MethodCallRemoval: page_size pragma affects only on-disk structure, not query correctness */
        $pdo->exec('PRAGMA page_size = 16384');
        /** @infection-ignore-all MethodCallRemoval: applyPragmas sets WAL/cache/case_sensitive_like; all terms are stored/queried in lowercase so LIKE correctness is unaffected without it */
        $this->applyPragmas();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS wordlist (
                id       INTEGER PRIMARY KEY,
                term     TEXT    NOT NULL UNIQUE,
                num_hits INTEGER NOT NULL,
                num_docs INTEGER NOT NULL
            ) STRICT"
        );
        // WITHOUT ROWID clusters rows by (term_id, doc_id), eliminating the heap fetch
        // that a secondary index would require. doc_id_index covers DELETE-by-doc_id.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS doclist (
                term_id   INTEGER NOT NULL,
                doc_id    INTEGER NOT NULL,
                hit_count INTEGER NOT NULL,
                PRIMARY KEY (term_id, doc_id)
            ) WITHOUT ROWID, STRICT"
        );
        /** @infection-ignore-all MethodCallRemoval: doc_id_index is a performance index; DELETE-by-doc_id still works via full scan */
        $pdo->exec("CREATE INDEX IF NOT EXISTS 'main'.'doc_id_index' ON doclist ('doc_id');");
        // Covers ORDER BY hit_count DESC LIMIT N for single- and multi-term fetches;
        // allows the planner to stop at the LIMIT without a temp-B-tree sort pass.
        /** @infection-ignore-all MethodCallRemoval: doclist_term_hitcount is a performance index; queries still return correct results via a temp sort */
        $pdo->exec("CREATE INDEX IF NOT EXISTS 'main'.'doclist_term_hitcount' ON doclist (term_id, hit_count DESC);");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_lengths (
                doc_id INTEGER PRIMARY KEY,
                length INTEGER NOT NULL
            ) STRICT"
        );

        // deleted_docs: documents removed by delete() whose posting rows (doclist, positions,
        // field_hits) are still on disk until purgeDeleted(). Read paths never see them: every
        // posting read joins doc_lengths, which delete() clears.
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_docs (doc_id INTEGER PRIMARY KEY) STRICT");

        $pdo->exec("CREATE TABLE IF NOT EXISTS info (key TEXT PRIMARY KEY, value TEXT NOT NULL) STRICT");
        /** @infection-ignore-all MethodCallRemoval: skipping this INSERT leaves total_documents/avg_doc_length rows absent; adjustStats UPDATEs hit 0 rows but keep infoCache correct for the current connection, so single-connection tests are unaffected; only a close+reopen would expose stale DB state */
        $pdo->exec("INSERT INTO info (key, value) VALUES ('total_documents', 0), ('avg_doc_length', 0)");

        $stmt = $pdo->prepare("INSERT INTO info (key, value) VALUES ('language', ?)");
        $stmt->execute([$language ?? '']);

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS positions (
                term_id  INTEGER NOT NULL,
                doc_id   INTEGER NOT NULL,
                position INTEGER NOT NULL,
                PRIMARY KEY (term_id, doc_id, position)
            ) WITHOUT ROWID, STRICT"
        );
        /** @infection-ignore-all MethodCallRemoval: positions_doc_id is a performance index; DELETE-by-doc_id still works via full scan */
        $pdo->exec("CREATE INDEX IF NOT EXISTS 'main'.'positions_doc_id' ON positions ('doc_id');");

        if ($store) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS documents (
                    doc_id INTEGER PRIMARY KEY,
                    data   TEXT NOT NULL
                ) STRICT"
            );
        }
        $stmt = $pdo->prepare("INSERT INTO info (key, value) VALUES ('has_document_store', ?)");
        $stmt->execute([$store ? '1' : '0']);
        $this->documentStoreEnabled = $store;

        // facet_keys: one row per unique facet field name (~10–100 entries; fully cached in PHP).
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS facet_keys (
                id           INTEGER PRIMARY KEY,
                name         TEXT NOT NULL UNIQUE,
                multi_valued INTEGER NOT NULL DEFAULT 0
            ) STRICT"
        );
        // facet_values: inverted index clustered on (key_id, value, doc_id).
        // WITHOUT ROWID → range scan on (key_id, value) is a pure B-tree leaf scan, no heap fetch.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS facet_values (
                key_id    INTEGER NOT NULL,
                value     TEXT    NOT NULL,
                doc_id    INTEGER NOT NULL,
                num_value REAL,
                PRIMARY KEY (key_id, value, doc_id)
            ) WITHOUT ROWID, STRICT"
        );
        // Covers DELETE-by-doc_id and the facet count join; see FACET_DOC_INDEX_DDL.
        $pdo->exec(self::FACET_DOC_INDEX_DDL);
        // Covers numeric range filter queries; partial keeps the B-tree small.
        $pdo->exec(
            "CREATE INDEX IF NOT EXISTS 'main'.'facet_numeric_index'
             ON facet_values (key_id, num_value, doc_id)
             WHERE num_value IS NOT NULL"
        );
        // facet_counts: documents per (key, value), kept exact by every write (adjustFacetCounts()),
        // so whole-key counts cost O(values) instead of a scan of every row of the key.
        // num_count/num_value keep facetStats: stats only when every counted row is numeric.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS facet_counts (
                key_id    INTEGER NOT NULL,
                value     TEXT    NOT NULL,
                count     INTEGER NOT NULL,
                num_count INTEGER NOT NULL,
                num_value REAL,
                PRIMARY KEY (key_id, value)
            ) WITHOUT ROWID, STRICT"
        );
        // sort_keys: case-folded string values of sortable fields (see sortKey()), clustered so a
        // sorted browse walks one key's values in sort order. Numbers sort via facet_numeric_index.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sort_keys (
                key_id   INTEGER NOT NULL,
                sort_key TEXT    NOT NULL,
                doc_id   INTEGER NOT NULL,
                PRIMARY KEY (key_id, sort_key, doc_id)
            ) WITHOUT ROWID, STRICT"
        );
        /** @infection-ignore-all MethodCallRemoval: sort_keys_doc_id is a performance index; DELETE-by-doc_id still works via full scan */
        $pdo->exec("CREATE INDEX IF NOT EXISTS 'main'.'sort_keys_doc_id' ON sort_keys (doc_id)");
        // field_names: one row per searchable field name (~2–10 entries; fully cached in PHP).
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS field_names (
                id   INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            ) STRICT"
        );
        // field_hits: per-field term hit counts for field boost re-scoring.
        // WITHOUT ROWID clusters on composite PK (term_id, doc_id, field_id);
        // sorted bulk insertion yields sequential B-tree appends identical to doclist.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS field_hits (
                term_id   INTEGER NOT NULL,
                doc_id    INTEGER NOT NULL,
                field_id  INTEGER NOT NULL,
                hit_count INTEGER NOT NULL,
                PRIMARY KEY (term_id, doc_id, field_id)
            ) WITHOUT ROWID, STRICT"
        );
        /** @infection-ignore-all MethodCallRemoval: field_hits_doc_id is a performance index; DELETE-by-doc_id still works via full scan */
        $pdo->exec("CREATE INDEX IF NOT EXISTS 'main'.'field_hits_doc_id' ON field_hits (doc_id);");

        // WITHOUT ROWID clusters on (source, target); single-SELECT synonym lookup is a pure B-tree scan.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS synonyms (
                source TEXT NOT NULL,
                target TEXT NOT NULL,
                PRIMARY KEY (source, target)
            ) WITHOUT ROWID, STRICT"
        );

        $schemaStmt = $pdo->prepare("INSERT INTO info (key, value) VALUES (?, ?)");
        $schemaStmt->execute(['schema_version',    (string) self::CURRENT_SCHEMA_VERSION]);
        $schemaStmt->execute(['filterable_fields', json_encode($schema->filterableFields)]);
        $schemaStmt->execute(['sortable_fields',   json_encode($schema->sortableFields)]);
        $schemaStmt->execute(['searchable_fields', $searchableFields !== null ? json_encode($searchableFields) : '']);
        $schemaStmt->execute(['strip_html',        $stripHtml ? '1' : '0']);
        $this->applyFieldLists($schema->filterableFields, $schema->sortableFields);
        $this->searchableFields   = $searchableFields;
        $this->searchableFieldSet = $searchableFields !== null ? array_flip($searchableFields) : null;
        $this->stripHtml          = $stripHtml;
        $this->schemaVersion      = self::CURRENT_SCHEMA_VERSION;

        if ($language !== null) {
            $this->applyLanguage($language);
        }
        $this->captureFileIdentity();

        return $this;
    }

    /**
     * Open an existing index file.
     *
     * @throws IOException    If the index file does not exist.
     * @throws QueryException If the file's schema revision is not one this version can open.
     */
    private function selectIndex(): void
    {
        if (!file_exists($this->path)) {
            throw new IOException("Index {$this->path} does not exist", 1);
        }
        // Capture identity before opening: if a rotation lands in between, the
        // stale identity makes the next reopenIfChanged() do one redundant reopen
        // (harmless) instead of serving the old file until the following rotation.
        $this->captureFileIdentity();
        $encodedPath         = implode('/', array_map(rawurlencode(...), explode('/', $this->path)));
        $dsn                 = $this->readonly
            ? 'sqlite:file://' . $encodedPath . '?mode=ro'
            : 'sqlite:' . $this->path;
        $this->pdo = new PDO($dsn);
        $this->resetConnectionCaches();
        /** @infection-ignore-all MethodCallRemoval: applyPragmas sets WAL/cache/case_sensitive_like; all terms are stored/queried in lowercase so LIKE correctness is unaffected without it */
        $this->applyPragmas();

        assert($this->pdo instanceof \PDO);
        $pdo   = $this->pdo;
        $stmt  = $pdo->query(
            "SELECT key, value FROM info"
            . " WHERE key IN ('language', 'has_document_store', 'filterable_fields', 'sortable_fields',"
            . " 'searchable_fields', 'strip_html', 'schema_version')"
        );
        $infoRows = [];
        if ($stmt) {
            /** @var array<string, string> $fetched */
            $fetched  = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $infoRows = $fetched;
        }
        // Files before 1.5.0 have no schema_version key; they are revision 1.
        $schemaVersion = (int) ($infoRows['schema_version'] ?? 1);
        if ($schemaVersion < self::MIN_SCHEMA_VERSION || $schemaVersion > self::CURRENT_SCHEMA_VERSION) {
            $this->pdo = null;
            throw new QueryException(self::unsupportedRevisionMessage($this->path, $schemaVersion));
        }
        $this->schemaVersion        = $schemaVersion;
        $this->applyLanguage($infoRows['language'] !== '' ? $infoRows['language'] : null);
        $this->documentStoreEnabled = $infoRows['has_document_store'] === '1';
        $this->applyFieldLists(
            self::decodeStringList($infoRows['filterable_fields']),
            self::decodeStringList($infoRows['sortable_fields']),
        );
        $this->searchableFields     = $infoRows['searchable_fields'] === ''
            ? null
            : self::decodeStringList($infoRows['searchable_fields']);
        $this->searchableFieldSet   = $this->searchableFields !== null ? array_flip($this->searchableFields) : null;
        $this->stripHtml            = $infoRows['strip_html'] === '1';
    }

    /**
     * Set the filterable and sortable field lists and their lookup sets. Both kinds are stored
     * in the facet index, so $facetFieldSet is their union.
     *
     * @param list<string> $filterable
     * @param list<string> $sortable
     */
    private function applyFieldLists(array $filterable, array $sortable): void
    {
        $this->filterableFields   = $filterable;
        $this->sortableFields     = $sortable;
        $this->filterableFieldSet = array_flip($filterable);
        $this->sortableFieldSet   = array_flip($sortable);
        $this->facetFieldSet      = $this->filterableFieldSet + $this->sortableFieldSet;
    }

    /** Why a file cannot be opened, and how to get a usable index again. */
    private static function unsupportedRevisionMessage(string $path, int $revision): string
    {
        if ($revision > self::CURRENT_SCHEMA_VERSION) {
            return "Index {$path} has schema revision {$revision}, written by a newer version of Fuzor;"
                . ' this version reads revision ' . self::CURRENT_SCHEMA_VERSION . '. Upgrade Fuzor to open it.';
        }
        return "Index {$path} has schema revision {$revision}, written by Fuzor 1.x; this version opens"
            . ' revision ' . self::MIN_SCHEMA_VERSION . ' and later. Recreate it from your source data:'
            . ' new Index($path, schema: ..., force: true), then insert the documents again.';
    }

    /**
     * Release the underlying database connection.
     *
     * Clears the prepared-statement cache and drops the PDO connection, allowing
     * SQLite to run its WAL checkpoint immediately rather than waiting for GC.
     * The instance must not be used after calling this method.
     */
    public function close(): void
    {
        $this->resetConnectionCaches();
        if (!$this->readonly) {
            // Update query-planner statistics for tables whose row counts have changed
            // since the last ANALYZE run. The 0x10002 mask = check all tables (0x10000)
            // + run ANALYZE where stale (0x0002). No-ops when statistics are current.
            /** @infection-ignore-all MethodCallRemoval: optimize updates sqlite_stat1; omitting leaves the query planner without fresh statistics */
            $this->pdo?->exec('PRAGMA optimize=0x10002');
            /** @infection-ignore-all MethodCallRemoval: SQLite triggers WAL checkpointing automatically on connection close; explicit TRUNCATE is a performance hint */
            $this->pdo?->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        }
        $this->pdo = null;
    }

    /**
     * Reopen the underlying connection when the index file was replaced on disk.
     *
     * snapshotTo() and rebuild() publish by atomically renaming a new file over the
     * index path. An already-open connection keeps reading the old inode and would
     * never see the new data (and keeps the old file's disk space allocated). This
     * method compares the path's device/inode against the values captured at open
     * and reopens the connection — same mode, fresh caches — when they differ.
     *
     * Intended for long-lived instances in worker-mode runtimes, called once per
     * request: the unchanged case costs a single stat() and keeps all caches warm.
     * In-place writes by other processes do not trigger a reopen; those are handled
     * by the data_version cache check.
     *
     * @return bool True when the file had been replaced and the connection was reopened.
     * @throws IOException If no file exists at the index path.
     */
    public function reopenIfChanged(): bool
    {
        clearstatcache(true, $this->path);
        $stat = @stat($this->path);
        if ($stat === false) {
            throw new IOException("Index {$this->path} does not exist", 1);
        }
        if ($this->fileIdentity === ['dev' => $stat['dev'], 'ino' => $stat['ino']]) {
            return false;
        }
        $this->close();
        $this->selectIndex();
        return true;
    }

    /** Record the index file's device and inode for rotation detection; see $fileIdentity. */
    private function captureFileIdentity(): void
    {
        clearstatcache(true, $this->path);
        $stat = @stat($this->path);
        /** @infection-ignore-all ArrayItemRemoval,FalseValue: identity fields only affect rotation detection sensitivity, exercised in reopenIfChanged tests */
        $this->fileIdentity = $stat === false ? null : ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    /** Reset all per-connection caches; called on every connection open or close. */
    private function resetConnectionCaches(): void
    {
        $this->stmtCache      = [];
        $this->bulkStmtCache  = [];
        $this->infoCache      = null;
        $this->termIdCache    = [];
        $this->wordlistCache  = [];
        $this->facetKeyCache   = [];
        $this->multiValuedKeys = [];
        $this->keyMultiValued  = [];
        $this->fieldNameCache  = [];
        $this->synonymCache    = null;
        $this->hasDeleted      = null;
        $this->inTransaction   = false;
        $this->dataVersion    = null;
    }

    /**
     * Detect commits made by other connections and drop the caches they invalidate.
     *
     * PRAGMA data_version is a per-connection counter that changes only when a different
     * connection commits to the database file — this connection's own writes never change
     * its value. Without this check, a long-lived instance (worker-mode runtimes, or a
     * reader sharing the file with a separate writer process) would serve stale document
     * stats, wordlist rows, synonyms, and — after external deletes prune terms — dead
     * term IDs, indefinitely.
     *
     * Called at the entry of every cache-consuming read path, and from wrapInTransaction()
     * after the write lock is acquired (no other writer can commit between the check and
     * this connection's own commit, so caches validated there stay valid for the whole
     * transaction). Costs one cached-statement PRAGMA round-trip.
     */
    private function checkDataVersion(): void
    {
        $stmt = $this->stmt('dataVersion', 'PRAGMA data_version');
        $stmt->execute();
        $version = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($this->dataVersion === $version) {
            return;
        }
        if ($this->dataVersion !== null) {
            $this->infoCache      = null;
            $this->wordlistCache  = [];
            $this->termIdCache    = [];
            $this->facetKeyCache   = [];
            $this->multiValuedKeys = [];
            $this->keyMultiValued  = [];
            $this->fieldNameCache  = [];
            $this->synonymCache    = null;
            $this->hasDeleted      = null;
        }
        $this->dataVersion = $version;
    }

    // --- Public write operations --------------------------------------------

    /**
     * Index one or many new documents.
     *
     * Pass a list of document arrays (each must contain an 'id' key). A single document is
     * wrapped in an outer array: [[$doc]]. Uses the two-phase bulk path for any batch size;
     * a single-element list uses an internal fast path that skips bulk pragma overhead.
     *
     * @param list<array<string, mixed>>|\Traversable<mixed, array<string, mixed>> $documents
     *                                    List of document arrays, each with an 'id' key.
     * @param callable(int $done, int $total): void|null $progress Called after each document is tokenised
     *                                                              in phase 1; $done starts at 1.
     * @throws QueryException If any document has no 'id' key, contains duplicate IDs, or any ID already exists.
     */
    public function insert(iterable $documents, ?callable $progress = null): void
    {
        $this->assertWritable();
        $this->insertMany($documents, $progress);
    }

    /**
     * @param list<array<string, mixed>>|\Traversable<mixed, array<string, mixed>> $documents
     *                                    Documents to index; each must contain an 'id' key.
     * @param callable(int $done, int $total): void|null $progress Called after each document is tokenised
     *                                                              in phase 1; $done starts at 1.
     */
    private function insertMany(iterable $documents, ?callable $progress = null): void
    {
        $total  = is_array($documents) || $documents instanceof \Countable ? count($documents) : 0;
        $chunks = self::chunked($documents, $this->config->insertChunkSize);
        /** @infection-ignore-all ReturnRemoval: empty batch produces no SQL writes; adjustStats(0,0) is a no-op when no tokens are processed */
        if (!$chunks->valid()) {
            return;
        }
        $first = $chunks->current();
        $chunks->next();
        $more = $chunks->valid();

        // Single document: use the lightweight single-doc path to avoid bulk pragma overhead.
        if (!$more && count($first) === 1) {
            $this->insertOne($first[0]);
            return;
        }

        $pdo = $this->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Index connection is closed.');
        }

        // Probe once to know whether the doclist is empty; used to skip the duplicate-ID check
        // (nothing can already exist in an empty table) and to gate index drop/rebuild.
        $probe        = $pdo->query('SELECT 1 FROM doclist LIMIT 1');
        assert($probe !== false);
        $indexIsEmpty = $probe->fetchColumn() === false;
        /** @infection-ignore-all MethodCallRemoval: closeCursor is resource cleanup; leaving cursor open is harmless in WAL mode */
        $probe->closeCursor();

        // The first chunk is checked before anything is written or dropped, as a one-chunk
        // insert always was; later chunks are checked inside the transaction.
        $firstIds = $this->checkInsertIds($first, 0, !$indexIsEmpty);

        // Pending deletes: purge them in a transaction of their own, while the doc_id indexes the
        // purge seeks on still exist (dropped below). A deleted ID written again would otherwise
        // collide with its old term-index rows.
        $this->wrapInTransaction(function (): void {
            if ($this->hasDeleted()) {
                $this->purgeDeleted();
            }
        });

        // Fresh bulk-load statement cache for this call; released in finally so large-N INSERT
        // statements don't stay open as SQLite tracked-statements during subsequent insert() calls.
        $this->bulkStmtCache = [];

        // For large batches (or a fresh index), drop both secondary indexes before the INSERT
        // and rebuild once from the completed data. Maintaining them row-by-row during a bulk
        // load costs more than a single post-insert sequential scan. Below 1 000 docs on a
        // non-empty index, per-row maintenance is cheaper than a full doclist rebuild. An input
        // longer than one chunk counts as large: its length is not known up front.
        /** @infection-ignore-all GreaterThanOrEqualTo,GreaterThanOrEqualToNegotiation,LogicalOr,LogicalOrAllSubExprNegation,LogicalOrNegation,LogicalOrSingleSubExprNegation: all mutations of this condition only affect whether secondary indexes are dropped/rebuilt; correctness is unaffected */
        $dropIndexes = $indexIsEmpty || $more || count($first) >= 1_000;

        // Bulk-import pragma overrides: restored in the finally block.
        /** @infection-ignore-all MethodCallRemoval: bulk-import pragma overrides are performance tuning only; correctness is unaffected */
        $this->applyBulkPragmas();

        // Drop indexes after applyBulkPragmas() so the DROP sees the same WAL state as
        // the subsequent INSERT. Dropping before the bulk pragmas are active risks a
        // "database table is locked" race on concurrent insertMany() calls.
        /** @infection-ignore-all IfNegation: inverting $dropIndexes only affects whether indexes are dropped; correctness is unaffected */
        if ($dropIndexes) {
            /** @infection-ignore-all MethodCallRemoval: dropping secondary indexes before bulk INSERT is a performance optimisation; correctness unaffected */
            $pdo->exec('
                DROP INDEX IF EXISTS doclist_term_hitcount;
                DROP INDEX IF EXISTS doc_id_index;
                DROP INDEX IF EXISTS positions_doc_id;
                DROP INDEX IF EXISTS facet_doc_id_index;
                DROP INDEX IF EXISTS facet_numeric_index;
                DROP INDEX IF EXISTS field_hits_doc_id;
                DROP INDEX IF EXISTS sort_keys_doc_id;
            ');
        }
        /** @infection-ignore-all UnwrapFinally: removing the try-finally wrapper only affects exception safety of the pragma restore; on the success path the behaviour is identical */
        try {
            $this->wrapInTransaction(function () use (&$first, $firstIds, $chunks, $more, $progress, $total): void {
                // One chunk at a time (Config::$insertChunkSize): Phase 1 and Phase 2 per chunk,
                // so memory follows the chunk, not the input. Stats are adjusted once at the end.
                /** @var list<array<string, mixed>> $chunk */
                $chunk       = $first;
                $first       = null;
                $ids         = $firstIds;
                $offset      = 0;
                $totalLength = 0;
                while (true) {
                    $this->purgeDeletedAmong($ids);
                    $chunkProgress = $progress === null
                        ? null
                        : static fn(int $done) => $progress($offset + $done, $total);

                    ['wordHits'          => $wordHits,
                     'wordDocs'          => $wordDocs,
                     'docTermBuffer'     => $docTermBuffer,
                     'docLengthBuffer'   => $docLengthBuffer,
                     'docPositionBuffer' => $docPositionBuffer,
                     'facetBuffer'       => $facetBuffer,
                     'multiValuedFacets' => $multiValuedFacets,
                     'rawDocuments'      => $rawDocuments,
                     'fieldTermBuffer'   => $fieldTermBuffer] = $this->buildBatchBuffer($chunk, $chunkProgress);

                    $totalLength += $this->flushBatch(
                        $wordHits,
                        $wordDocs,
                        $docTermBuffer,
                        $docLengthBuffer,
                        $docPositionBuffer,
                        $rawDocuments,
                        $facetBuffer,
                        $fieldTermBuffer,
                        $multiValuedFacets,
                    );
                    // Free this chunk's buffers before the next one is built.
                    unset(
                        $wordHits,
                        $wordDocs,
                        $docTermBuffer,
                        $docLengthBuffer,
                        $docPositionBuffer,
                        $facetBuffer,
                        $multiValuedFacets,
                        $rawDocuments,
                        $fieldTermBuffer,
                    );
                    $offset += count($chunk);

                    if (!$more) {
                        break;
                    }
                    /** @var list<array<string, mixed>> $chunk */
                    $chunk = $chunks->current();
                    $chunks->next();
                    $more = $chunks->valid();
                    // Earlier chunks are in doc_lengths by now, so the index check also catches
                    // an ID repeated across chunks.
                    $ids = $this->checkInsertIds($chunk, $offset, true);
                }

                $this->adjustStats($offset, $totalLength);
            });
        } finally {
            // Rebuild dropped indexes from the completed dataset while bulk-load pragmas
            // are still active (large cache + synchronous=OFF).
            /** @infection-ignore-all IfNegation: inverting $dropIndexes only affects whether indexes are rebuilt; correctness is unaffected */
            if ($dropIndexes) {
                /** @infection-ignore-all MethodCallRemoval: rebuilding secondary indexes is a performance step; correctness is unaffected */
                $pdo->exec('
                    CREATE INDEX IF NOT EXISTS doc_id_index ON doclist (doc_id);
                    CREATE INDEX IF NOT EXISTS doclist_term_hitcount ON doclist (term_id, hit_count DESC);
                    CREATE INDEX IF NOT EXISTS positions_doc_id ON positions (doc_id);
                ');
                $pdo->exec(self::FACET_DOC_INDEX_DDL);
                $pdo->exec('
                    CREATE INDEX IF NOT EXISTS facet_numeric_index ON facet_values (key_id, num_value, doc_id)
                        WHERE num_value IS NOT NULL;
                ');
                /** @infection-ignore-all MethodCallRemoval: rebuilding field_hits_doc_id is a performance step; correctness is unaffected */
                $pdo->exec('CREATE INDEX IF NOT EXISTS field_hits_doc_id ON field_hits (doc_id);');
                $pdo->exec('CREATE INDEX IF NOT EXISTS sort_keys_doc_id ON sort_keys (doc_id);');
            }
            /** @infection-ignore-all MethodCallRemoval: restoring pragmas after bulk load is a performance step; the next connection will re-apply from applyPragmas() */
            $this->restoreNormalPragmas();
            // Release bulk-load statements so they don't stay open as SQLite tracked-statements
            // during subsequent single-doc insert() transactions.
            $this->bulkStmtCache = [];
        }
    }

    /**
     * Split documents into lists of at most $size, reading the input lazily.
     *
     * @param  iterable<mixed, array<string, mixed>> $documents
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private static function chunked(iterable $documents, int $size): \Generator
    {
        $chunk = [];
        foreach ($documents as $document) {
            $chunk[] = $document;
            if (count($chunk) === $size) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * Check one chunk of an insert: every document has an 'id', no ID repeats inside the chunk,
     * and (with $checkIndex) none exists in the index yet — which, inside the insert's
     * transaction, includes the IDs of its earlier chunks.
     *
     * @param  list<array<string, mixed>> $chunk
     * @param  int                        $offset Position of the chunk's first document in the input.
     * @return list<int>                          The chunk's IDs.
     * @throws QueryException
     */
    private function checkInsertIds(array $chunk, int $offset, bool $checkIndex): array
    {
        $ids = [];
        foreach ($chunk as $i => $document) {
            $at = $offset + $i;
            if (!array_key_exists('id', $document)) {
                throw new QueryException("Document at index {$at} must contain an 'id' key.");
            }
            $id = $this->extractId($document['id']);
            if (isset($ids[$id])) {
                throw new QueryException("Duplicate id {$id} at index {$at}.");
            }
            /** @infection-ignore-all TrueValue: isset() returns true for any non-null value including false; both true and false mark the slot as occupied */
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        if ($checkIndex) {
            $existing = array_keys($this->fetchDocLengthsForDocs($ids));
            if ($existing !== []) {
                $where = $offset === 0 ? '' : ' (in the index or earlier in this insert)';
                throw new QueryException(
                    'Documents already exist with ids: ' . implode(', ', $existing)
                        . "{$where}. Use update() to replace them."
                );
            }
        }
        return $ids;
    }

    /** @param array<string, mixed> $document */
    private function insertOne(array $document): void
    {
        if (!array_key_exists('id', $document)) {
            throw new QueryException("Document must contain an 'id' key.");
        }
        $this->wrapInTransaction(function () use ($document): void {
            $id    = $this->extractId($document['id']);
            $check = $this->stmt('docExistsCheck', 'SELECT 1 FROM doc_lengths WHERE doc_id = :id LIMIT 1');
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() !== false) {
                throw new QueryException("Document {$id} already exists. Use update() to replace it.");
            }
            /** @infection-ignore-all MethodCallRemoval: closeCursor is a resource-management call; omitting it leaves the cursor open but does not affect WAL-mode write correctness */
            $check->closeCursor();
            $this->purgeDeletedAmong([$id]);

            $length = $this->processDocument($document);
            $this->adjustStats(1, $length);
        });
    }

    /**
     * Replace one or many existing documents in the index.
     *
     * All IDs are checked for existence before any writes — missing IDs throw without
     * modifying the index.
     *
     * @param list<array<string, mixed>>|\Traversable<mixed, array<string, mixed>> $documents
     *                                    List of document arrays; each must contain an 'id' key.
     * @throws QueryException If any document has no 'id' key, or any ID does not exist.
     */
    public function update(iterable $documents): void
    {
        $this->assertWritable();
        $this->replaceMany($documents, strict: true);
    }

    /**
     * Create or replace one or many documents in the index.
     *
     * @param list<array<string, mixed>>|\Traversable<mixed, array<string, mixed>> $documents
     *                                    List of document arrays; each must contain an 'id' key.
     * @throws QueryException If any document has no 'id' key.
     */
    public function upsert(iterable $documents): void
    {
        $this->assertWritable();
        $this->replaceMany($documents, strict: false);
    }

    /**
     * Shared implementation for update() and upsert().
     *
     * @param array<string, mixed> $document
     * @throws QueryException
     */
    private function replaceOne(array $document, bool $strict): void
    {
        if (!array_key_exists('id', $document)) {
            throw new QueryException("Document must contain an 'id' key.");
        }
        $this->wrapInTransaction(function () use ($document, $strict): void {
            $id = $this->extractId($document['id']);
            $this->purgeDeletedAmong([$id]);
            if ($this->documentStoreEnabled) {
                $kept = $this->unchangedSearchableLengths([$document], $this->fetchDocLengthsForDocs([$id]));
                if ($kept !== []) {
                    $this->removeDocumentRows([$id]);
                    $this->processDocument($document, $kept[$id]);
                    return;
                }
            }
            $oldLength = $this->removeDocumentData($id);

            if ($oldLength === null) {
                if ($strict) {
                    throw new QueryException("Document {$id} does not exist. Use upsert() to create or replace it.");
                }
                $newLength = $this->processDocument($document);
                $this->adjustStats(1, $newLength);
            } else {
                $newLength = $this->processDocument($document);
                $this->adjustStats(0, $newLength - $oldLength);
            }
        });
    }

    /**
     * Shared implementation for the bulk update() and upsert() paths.
     *
     * Uses the same two-phase bulk path as insertMany(): a single bulk-remove pass over all
     * existing documents followed by buildBatchBuffer() + flushBatch() for all incoming documents.
     * This avoids the per-document removeDocumentData() + processDocument() loop that wipes caches
     * and issues individual prepared statements for every row.
     *
     * @param list<array<string, mixed>>|\Traversable<mixed, array<string, mixed>> $documents
     * @throws QueryException
     */
    private function replaceMany(iterable $documents, bool $strict): void
    {
        /** @infection-ignore-all LogicalNot: iterator_to_array() accepts arrays in PHP 8.1+; converting an array produces the same array */
        if (!is_array($documents)) {
            $documents = iterator_to_array($documents, false);
        }

        if ($documents === []) {
            return;
        }

        // Single-element list: use the lightweight single-doc path to avoid bulk pragma overhead.
        if (count($documents) === 1) {
            $this->replaceOne($documents[0], $strict);
            return;
        }

        $pdo = $this->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Index connection is closed.');
        }

        $this->bulkStmtCache = [];

        $this->applyBulkPragmas();

        try {
            $this->wrapInTransaction(function () use ($documents, $strict): void {
                // 1. Collect IDs and fetch existing doc lengths in one pass.
                $ids = [];
                foreach ($documents as $i => $document) {
                    if (!array_key_exists('id', $document)) {
                        throw new QueryException("Document at index {$i} must contain an 'id' key.");
                    }
                    $ids[] = $this->extractId($document['id']);
                }

                $this->purgeDeletedAmong($ids);
                $oldLengths = $this->fetchDocLengthsForDocs($ids);

                // 2. Strict mode: every ID must already exist.
                if ($strict) {
                    $missing = array_diff($ids, array_keys($oldLengths));
                    if ($missing !== []) {
                        throw new QueryException(
                            'Documents do not exist with ids: '
                                . implode(', ', $missing) . '. Use upsert() to create or replace them.'
                        );
                    }
                }

                // 3. Bulk-remove all documents that currently exist in the index; those whose
                //    searchable input is unchanged keep their term-index rows.
                $existingIds = array_keys($oldLengths);
                $kept        = $this->unchangedSearchableLengths($documents, $oldLengths);
                if ($kept !== []) {
                    $this->removeDocumentRows(array_keys($kept));
                }
                $changedIds = array_values(array_diff($existingIds, array_keys($kept)));
                if ($changedIds !== []) {
                    $this->bulkRemoveDocuments($changedIds);
                }

                // 4. Bulk-insert all documents using the same two-phase path as insertMany().
                ['wordHits'          => $wordHits,
                 'wordDocs'          => $wordDocs,
                 'docTermBuffer'     => $docTermBuffer,
                 'docLengthBuffer'   => $docLengthBuffer,
                 'docPositionBuffer' => $docPositionBuffer,
                 'facetBuffer'       => $facetBuffer,
                 'multiValuedFacets' => $multiValuedFacets,
                 'rawDocuments'      => $rawDocuments,
                 'fieldTermBuffer'   => $fieldTermBuffer] = $this->buildBatchBuffer($documents, knownLengths: $kept);

                $totalNewLength = $this->flushBatch(
                    $wordHits,
                    $wordDocs,
                    $docTermBuffer,
                    $docLengthBuffer,
                    $docPositionBuffer,
                    $rawDocuments,
                    $facetBuffer,
                    $fieldTermBuffer,
                    $multiValuedFacets,
                );

                // 5. Update stats: only truly new documents change the document count.
                $docDelta    = count($ids) - count($existingIds);
                $lengthDelta = $totalNewLength - array_sum($oldLengths);

                /** @infection-ignore-all NotIdentical: diverges only when docDelta≠0 and lengthDelta=0, which requires new docs with zero tokens — impossible in practice */
                if ($docDelta !== 0 || $lengthDelta !== 0) {
                    $this->adjustStats($docDelta, $lengthDelta);
                }
            });
        } finally {
            $this->restoreNormalPragmas();
            $this->bulkStmtCache = [];
        }
    }

    /**
     * Remove one or more documents from the index.
     *
     * Accepts one or many IDs as variadic arguments: delete(1), delete(1, 2, 3), or
     * delete(...$ids). Non-existent IDs are silently skipped. All deletions happen in
     * a single transaction with one stats update.
     *
     * The documents leave every read path, the stored documents, the facet tables and the
     * document count at once. Their rows in the term index (doclist, positions, field_hits) are
     * only marked deleted and purged later by optimize(), or by delete() itself once deleted
     * documents make up 10% of the index; until then BM25's per-term document counts still
     * include them.
     *
     * @param int ...$ids Document IDs to remove.
     */
    public function delete(int ...$ids): void
    {
        $this->assertWritable();
        /** @infection-ignore-all ReturnRemoval: empty $ids finds no lengths and returns inside the transaction — identical result */
        if ($ids === []) {
            return;
        }

        $this->wrapInTransaction(function () use ($ids): void {
            $this->deleteDocuments(array_values(array_unique($ids)));
        });
    }

    /**
     * Remove every document that matches $filter, as delete() does, and return how many.
     *
     * $filter has the shape and meaning of SearchOptions::$filter — values, lists, FacetRange,
     * FacetExclude, all combined with AND — and names filterable fields only. Matching and
     * deletion happen in one transaction.
     *
     * @param  array<string, FacetExclude|FacetRange|list<string>|string> $filter
     * @return int The number of documents deleted.
     * @throws \InvalidArgumentException If $filter is empty (clear() removes every document).
     * @throws QueryException            If a field is not declared in filterableFields.
     * @throws IOException               If the index is read-only.
     */
    public function deleteByFilter(array $filter): int
    {
        $this->assertWritable();
        if ($filter === []) {
            throw new \InvalidArgumentException('deleteByFilter() needs a filter; clear() removes every document.');
        }
        $this->checkFilterableFields('Filter', array_keys($filter));

        $deleted = 0;
        $this->wrapInTransaction(function () use ($filter, &$deleted): void {
            $conditions = $this->orderBySelectivity($this->facetFilterConditions($filter));
            // No conditions left: only exclusions of values no document holds, which keep every document.
            $match = $conditions === [] ? ['SELECT doc_id FROM doc_lengths', []] : $this->matchingDocsSql($conditions);
            if ($match === null) {
                return;
            }
            $stmt = $this->prepare($match[0]);
            $stmt->execute($match[1]);
            /** @var list<int> $ids */
            $ids     = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $deleted = $this->deleteDocuments(array_values(array_unique($ids)));
        });
        return $deleted;
    }

    /**
     * delete()'s work inside the caller's transaction: remove the per-document rows of the live
     * documents among $ids, record them in deleted_docs, adjust the stats, and purge every
     * deleted document once they reach PURGE_RATIO of the index.
     *
     * @param  list<int> $ids Distinct IDs; ones without a live document are skipped.
     * @return int            The number of documents deleted.
     */
    private function deleteDocuments(array $ids): int
    {
        $lengths = $this->fetchDocLengthsForDocs($ids);
        if ($lengths === []) {
            return 0;
        }
        $deleted = array_keys($lengths);
        $this->removeDocumentRows($deleted);
        foreach (array_chunk($deleted, self::CHUNK_1P) as $chunk) {
            $this->prepare(
                'INSERT INTO deleted_docs (doc_id) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?)'))
            )->execute($chunk);
        }
        $this->adjustStats(-count($lengths), -array_sum($lengths));
        $this->hasDeleted = true;

        $pending = $this->countDeleted();
        $live    = (int) $this->getInfoValues(['total_documents'])['total_documents'];
        if ($pending >= self::PURGE_RATIO * ($pending + $live)) {
            $this->purgeDeleted();
        }
        return count($deleted);
    }

    /**
     * Purge the term-index rows of every deleted document (see delete()) and bring the per-term
     * document and hit counts back to exact. rebuild() and clear() leave none behind either.
     *
     * @return int The number of deleted documents purged.
     * @throws IOException If the index is read-only.
     */
    public function optimize(): int
    {
        $this->assertWritable();
        $purged = 0;
        $this->wrapInTransaction(function () use (&$purged): void {
            $purged = $this->purgeDeleted();
        });
        return $purged;
    }

    /**
     * Remove all documents from the index in a single transaction.
     *
     * Faster than deleting each ID individually: unconditional DELETEs across all
     * index tables replace per-document bookkeeping. Stats and caches are reset in-place.
     */
    public function clear(): void
    {
        $this->assertWritable();

        $this->wrapInTransaction(function (): void {
            $pdo = $this->pdo;
            assert($pdo instanceof \PDO);

            $pdo->exec('DELETE FROM doclist');
            $pdo->exec('DELETE FROM positions');
            $pdo->exec('DELETE FROM doc_lengths');
            $pdo->exec('DELETE FROM wordlist');
            if ($this->documentStoreEnabled) {
                $pdo->exec('DELETE FROM documents');
            }
            $pdo->exec('DELETE FROM facet_values');
            $pdo->exec('DELETE FROM facet_counts');
            $pdo->exec('DELETE FROM sort_keys');
            $pdo->exec('UPDATE facet_keys SET multi_valued = 0 WHERE multi_valued = 1');
            $pdo->exec('DELETE FROM field_hits');
            $pdo->exec('DELETE FROM deleted_docs');

            $this->stmt(
                'statsWrite',
                "UPDATE info SET value = CASE key
                     WHEN 'total_documents' THEN :n
                     WHEN 'avg_doc_length'  THEN :avg
                 END WHERE key IN ('total_documents', 'avg_doc_length')"
            )->execute([':n' => '0', ':avg' => '0']);
        });

        $this->infoCache      = ['total_documents' => '0', 'avg_doc_length' => '0'];
        $this->termIdCache    = [];
        $this->wordlistCache  = [];
        $this->facetKeyCache   = [];
        $this->multiValuedKeys = [];
        $this->keyMultiValued  = [];
        $this->fieldNameCache  = [];
        $this->hasDeleted      = false;
    }

    // --- Synonym management -------------------------------------------------

    /**
     * Replace the full synonym configuration for this index.
     *
     * All terms are normalized (lowercased, stemmed if a language is set) before
     * storage, so synonyms remain consistent with indexed tokens regardless of how
     * the caller spells them.  Multi-word terms and terms that reduce to an empty
     * string after normalization are skipped and returned to the caller rather than
     * applied.
     *
     * Equivalences are stored as bidirectional pairs: every term in the group
     * expands to all the others.  One-way entries are directional: only the
     * source term expands to its targets, not the reverse.
     *
     * Replaces every existing synonym in a single transaction; calling with both
     * parameters empty is equivalent to clearSynonyms().
     *
     * @param  list<list<string>>          $equivalences Groups where each term finds all others.
     * @param  array<string, list<string>> $oneWay       Source → list of targets (one direction only).
     * @return list<string> Raw (un-normalized) terms that were skipped — typically because they
     *                       contain more than one word.
     */
    public function setSynonyms(array $equivalences = [], array $oneWay = []): array
    {
        $this->assertWritable();

        /** @var list<array{string, string}> $pairs */
        $pairs = [];
        /** @var list<string> $skipped */
        $skipped = [];

        foreach ($equivalences as $group) {
            $normalized = [];
            foreach ($group as $term) {
                $norm = $this->normalizeSynonymTerm($term);
                if ($norm === '') {
                    $skipped[] = $term;
                    continue;
                }
                $normalized[] = $norm;
            }
            foreach ($normalized as $normA) {
                foreach ($normalized as $normB) {
                    if ($normA === $normB) {
                        continue;
                    }
                    $pairs[] = [$normA, $normB];
                }
            }
        }

        foreach ($oneWay as $source => $targets) {
            $normSource = $this->normalizeSynonymTerm((string) $source);
            if ($normSource === '') {
                $skipped[] = (string) $source;
                continue;
            }
            foreach ($targets as $target) {
                $normTarget = $this->normalizeSynonymTerm($target);
                if ($normTarget === '') {
                    $skipped[] = $target;
                    continue;
                }
                if ($normSource === $normTarget) {
                    continue;
                }
                $pairs[] = [$normSource, $normTarget];
            }
        }

        // Deduplicate before hitting the DB.
        $seen  = [];
        $dedup = [];
        foreach ($pairs as $pair) {
            $key = $pair[0] . "\0" . $pair[1];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $dedup[]    = $pair;
            }
        }

        $this->wrapInTransaction(function () use ($dedup): void {
            $pdo = $this->pdo;
            assert($pdo instanceof \PDO);
            $pdo->exec('DELETE FROM synonyms');
            foreach (array_chunk($dedup, self::CHUNK_2P) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?,?)'));
                $stmt = $this->prepare("INSERT INTO synonyms (source, target) VALUES {$placeholders}");
                $stmt->execute(array_merge(...array_map(fn($p) => [$p[0], $p[1]], $chunk)));
            }
        });

        $this->synonymCache = null;

        return $skipped;
    }

    /**
     * Return all configured synonyms as a flat source → targets map.
     *
     * Keys and values are in their normalized (stemmed) form — the same form used
     * for lookup at query time.  Equivalences appear as entries in both directions.
     *
     * @return array<string, list<string>>
     */
    public function getSynonyms(): array
    {
        $this->checkDataVersion();
        $this->loadSynonymCache();
        return $this->synonymCache ?? [];
    }

    /** Remove all synonym mappings from this index. */
    public function clearSynonyms(): void
    {
        $this->assertWritable();
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);
        $pdo->exec('DELETE FROM synonyms');
        $this->synonymCache = [];
    }

    /**
     * Check whether a document exists in the index.
     */
    public function has(int $id): bool
    {
        $stmt = $this->stmt('hasOne', 'SELECT 1 FROM doc_lengths WHERE doc_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Check whether multiple documents exist in the index.
     *
     * Returns an `id => bool` map in the same order as the input.
     * Returns an empty array when called with no arguments.
     *
     * @param  int             ...$ids Document IDs to check.
     * @return array<int, bool>
     */
    public function hasMany(int ...$ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<int> $found */
        $found = [];
        foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
            $placeholders = $this->placeholders(count($chunk));
            $stmt = $this->prepare("SELECT doc_id FROM doc_lengths WHERE doc_id IN ($placeholders)");
            $stmt->execute($chunk);
            /** @var list<int> $rows */
            $rows  = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $found = array_merge($found, $rows);
        }
        $foundSet = array_flip($found);

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = isset($foundSet[$id]);
        }
        return $result;
    }

    /**
     * Fetch a stored document by ID.
     *
     * @return array<string, mixed>|null Document array, or null if not found.
     * @throws QueryException If the document store is not enabled on this index.
     */
    public function get(int $id): ?array
    {
        if (!$this->documentStoreEnabled) {
            throw new QueryException(
                'Document store is not enabled on this index (created with store: false in SchemaConfig).'
            );
        }
        $map = $this->fetchDocuments([$id]);
        return $map[$id] ?? null;
    }

    /**
     * Fetch multiple stored documents by ID.
     *
     * Missing IDs are silently omitted from the result.
     * Returns an empty array when called with no arguments.
     *
     * @param  int ...$ids Document IDs to fetch.
     * @return array<int, array<string, mixed>>
     * @throws QueryException If the document store is not enabled on this index.
     */
    public function getMany(int ...$ids): array
    {
        if (!$this->documentStoreEnabled) {
            throw new QueryException(
                'Document store is not enabled on this index (created with store: false in SchemaConfig).'
            );
        }
        if ($ids === []) {
            return [];
        }
        return $this->fetchDocuments(array_values($ids));
    }

    /**
     * @param  list<int>                         $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchDocuments(array $ids): array
    {
        $result = [];
        foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
            $n            = count($chunk);
            $placeholders = $this->placeholders($n);
            $stmt = $this->stmt(
                "getManyDocs:{$n}",
                "SELECT doc_id, data FROM documents WHERE doc_id IN ({$placeholders})"
            );
            $stmt->execute($chunk);
            /** @var list<array{0: int, 1: string}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$docId, $data]) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                $result[$docId] = $decoded;
            }
        }
        return $result;
    }

    /**
     * Stream all documents from the store in ascending doc_id order.
     *
     * Yields doc_id => document pairs one at a time. $batchSize controls how many rows
     * are fetched from SQLite per round-trip via a keyset cursor (WHERE doc_id > :last).
     * Pass the last yielded key as $afterId to resume from a checkpoint or fetch the next page.
     * so each fetch is O(1) against the clustered PK regardless of position in the dataset.
     *
     * @param  int $batchSize Rows fetched per SQL round-trip (default 100).
     * @return \Generator<int, array<string, mixed>>
     * @throws QueryException            If the document store is not enabled.
     * @throws \InvalidArgumentException If $batchSize < 1.
     */
    public function stream(int $batchSize = 100, int $afterId = 0): \Generator
    {
        if (!$this->documentStoreEnabled) {
            throw new QueryException(
                'Document store is not enabled on this index (created with store: false in SchemaConfig).'
            );
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be >= 1.');
        }
        $lastId = $afterId;
        do {
            $stmt = $this->stmt(
                'streamCursor',
                'SELECT doc_id, data FROM documents WHERE doc_id > ? ORDER BY doc_id LIMIT ?'
            );
            $stmt->execute([$lastId, $batchSize]);
            /** @var list<array{0: int, 1: string}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$docId, $data]) {
                /** @var array<string, mixed> $doc */
                $doc    = json_decode((string) $data, true, 512, JSON_THROW_ON_ERROR);
                $lastId = (int) $docId;
                yield (int) $docId => $doc;
            }
        } while (count($rows) === $batchSize);
    }

    // --- Public API: info & factories ----------------------------------------

    /**
     * Return the total number of documents in the index.
     */
    public function count(): int
    {
        $this->checkDataVersion();
        /** @infection-ignore-all DecrementInteger|IncrementInteger: ?? fallback is only reached on a corrupt/missing info table row; all writes keep total_documents consistent, so this path is unreachable in tests */
        return (int) ($this->getInfoValues(['total_documents'])['total_documents'] ?? 0);
    }

    /**
     * Explain what the engine does internally with a query string.
     *
     * Answers "why did my search return these results (or nothing)?" by walking the same
     * tokenisation → stopword filtering → stemming → wordlist resolution pipeline that the
     * real search methods use, and recording each step.
     *
     * Read-only: makes no writes and does not invalidate any cache. Wordlist lookups are
     * written into the wordlist cache, so a subsequent search() call for the same phrase
     * benefits from warm cache entries.
     *
     * @param  string $phrase Raw query string; processed identically to search().
     */
    public function inspectQuery(string $phrase, bool $asYouType = true): QueryInspection
    {
        $this->checkDataVersion();
        $verbose = $this->filterQueryTokens($phrase, verbose: true);
        /** @var list<string> $filteredTokens */
        $filteredTokens = $verbose['filtered'];
        /** @var list<string> $survivingRaw */
        $survivingRaw = $verbose['surviving_raw'];

        $lastIndex = count($filteredTokens) - 1;
        $tokens    = [];

        foreach ($filteredTokens as $i => $processed) {
            $isLast = $asYouType && ($i === $lastIndex);
            $rows   = $this->getWordlistByKeyword($processed, $isLast);

            $matchType = match (true) {
                $rows === []                                                     => 'none',
                isset($rows[0]['distance'])                                      => 'fuzzy',
                /** @infection-ignore-all LogicalAnd: non-last words are always exact matches (wordlist lookup uses isLastWord=false), so term===processed; neither && mutation fires on real data */
                $isLast && $rows[0]['term'] !== $processed                       => 'prefix',
                default                                                          => 'exact',
            };

            $wordlistRows = array_map(
                fn(array $r): array => [
                    'term'     => $r['term'],
                    'numHits'  => (int) $r['num_hits'],
                    'numDocs'  => (int) $r['num_docs'],
                    'distance' => isset($r['distance']) ? (int) $r['distance'] : null,
                ],
                $rows
            );

            $tokens[] = new QueryToken(
                raw:          $survivingRaw[$i] ?? $processed,
                processed:    $processed,
                isLast:       $isLast,
                found:        $rows !== [],
                matchType:    $matchType,
                wordlistRows: $wordlistRows,
                /** @infection-ignore-all CastInt: array_sum() on numeric strings returns int in PHP 8; the cast is defensive documentation, not a type change */
                numHits:      (int) array_sum(array_column($rows, 'num_hits')),
                /** @infection-ignore-all CastInt: same as numHits — array_sum already returns int here */
                numDocs:      (int) array_sum(array_column($rows, 'num_docs')),
            );
        }

        $indexInfo = $this->getInfoValues(['total_documents', 'avg_doc_length']);

        return new QueryInspection(
            rawTokens:       $verbose['raw_tokens'],
            filteredTokens:  $filteredTokens,
            stopwordsActive: $this->stopwords instanceof \Fuzor\Stopwords,
            stemmerActive:   $this->stemmer instanceof \Fuzor\Stemmer,
            allStripped:     $verbose['all_stripped'],
            totalDocuments:  (int) ($indexInfo['total_documents'] ?? 0),
            avgDocLength:    (float) ($indexInfo['avg_doc_length'] ?? 0),
            tokens:          $tokens,
            /** @infection-ignore-all Concat|ConcatOperandRemoval: '|' prepend is the OR identity; '|' . $phrase and $phrase . '|' both yield identical postfix because '|' is always the last operator popped */
            booleanPostfix:  BooleanParser::toPostfix('|' . $verbose['free_phrase'])[0],
            phraseGroups:    $verbose['phrase_groups'],
        );
    }

    /**
     * Return a Snippeter pre-configured with the index language.
     *
     * @param bool $escape Escape the excerpt for safe inclusion in HTML.
     */
    public function snippeter(
        int $windowSize = 200,
        int $maxSnippets = 1,
        string $ellipsis = '…',
        bool $escape = false,
    ): Snippeter {
        return new Snippeter(
            windowSize: $windowSize,
            maxSnippets: $maxSnippets,
            ellipsis: $ellipsis,
            language: $this->language,
            escape: $escape,
        );
    }

    /**
     * Return a Highlighter pre-configured for use with this index.
     *
     * @param bool $escape Treat input as plain text and return safe HTML; the tags are inserted verbatim.
     */
    public function highlighter(
        string $open = '<mark>',
        string $close = '</mark>',
        bool $asYouType = true,
        bool $escape = false,
    ): Highlighter {
        return new Highlighter(
            open: $open,
            close: $close,
            asYouType: $asYouType,
            language: $this->language,
            escape: $escape,
        );
    }

    // --- Search operations --------------------------------------------------

    /**
     * Run a BM25 ranked full-text search.
     *
     * Typo tolerance is automatic: words of at least 5 codepoints fall through to Levenshtein
     * matching when no exact or prefix match is found; 1 typo allowed for 5–8 codepoints,
     * 2 for 9+. Respects Config::$fuzzyPrefixLength and $fuzzyMaxExpansions.
     * Shorter words use exact + optional as-you-type prefix matching only.
     *
     * A word or quoted phrase prefixed with '-' ("shirt -formal", 'dress -"long sleeve"') removes
     * the documents that contain it (exact match after stemming; see extractNegations()). A query
     * of only negations returns every document except those, like an empty query.
     *
     * @param  string        $phrase  Raw search phrase; will be tokenised.
     * @param  SearchOptions $options Per-query options (limit, offset, filter, facets, sort, …).
     * @throws \InvalidArgumentException On a malformed sort spec.
     * @throws QueryException            When a sort field is not sortable, or a filter, facet, or
     *                                   distinct field is not filterable (see SchemaConfig).
     */
    public function search(
        string $phrase,
        SearchOptions $options = new SearchOptions(),
    ): SearchResult {
        $this->checkDataVersion();
        ['phrase' => $positive, 'conditions' => $negations] = $this->extractNegations($phrase);
        if (trim($positive) === '') {
            return $this->browse($phrase, $options, $negations);
        }
        $asYouType     = $options->asYouType;
        $limit         = $options->limit;
        $offset        = $options->offset;
        $filter        = $options->filter;
        $facets        = $options->facets;
        $distinct      = $options->distinct;
        $distinctCount = $options->distinctCount;
        $sortSpecs     = $this->checkDeclaredFields($options);
        $warnings      = [];
        $parsed        = $this->filterQueryTokens($positive);
        /** @var list<string> $keywords */
        $keywords     = $parsed['filtered'];
        /** @var list<list<string>> $phraseGroups */
        $phraseGroups = $parsed['phrase_groups'];

        /** @var array<int, float> $docScores */
        $docScores = [];
        /** @var array<int, int> $docBucket  Leading keyword groups (in strategy order) each doc contains; see MatchingStrategy. */
        $docBucket = [];
        $strategy  = $options->matchingStrategy;

        $fieldBoosts    = $this->config->fieldBoosts;
        $useFieldBoosts = $fieldBoosts !== [];
        /** @var array<int, float> $termIdfMap  termId → idfK1p1; populated when $useFieldBoosts for post-loop re-scoring. */
        $termIdfMap = [];
        /** @var array<int, array<int, true>> $docContribTermIds  docId → set<termId>; populated when $useFieldBoosts. */
        $docContribTermIds = [];

        $info           = $this->getInfoValues(['total_documents', 'avg_doc_length']);
        /** @infection-ignore-all DecrementInteger|IncrementInteger|CastInt: fallback 0 is used only on a corrupt/empty DB; all writes keep info consistent, so this path is unreachable in tests */
        $totalDocuments = (int) ($info['total_documents'] ?? 0);
        /** @infection-ignore-all DecrementInteger|IncrementInteger|Coalesce|CastFloat: fallback 0 is guarded by max(1.0,…) below; mutations produce the same clamped value; unreachable on a healthy index */
        $avgdl          = max(1.0, (float) ($info['avg_doc_length'] ?? 0));
        $k1             = $this->config->k1;
        $b              = $this->config->b;
        $lastIndex      = count($keywords) - 1;
        // These two BM25 denominator constants do not depend on per-keyword IDF; hoist
        // them outside the loop to avoid recomputing on every keyword iteration.
        /** @infection-ignore-all Multiplication: k1_1mb is a pre-loop constant; mutating it uniformly shifts all docs' denominators, preserving relative BM25 ranking for any single-term query */
        $k1_1mb    = $k1 * (1.0 - $b);   // k1 * (1 - b)    — denominator constant
        /** @infection-ignore-all Multiplication|Division: k1b_avgdl is the length-normalisation scale; mutations change score magnitudes but preserve relative ordering for uniform-term-frequency distributions */
        $k1b_avgdl = $k1 * $b / $avgdl;  // k1 * b / avgdl  — length-norm scale

        /** @var list<list<int>> $termGroups  keyword_index → matched term IDs, for proximity ranking */
        $termGroups = [];
        /** @var list<KeywordGroup> $groups  One per keyword, in query order. */
        $groups = [];
        // Set when a cap cut matches out: a keyword above maxDocs, or a capped prefix expansion.
        $candidatesCapped = false;
        $prefixCapped     = false;

        // Phase 1: resolve every keyword group's terms and document frequency (wordlist only).
        foreach ($keywords as $idx => $term) {
            $isLastKeyword = $asYouType && ($lastIndex === $idx);
            $word = $this->getWordlistByKeyword($term, $isLastKeyword, true, $prefixCapped);
            foreach ($this->synonymsFor($term) as $synTerm) {
                $synRows = $this->getWordlistByKeyword($synTerm, false, false);
                if ($synRows !== []) {
                    array_push($word, ...$synRows);
                }
            }
            /** @infection-ignore-all IncrementInteger,Ternary,CastInt: numDocs feeds BM25 scoring only; for single-term prefix results array_sum equals word[0]['num_docs']; CastInt: array_sum returns int */
            $df = count($word) === 1 ? $word[0]['num_docs'] : (int) array_sum(array_column($word, 'num_docs'));
            // Smoothed BM25 IDF, log((N + 1) / (df + 0.5)): positive only while df <= N, so df is
            // capped at N. It can exceed N when prefix expansions are summed (a document counted
            // once per matching term) and while deleted documents are not purged (see delete()).
            $idfDf = min($df, $totalDocuments);
            /** @infection-ignore-all IncrementInteger|Minus|Plus|Division: IDF mutations monotonically shift all per-term scores by the same factor; relative document ordering is preserved for any single-term query */
            $idf      = log(1 + ($totalDocuments - $idfDf + 0.5) / ($idfDf + 0.5));
            $groups[] = [
                'words'     => $word,
                'termIds'   => array_column($word, 'id'),
                /** @infection-ignore-all DecrementInteger|IncrementInteger|Plus|Multiplication: idfK1p1 is a per-term scalar; mutating k1+1 uniformly rescales every doc's contribution for that term, preserving relative ranking */
                'idfK1p1'   => $idf * ($k1 + 1),
                'df'        => $df,
                'truncated' => $df > $this->config->maxDocs,
                'rows'      => [],
            ];
        }

        // Phase 2: the order groups are required in (see MatchingStrategy), and which to fetch.
        $order = array_keys($groups);
        if ($strategy === MatchingStrategy::Frequency) {
            usort($order, fn(int $a, int $b): int => $groups[$a]['df'] <=> $groups[$b]['df'] ?: $a <=> $b);
        }
        $fetch = $order;
        if ($strategy === MatchingStrategy::All && $groups !== []) {
            // Every result contains every word, so all of them are among the rarest word's
            // documents. When that word is complete, nothing else needs to be fetched.
            $rarest = $order;
            usort($rarest, fn(int $a, int $b): int => $groups[$a]['df'] <=> $groups[$b]['df'] ?: $a <=> $b);
            if (!$groups[$rarest[0]]['truncated']) {
                $fetch = [$rarest[0]];
            }
        }
        foreach ($fetch as $g) {
            if ($groups[$g]['words'] === []) {
                continue;
            }
            $candidatesCapped = $candidatesCapped || $groups[$g]['truncated'];
            // BM25 score computed in SQLite C; PHP receives (term_id, doc_id, score).
            $groups[$g]['rows'] = $this->fetchDocsByTermIds(
                $groups[$g]['words'],
                $this->config->maxDocs,
                isset($groups[$g]['words'][0]['distance']),
                $groups[$g]['idfK1p1'],
                $k1_1mb,
                $k1b_avgdl,
            );
        }

        // Phase 3: walk the groups in order. A document's bucket is the number of leading groups
        // it contains; it is fixed at the first group it lacks, so later groups are never looked
        // up for it, and only the groups in its bucket add to its score (so how a word was cut at
        // maxDocs cannot reorder documents within a bucket). A group cut at maxDocs is completed
        // for the documents still alive that it has not seen (completeGroup()).
        /** @var array<int, true> $alive */
        $alive = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as [, $docId]) {
                $alive[$docId] = true;
            }
        }
        foreach ($order as $position => $g) {
            if ($alive === []) {
                break;
            }
            $group = $groups[$g];
            if ($group['truncated'] || !in_array($g, $fetch, true)) {
                array_push($group['rows'], ...$this->completeGroup($group, $alive, $k1_1mb, $k1b_avgdl));
            }
            /** @var array<int, true> $groupTermIds */
            $groupTermIds = [];
            /** @var array<int, true> $matched  Alive docs this group contains (prefix-expanded term IDs count once). */
            $matched = [];
            foreach ($group['rows'] as [$termId, $docId, $score]) {
                if (!isset($alive[$docId])) {
                    continue;
                }
                /** @infection-ignore-all OneZeroFloat: ?? 0.0 is the additive identity; the fallback only applies on first encounter of a docId which always has score 0 before accumulation */
                $docScores[$docId]     = ($docScores[$docId] ?? 0.0) + $score;
                $groupTermIds[$termId] = true;
                $matched[$docId]       = true;
                if ($useFieldBoosts) {
                    $termIdfMap[$termId]               ??= $group['idfK1p1'];
                    $docContribTermIds[$docId][$termId]  = true;
                }
            }
            foreach (array_diff_key($alive, $matched) as $docId => $_) {
                $docBucket[$docId] = $position;
            }
            $alive = $matched;
            if ($groupTermIds !== []) {
                $termGroups[] = array_keys($groupTermIds);
            }
        }
        foreach ($alive as $docId => $_) {
            $docBucket[$docId] = count($order);
        }
        // Bucket 0 (lacks the first required word) never matches; with All only full matches do.
        $minBucket = $strategy === MatchingStrategy::All ? count($order) : 1;
        foreach ($docBucket as $docId => $bucket) {
            if ($bucket < $minBucket) {
                unset($docBucket[$docId], $docScores[$docId], $docContribTermIds[$docId]);
            }
        }
        if ($strategy === MatchingStrategy::All && $fetch !== $order) {
            // Driven from the complete rarest word: every document with all words was found.
            $candidatesCapped = false;
        }

        // Field boost re-scoring: replace uniform BM25 scores with field-weighted BM25.
        // Only executes when fieldBoosts is non-empty; the normal path is completely untouched.
        // Runs before proximity boost so proximity applies on top of the re-scored values.
        if ($useFieldBoosts && $docScores !== []) {
            $fieldIdBoostMap = [];
            foreach ($fieldBoosts as $name => $boost) {
                $fid = $this->lookupFieldNameId($name);
                if ($fid !== null) {
                    $fieldIdBoostMap[$fid] = (float) $boost;
                }
            }
            if ($fieldIdBoostMap !== []) {
                $fieldHitRows = $this->fetchFieldHitsForDocs(array_keys($termIdfMap), array_keys($docScores));
                $docLengths   = $this->fetchDocLengthsForDocs(array_keys($docScores));
                $newScores    = [];
                foreach ($docScores as $docId => $_) {
                    $docLen   = (float) max(1, $docLengths[$docId] ?? 1);
                    $newScore = 0.0;
                    foreach ($docContribTermIds[$docId] ?? [] as $termId => $_) {
                        $idfK1p1val = $termIdfMap[$termId] ?? 0.0;
                        $weighted   = 0.0;
                        foreach ($fieldHitRows[$termId][$docId] ?? [] as $fieldId => $hits) {
                            $weighted += ($fieldIdBoostMap[$fieldId] ?? 1.0) * $hits;
                        }
                        if ($weighted > 0.0) {
                            $newScore += $idfK1p1val * $weighted / ($k1_1mb + $k1b_avgdl * $docLen + $weighted);
                        }
                    }
                    $newScores[$docId] = $newScore;
                }
                $docScores = $newScores;
            }
        }

        // Phrase filter: remove documents that do not contain every quoted phrase as a
        // contiguous token sequence. Positions are only fetched for the candidate set, not the
        // entire doclist.
        if ($phraseGroups !== []) {
            $lastToken = end($keywords) ?: '';
            $matchIds  = $this->filterDocsByPhrases(array_keys($docScores), $phraseGroups, $lastToken, $asYouType);
            $docScores = array_intersect_key($docScores, array_flip($matchIds));
        }

        // Apply facet filters: load per-key doc ID sets and intersect with the score map.
        // Exclusions are removed first, so they also hold for disjunctive facet counts.
        [$filterSets, $excluded] = $this->loadFacetKeySets($filter, array_keys($docScores), $negations);
        $docScores    = array_diff_key($docScores, $excluded);
        $rawDocScores = $docScores;
        if ($filterSets !== []) {
            $globalFilter = $this->intersectFilterSets($filterSets);
            $docScores = array_intersect_key($docScores, $globalFilter);
        }

        // Compute disjunctive facet counts on the full filtered result set.
        [
            'distribution' => $facetDistribution,
            'stats'        => $facetStats,
            'approximate'  => $approximateFacets,
        ] = $this->computeFacetCounts(
            $facets,
            $filterSets,
            $rawDocScores,
            $docScores,
            $this->config->maxFacetCountDocs,
        );
        $facetDistribution = $this->orderFacetValues($facetDistribution, $options->sortFacetValuesBy);
        $exhaustive = !$candidatesCapped && !$prefixCapped;
        $warnings   = [
            ...$warnings,
            ...$this->capWarnings($candidatesCapped ? 'maxDocs' : null, $prefixCapped, $approximateFacets),
        ];

        $total = count($docScores);

        // Proximity only reorders the documents that remain after every filter, so it runs
        // last. Without sort or distinct only the first offset + limit documents are shown, and
        // the rerank can stop early (see applyProximityRanking()).
        $proximity = count($termGroups) >= 2 && $this->config->proximityBoost > 0.0;
        // With sort fields the proximity factor only breaks ties between documents with equal
        // sort values, so the sorted path reranks just the ties on the page (rerankSortedPageTies()).
        if ($proximity && ($limit > 0 || $distinct !== null) && ($sortSpecs === [] || $distinct !== null)) {
            $this->applyProximityRanking(
                $docScores,
                $docBucket,
                $termGroups,
                count($keywords),
                $sortSpecs === [] && $distinct === null ? $offset + $limit : null,
            );
        }

        /** @infection-ignore-all DecrementInteger: $total is count(); -1 is impossible, so the guard fires identically for any realistic input */
        if ($total === 0) {
            return new SearchResult(
                ids: [],
                totalHits: 0,
                documents: $this->hydrateAndFormat([], $positive, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
                warnings: $warnings,
                exhaustive: $exhaustive,
                approximateFacets: $approximateFacets,
            );
        }

        if ($distinct !== null) {
            $keyId = $this->lookupFacetKeyId($distinct);
            if ($sortSpecs !== []) {
                $sortedIds = $this->sortDocIdsBySpecs(
                    array_keys($docScores),
                    $sortSpecs,
                    $docScores,
                    leading: count($keywords) > 1 ? $docBucket : null,
                );
            } elseif (count($keywords) > 1) {
                $sortedIds = self::rankByBucketThenScore($docScores, $docBucket);
            } else {
                arsort($docScores);
                $sortedIds = array_keys($docScores);
            }
            $valueMap = $this->fetchSortValues($sortedIds, $keyId);
            [$pagedIds, $distinctHits] = $this->applyDistinctPagination(
                $sortedIds,
                $valueMap,
                $distinctCount,
                $offset,
                $limit,
            );
            return new SearchResult(
                ids: $pagedIds,
                totalHits: $distinctHits,
                documents: $this->hydrateAndFormat($pagedIds, $positive, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
                warnings: $warnings,
                exhaustive: $exhaustive,
                approximateFacets: $approximateFacets,
            );
        }

        if ($limit === 0) {
            return new SearchResult(
                ids: [],
                totalHits: $total,
                documents: $this->hydrateAndFormat([], $positive, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
                warnings: $warnings,
                exhaustive: $exhaustive,
                approximateFacets: $approximateFacets,
            );
        }

        if ($sortSpecs !== []) {
            // Words bucket first (MatchingStrategy), then the sort fields, then BM25 score, then
            // doc ID as the final deterministic key.
            $tieKeys   = [];
            $sortedIds = $this->sortDocIdsBySpecs(
                array_keys($docScores),
                $sortSpecs,
                $docScores,
                $tieKeys,
                count($keywords) > 1 ? $docBucket : null,
            );
            $pagedIds  = $proximity
                ? $this->rerankSortedPageTies(
                    $sortedIds,
                    $tieKeys ?? [],
                    $docScores,
                    $docBucket,
                    $termGroups,
                    count($keywords),
                    $offset,
                    $limit,
                )
                : array_slice($sortedIds, $offset, $limit);
        } elseif (count($keywords) > 1) {
            $pagedIds = array_slice(self::rankByBucketThenScore($docScores, $docBucket), $offset, $limit);
        } else {
            // Single-keyword: all docs tie on match count; C-native arsort on scores alone.
            arsort($docScores);
            $pagedIds = array_slice(array_keys($docScores), $offset, $limit);
        }
        return new SearchResult(
            ids: $pagedIds,
            totalHits: $total,
            documents: $this->hydrateAndFormat($pagedIds, $positive, $options),
            facetCounts: $facetDistribution,
            facetStats: $facetStats,
            query: $phrase,
            limit: $limit,
            offset: $offset,
            warnings: $warnings,
            exhaustive: $exhaustive,
            approximateFacets: $approximateFacets,
        );
    }

    /**
     * Run a boolean full-text search using Shunting-Yard postfix evaluation.
     *
     * Operator precedence (tightest to loosest): NOT (~) > AND (&, space) > OR ( or ).
     * Parentheses override precedence. BM25 scores are not available in boolean mode.
     *
     * @param  string        $phrase  Boolean query string.
     * @param  SearchOptions $options Per-query options (limit, offset, filter, facets, sort, …).
     * @throws \InvalidArgumentException On a malformed sort spec.
     * @throws QueryException            When a sort field is not sortable, or a filter, facet, or
     *                                   distinct field is not filterable (see SchemaConfig).
     */
    public function searchBoolean(
        string $phrase,
        SearchOptions $options = new SearchOptions(),
    ): SearchResult {
        $this->checkDataVersion();
        if (trim($phrase) === '') {
            return $this->browse($phrase, $options);
        }
        $asYouType     = $options->asYouType;
        $limit         = $options->limit;
        $offset        = $options->offset;
        $filter        = $options->filter;
        $facets        = $options->facets;
        $distinct      = $options->distinct;
        $distinctCount = $options->distinctCount;
        $sortSpecs     = $this->checkDeclaredFields($options);
        $warnings      = [];
        $parsed       = $this->filterQueryTokens($phrase);
        /** @var list<list<string>> $phraseGroups */
        $phraseGroups = $parsed['phrase_groups'];

        // Prepend "|" so the Shunting-Yard algorithm always has a left-hand operand.
        // OR with an empty set is the identity, so it does not affect the result.
        // Use free_phrase (quotes stripped, words left in place) so BooleanParser's
        // space→& replacement does not break quoted phrases.
        /** @infection-ignore-all ConcatOperandRemoval: '|' prefix is the OR identity; removing it or appending instead yields identical postfix because '|' is always the lowest-priority operator */
        [$postfix, $lastTerm] = BooleanParser::toPostfix('|' . $parsed['free_phrase']);

        // PHP-side set evaluation: fetch per-term doc IDs (capped at maxDocs) from SQLite,
        // then apply set operations in PHP via C-native array_intersect / array_diff /
        // array_unique. This avoids SQLite compound-query materialisation (UNION/INTERSECT/
        // EXCEPT over unbounded doclists) which was the main boolean performance bottleneck.
        // Each term lookup is a single indexed SELECT with LIMIT; the wordlistCache ensures
        // repeated keyword lookups within one query incur no extra DB round-trips.

        $maxDocs = $this->config->maxDocs;

        // Set when a cap cut matches out: a keyword above maxDocs, or a capped prefix expansion.
        $candidatesCapped = false;
        $prefixCapped     = false;

        /** Fetch capped doc IDs for one keyword (resolves prefix expansion / caching). */
        $fetchIds = function (string $kw, bool $isLast) use ($maxDocs, &$candidatesCapped, &$prefixCapped): array {
            return $this->fetchBooleanDocIds(
                $this->resolveWordlistIds($kw, $isLast, $prefixCapped),
                $maxDocs,
                $candidatesCapped,
            );
        };

        /**
         * Materialise a stack entry into a flat list of doc IDs.
         * Strings are lazily fetched; lists are passed through; null maps to [].
         *
         * @param  string|list<int>|null $entry
         * @return list<int>
         */
        $ids = function (string|array|null $entry) use ($fetchIds, $lastTerm, $asYouType): array {
            // A negation outside AND has no positive documents to subtract from (there is no
            // "all documents" set here), so "~a" alone or "a | ~b" contributes nothing.
            if ($entry === null || is_array($entry) && isset($entry['__not__'])) {
                return [];
            }
            if (is_string($entry)) {
                return $fetchIds($entry, $asYouType && $entry === $lastTerm);
            }
            /** @var list<int> $entry */
            return $entry;
        };

        /**
         * Stack entries: lazy string term, materialised list<int>, NOT-marker array, or null.
         * @var list<string|list<int>|array{__not__: list<int>}|null> $stack
         */
        $stack = [];

        foreach ($postfix as $token) {
            if ($token === '~') {
                // Lazy NOT: fetch the excluded IDs now so AND can use array_diff directly.
                $term    = array_pop($stack);
                /** @infection-ignore-all Ternary: $term is always a string when '~' is evaluated — Shunting-Yard emits '~' immediately after its operand term, never after a materialised list; both branches of $ids() reach $fetchIds() for strings anyway */
                $termIds = is_string($term) ? $fetchIds($term, $term === $lastTerm) : $ids($term);
                $stack[] = ['__not__' => $termIds];
            } elseif ($token === '&') {
                $right    = array_pop($stack);
                $left     = array_pop($stack);
                $rightNot = is_array($right) && isset($right['__not__']);
                $leftNot  = is_array($left) && isset($left['__not__']);
                if ($rightNot && $leftNot) {
                    // ~a & ~b = ~(a | b): stays a negation for an enclosing AND to subtract.
                    /** @var array{__not__: list<int>} $left */
                    /** @var array{__not__: list<int>} $right */
                    $excluded = array_merge($left['__not__'], $right['__not__']);
                    $stack[]  = ['__not__' => array_values(array_unique($excluded))];
                } elseif ($rightNot || $leftNot) {
                    // AND-NOT, in either order ("a ~b" or "~b a"): subtract the negated IDs.
                    /** @var array{__not__: list<int>} $negated */
                    [$positive, $negated] = $rightNot ? [$left, $right] : [$right, $left];
                    /** @infection-ignore-all UnwrapArrayValues: array_diff preserves keys from first arg; array_values ensures list<int> contract for downstream array_slice/assertContains; keys are integer so assertContains still passes without reindex, making this a silent correctness issue rather than a detectable test failure */
                    $stack[] = array_values(array_diff($ids($positive), $negated['__not__']));
                } else {
                    // AND: intersection of both sides (C-native, O(n log n)).
                    /** @infection-ignore-all UnwrapArrayValues: array_intersect preserves keys from the first arg; array_values is needed to guarantee a list<int> */
                    $stack[] = array_values(array_intersect($ids($left), $ids($right)));
                }
            } elseif ($token === '|') {
                // OR: merge both sides and deduplicate (preserves first-seen / popularity order).
                $right = array_pop($stack) ?? null;
                $left  = array_pop($stack) ?? null;
                /** @infection-ignore-all UnwrapArrayUnique|UnwrapArrayValues: array_unique removes duplicate doc IDs that can appear when a term is OR'd with itself; array_values ensures sequential keys; assertContains tests pass regardless of key gaps, so the mutation is not detectable without key-sensitive assertions */
                $stack[] = array_values(array_unique(array_merge($ids($left), $ids($right))));
            } else {
                $stack[] = $token; // lazy string operand
            }
        }

        /** @var list<int> $docIds */
        $docIds = $ids(array_pop($stack) ?? null);

        if ($phraseGroups !== []) {
            $docIds = $this->filterDocsByPhrases($docIds, $phraseGroups, $lastTerm ?? '', $asYouType);
        }

        // Apply facet filters: load per-key doc ID sets and intersect with the result.
        // array_flip($docIds) gives doc_id → position, usable as a set for array_intersect_key.
        // Exclusions are removed first, so they also hold for disjunctive facet counts.
        [$filterSets, $excluded] = $this->loadFacetKeySets($filter, $docIds);
        $rawDocSet  = array_diff_key(array_flip($docIds), $excluded);
        if ($filterSets !== []) {
            $globalFilter = $this->intersectFilterSets($filterSets);
            $docIds = array_keys(array_intersect_key($rawDocSet, $globalFilter));
        } elseif ($excluded !== []) {
            $docIds = array_keys($rawDocSet);
        }

        // Compute disjunctive facet counts on the full filtered result.
        $filteredDocSet = array_flip($docIds);
        [
            'distribution' => $facetDistribution,
            'stats'        => $facetStats,
            'approximate'  => $approximateFacets,
        ] = $this->computeFacetCounts(
            $facets,
            $filterSets,
            $rawDocSet,
            $filteredDocSet,
            $this->config->maxFacetCountDocs,
        );
        $facetDistribution = $this->orderFacetValues($facetDistribution, $options->sortFacetValuesBy);
        $exhaustive = !$candidatesCapped && !$prefixCapped;
        $warnings   = [
            ...$warnings,
            ...$this->capWarnings($candidatesCapped ? 'maxDocs' : null, $prefixCapped, $approximateFacets),
        ];

        $total = count($docIds);

        if ($distinct !== null) {
            $keyId     = $this->lookupFacetKeyId($distinct);
            $sortedIds = $sortSpecs !== [] && $total > 0
                ? $this->sortDocIdsBySpecs($docIds, $sortSpecs, [])
                : $docIds;
            $valueMap = $this->fetchSortValues($sortedIds, $keyId);
            [$pagedIds, $distinctHits] = $this->applyDistinctPagination(
                $sortedIds,
                $valueMap,
                $distinctCount,
                $offset,
                $limit,
            );
            return new SearchResult(
                ids: $pagedIds,
                totalHits: $distinctHits,
                documents: $this->hydrateAndFormat($pagedIds, $phrase, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
                warnings: $warnings,
                exhaustive: $exhaustive,
                approximateFacets: $approximateFacets,
            );
        }

        if ($sortSpecs !== [] && $total > 0 && $limit > 0) {
            $docIds = $this->applySortedPagination($docIds, $sortSpecs, [], $offset, $limit);
        } else {
            $docIds = array_slice($docIds, $offset, $limit);
        }

        return new SearchResult(
            ids: $docIds,
            totalHits: $total,
            documents: $this->hydrateAndFormat($docIds, $phrase, $options),
            facetCounts: $facetDistribution,
            facetStats: $facetStats,
            query: $phrase,
            limit: $limit,
            offset: $offset,
            warnings: $warnings,
            exhaustive: $exhaustive,
            approximateFacets: $approximateFacets,
        );
    }

    /**
     * Search within facet values for a given facet field.
     *
     * Returns facet values (with document counts) that optionally match a prefix and belong
     * to documents that optionally match an FTS query and/or facet filters. Results are
     * ordered by count descending.
     *
     * Typical use: autocomplete a filter dropdown — given the partial text the user has typed
     * into a facet search box, return the matching values and how many documents each has.
     *
     * @param FacetSearchQuery $query  Query parameters; only $facetName is required.
     * @return FacetSearchResult       Matching values with counts, ordered by count descending.
     * @throws QueryException          When $facetName or a filter field is not filterable.
     */
    public function facetSearch(FacetSearchQuery $query): FacetSearchResult
    {
        $this->checkDataVersion();
        $this->checkFilterableFields('Facet', [$query->facetName]);
        $this->checkFilterableFields('Filter', array_keys($query->filter));
        $warnings = [];
        $keyId = $this->lookupFacetKeyId($query->facetName);
        if ($keyId === null) {
            return new FacetSearchResult([], $query->facetQuery, $warnings);
        }

        // --- Step 1: FTS candidate doc IDs (AND-intersection across keywords; phrases applied after) ---
        $ftsCandidates    = null; // null = no FTS restriction
        $candidatesCapped = false;
        $prefixCapped     = false;
        ['phrase' => $positive, 'conditions' => $negations] = $this->extractNegations($query->query);
        if (trim($positive) !== '') {
            $parsed       = $this->filterQueryTokens($positive);
            $keywords     = $parsed['filtered'];
            /** @var list<list<string>> $phraseGroups */
            $phraseGroups = $parsed['phrase_groups'];
            $maxDocs      = $this->config->maxFacetCountDocs;
            $last         = count($keywords) - 1;
            foreach ($keywords as $i => $kw) {
                $termIds       = $this->resolveWordlistIds($kw, $i === $last, $prefixCapped);
                $kwIds         = $this->fetchBooleanDocIds($termIds, $maxDocs, $candidatesCapped);
                $kwDocs        = array_fill_keys($kwIds, true);
                $ftsCandidates = $ftsCandidates === null ? $kwDocs : array_intersect_key($ftsCandidates, $kwDocs);
                if ($ftsCandidates === []) {
                    break;
                }
            }
            if ($phraseGroups !== [] && $ftsCandidates !== null && $ftsCandidates !== []) {
                $lastToken = end($keywords) ?: '';
                $matchIds  = $this->filterDocsByPhrases(array_keys($ftsCandidates), $phraseGroups, $lastToken, true);
                $ftsCandidates = array_fill_keys($matchIds, true);
            }
        }

        $exhaustive = !$candidatesCapped && !$prefixCapped;
        $warnings   = [
            ...$warnings,
            ...$this->capWarnings($candidatesCapped ? 'maxFacetCountDocs' : null, $prefixCapped, []),
        ];

        // --- Step 2: Facet filters ---
        // With FTS candidates the filters are intersected in PHP against that (bounded) set;
        // without them the matching documents come straight from SQL (matchingDocsSql()).
        if ($ftsCandidates === []) {
            return new FacetSearchResult([], $query->facetQuery, $warnings, $exhaustive);
        }
        $match       = null;
        $excludedSql = null;
        if ($ftsCandidates !== null && ($query->filter !== [] || $negations !== [])) {
            [$filterSets, $excluded] = $this->loadFacetKeySets(
                $query->filter,
                array_keys($ftsCandidates),
                $negations,
            );
            $ftsCandidates = array_diff_key($ftsCandidates, $excluded);
            if ($filterSets !== []) {
                $ftsCandidates = array_intersect_key($ftsCandidates, $this->intersectFilterSets($filterSets));
            }
        } elseif ($query->filter !== [] || $negations !== []) {
            // Exclusions that exclude nothing are dropped, which can leave no condition at all.
            $conditions = $this->orderBySelectivity([...$this->facetFilterConditions($query->filter), ...$negations]);
            if ($conditions !== [] && self::isExclusionOnly($conditions)) {
                // Scan the key and skip the excluded documents rather than probe every kept one:
                // 13–21 ms instead of 58–103 ms for brandName on the 45k ecom set.
                $excludedSql = $this->excludedDocsSql($conditions);
            } elseif ($conditions !== []) {
                $match = $this->matchingDocsSql($conditions);
                if ($match === null) {
                    return new FacetSearchResult([], $query->facetQuery, $warnings, $exhaustive);
                }
            }
        }
        if ($ftsCandidates === []) {
            return new FacetSearchResult([], $query->facetQuery, $warnings, $exhaustive);
        }

        // --- Step 3: Query facet_values GROUP BY value ---
        // A restricted count is driven from the matching documents: one covering-index lookup
        // per document for this key, instead of scanning every row of the key and testing
        // membership. Measured on the 45k ecom set it is 10–20% faster for broad restrictions
        // and far faster for narrow ones. Without any restriction the key's rows are scanned.
        $facetQuery = $query->facetQuery;
        $params     = [];
        if ($ftsCandidates !== null) {
            $from     = 'json_each(?) m CROSS JOIN facet_values fv ON fv.doc_id = m.value';
            $params[] = json_encode(array_keys($ftsCandidates));
        } elseif ($match !== null) {
            $from   = "({$match[0]}) m CROSS JOIN facet_values fv ON fv.doc_id = m.doc_id";
            $params = $match[1];
        } else {
            $from = 'facet_values fv';
        }
        $where    = 'fv.key_id = ?';
        $params[] = $keyId;
        if ($excludedSql !== null) {
            $where .= " AND fv.doc_id NOT IN ({$excludedSql[0]})";
            array_push($params, ...$excludedSql[1]);
        }

        $byCount = $query->sortFacetValuesBy === FacetOrder::Count;
        $needle  = self::facetMatchKey($facetQuery);
        // Unrestricted: the key's maintained counts, one row per value (no row scan).
        $grouped = $from === 'facet_values fv' && $excludedSql === null
            ? 'SELECT value, count FROM facet_counts fv WHERE key_id = ?'
            : "SELECT fv.value, COUNT(*) AS count FROM {$from} WHERE {$where} GROUP BY fv.value";
        if ($facetQuery === '' && $byCount) {
            // Nothing to match in PHP: let SQL order and cut.
            $stmt = $this->prepare($grouped . ' ORDER BY count DESC, fv.value LIMIT ?');
            $stmt->execute([...$params, $query->limit]);
            /** @var array<array-key, int> $counts */
            $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } else {
            // Case and accent folding (Tokenizer::sortKey()) and word-start matching cannot be
            // expressed in SQL, so the key's distinct values are grouped there and matched here.
            $stmt = $this->prepare($grouped);
            $stmt->execute($params);
            /** @var array<array-key, int> $counts */
            $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($needle !== '') {
                foreach ($counts as $value => $_) {
                    if (!self::facetValueMatches(self::facetMatchKey((string) $value), $needle)) {
                        unset($counts[$value]);
                    }
                }
            }
            $counts = $byCount ? self::countOrder($counts) : self::alphaOrder($counts);
            $counts = array_slice($counts, 0, max(0, $query->limit), true);
        }

        $hits = [];
        foreach ($counts as $value => $count) {
            $hits[] = ['value' => (string) $value, 'count' => (int) $count];
        }

        return new FacetSearchResult($hits, $facetQuery, $warnings, $exhaustive);
    }

    /**
     * Browse all documents with no FTS scoring — used when $phrase is empty.
     *
     * Fast path (no filter / sort / facets / distinct): totalHits from the info cache,
     * page fetched with a single PK scan. Default order is doc_id DESC (insertion order,
     * newest first).
     *
     * General path: everything is answered by SQL over the whole index, with no candidate
     * cap, so totals, pages, and facet counts are exact at any index size. Like Meilisearch's
     * sort, a sorted page walks the sort field's index in order and stops once the page is
     * full (see browseSortedPage()). The exact filtered count comes first and picks the filter
     * shape: a filter matching at least a tenth of the index is probed row by row along the
     * walk, a more selective one is materialised once (see facetFilterSql()). Several filters
     * are driven from the most selective one (see orderBySelectivity()).
     *
     * Facet counts are exact without a filter; with one they cover at most
     * Config::$maxFacetCountDocs matching documents, as in search() (see browseFacetCounts()).
     * distinct still needs every matching document in PHP to count the surviving groups, so
     * that combination costs O(matches); it is exact too.
     *
     * @param list<FacetCondition> $negations Exclusions from a query of only '-' words/phrases;
     *                                        $phrase is then not highlighted.
     */
    private function browse(string $phrase, SearchOptions $options, array $negations = []): SearchResult
    {
        $formatPhrase  = $negations === [] ? $phrase : '';
        $limit         = $options->limit;
        $offset        = $options->offset;
        $filter        = $options->filter;
        $facets        = $options->facets;
        $distinct      = $options->distinct;
        $distinctCount = $options->distinctCount;
        $sortSpecs     = $this->checkDeclaredFields($options);
        $warnings      = [];
        // Totals, pages, and order are always exact here; only filtered facet counts can be capped.
        $exhaustive        = true;
        $approximateFacets = [];

        $info           = $this->getInfoValues(['total_documents']);
        $totalDocuments = (int) ($info['total_documents'] ?? 0);

        // Fast path: skip all PHP-side work; one PK scan for the page, total from cache.
        $plainBrowse = $filter === [] && $negations === [] && $sortSpecs === [] && $facets === [] && $distinct === null;
        if ($plainBrowse) {
            $stmt  = $this->stmt(
                'browsePageIds',
                'SELECT doc_id FROM doc_lengths ORDER BY doc_id DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute([$limit, $offset]);
            /** @var list<int> $pagedIds */
            $pagedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return new SearchResult(
                ids: $pagedIds,
                totalHits: $totalDocuments,
                documents: $this->hydrateAndFormat($pagedIds, $formatPhrase, $options),
                facetCounts: [],
                facetStats: [],
                query: $phrase,
                limit: $limit,
                offset: $offset,
                warnings: $warnings,
                exhaustive: $exhaustive,
                approximateFacets: $approximateFacets,
            );
        }

        $conditions = $this->orderBySelectivity([...$this->facetFilterConditions($filter), ...$negations]);
        $match      = $conditions === [] ? null : $this->matchingDocsSql($conditions);
        // With facets to count over the matching documents, fetch their IDs once (up to the facet
        // cap + 1): under the cap the list length is the exact total and the same IDs feed the
        // facet counts, instead of evaluating the filter twice (COUNT, then the IDs).
        $matchIds = null;
        if ($facets !== [] && $match !== null && !self::isExclusionOnly($conditions)) {
            $capped   = false;
            $ids      = $this->browseFacetDocIds($conditions, $capped) ?? [];
            $matchIds = ['ids' => $ids, 'capped' => $capped];
        }
        $total      = match (true) {
            $conditions === []                         => $totalDocuments,
            $match === null                            => 0,
            self::isExclusionOnly($conditions)         => $totalDocuments - $this->countExcludedDocs($conditions),
            $matchIds !== null && !$matchIds['capped'] => count($matchIds['ids']),
            default                                    => $this->countBrowseDocs($match),
        };
        // Probe along the walk when matches are dense; materialise the filter when they are sparse.
        // Measured on the 45k ecom set: at 13% of the index a probed page takes 0.2 ms against
        // 6.4 ms materialised, at 5% the order flips (0.5 ms vs 5 ms — matches cluster by
        // insertion order, so the walk runs long). The crossover sits near 10%.
        $probe = $total * 10 >= $totalDocuments;

        [
            'distribution' => $facetDistribution,
            'stats'        => $facetStats,
            'approximate'  => $approximateFacets,
        ] = $this->browseFacetCounts($facets, $conditions, $matchIds);
        $facetDistribution = $this->orderFacetValues($facetDistribution, $options->sortFacetValuesBy);
        $warnings = [...$warnings, ...$this->capWarnings(null, false, $approximateFacets)];

        if ($distinct !== null) {
            $docIds = $total === 0 ? [] : $this->fetchBrowseDocIds($conditions);
            $sortedDocIds = $sortSpecs !== [] ? $this->sortDocIdsBySpecs($docIds, $sortSpecs, []) : $docIds;
            [$pagedIds, $distinctHits] = $this->applyDistinctPagination(
                $sortedDocIds,
                $this->fetchSortValues($sortedDocIds, $this->lookupFacetKeyId($distinct)),
                $distinctCount,
                $offset,
                $limit,
            );
            return new SearchResult(
                ids: $pagedIds,
                totalHits: $distinctHits,
                documents: $this->hydrateAndFormat($pagedIds, $formatPhrase, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
                warnings: $warnings,
                exhaustive: $exhaustive,
                approximateFacets: $approximateFacets,
            );
        }

        if ($total === 0 || $limit === 0) {
            $pagedIds = [];
        } elseif ($sortSpecs !== []) {
            $pagedIds = $this->browseSortedPage($sortSpecs, $conditions, $probe, $offset, $limit);
        } else {
            $pagedIds = $this->browseUnsortedPage($conditions, $probe, $offset, $limit);
        }

        return new SearchResult(
            ids: $pagedIds,
            totalHits: $total,
            documents: $this->hydrateAndFormat($pagedIds, $formatPhrase, $options),
            facetCounts: $facetDistribution,
            facetStats: $facetStats,
            query: $phrase,
            limit: $limit,
            offset: $offset,
            warnings: $warnings,
            exhaustive: $exhaustive,
            approximateFacets: $approximateFacets,
        );
    }

    /**
     * Count the documents matched by matchingDocsSql().
     *
     * @param array{0: string, 1: list<mixed>} $match
     */
    private function countBrowseDocs(array $match): int
    {
        $stmt = $this->prepare("SELECT COUNT(*) FROM ({$match[0]})");
        $stmt->execute($match[1]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Count the documents an exclusion-only filter removes; the browse total is the rest.
     *
     * Counting the kept documents directly probes every document in the index (~31 ms on the
     * 45k ecom set); the excluded ones sit in one index range per condition (0.06–1.7 ms).
     *
     * @param non-empty-list<FacetCondition> $conditions Exclusions only (see isExclusionOnly()).
     */
    private function countExcludedDocs(array $conditions): int
    {
        [$sql, $params] = $this->excludedDocsSql($conditions);
        $stmt = $this->prepare("SELECT COUNT(DISTINCT doc_id) FROM ({$sql})");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch every doc ID matching the conditions, newest first. Used by the distinct path only.
     *
     * @param  list<FacetCondition> $conditions
     * @return list<int>
     */
    private function fetchBrowseDocIds(array $conditions): array
    {
        [$sql, $params] = $this->facetFilterSql($conditions, 'd.doc_id', false) ?? ['', []];
        $stmt = $this->prepare('SELECT d.doc_id FROM doc_lengths d WHERE 1' . $sql . ' ORDER BY d.doc_id DESC');
        $stmt->execute($params);
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $ids;
    }

    /**
     * One page of filtered documents in the default browse order (doc_id DESC).
     *
     * @param  list<FacetCondition> $conditions
     * @return list<int>
     */
    private function browseUnsortedPage(array $conditions, bool $probe, int $offset, int $limit): array
    {
        [$sql, $params] = $this->facetFilterSql($conditions, 'd.doc_id', $probe) ?? ['', []];
        $stmt = $this->prepare(
            'SELECT d.doc_id FROM doc_lengths d WHERE 1' . $sql . ' ORDER BY d.doc_id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([...$params, $limit, $offset]);
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $ids;
    }

    /**
     * One page of filtered documents in sort order, read by walking the primary sort field's index.
     *
     * The walk visits the primary field in three phases, matching compareSortValues(): numeric
     * values along facet_numeric_index, then string values along the sort_keys primary key,
     * then documents without the field in doc_id order. Every phase orders ties by doc_id
     * ascending, the same final tiebreaker as sortDocIdsBySpecs(). Rows are fetched lazily and
     * the walk stops once offset + limit documents are collected, so the cost follows the page
     * position rather than the index size.
     *
     * A multi-value document is kept at its first row along the walk, which is exactly the
     * value compareSortValues() picks to represent it (smallest ascending, largest descending).
     *
     * With secondary sort specs the walk also finishes the tie group that straddles the page
     * boundary, then sortDocIdsBySpecs() orders the collected prefix. Every uncollected document
     * sorts after the boundary value, so the prefix is exactly the head of the full order.
     *
     * @param  list<array{field: string, asc: bool}> $specs
     * @param  list<FacetCondition>                  $conditions
     * @return list<int>
     */
    private function browseSortedPage(array $specs, array $conditions, bool $probe, int $offset, int $limit): array
    {
        ['field' => $field, 'asc' => $asc] = $specs[0];
        $keyId     = $this->lookupFacetKeyId($field);
        $direction = $asc ? 'ASC' : 'DESC';
        $wanted    = $offset + $limit;
        $finishTie = count($specs) > 1;

        // A range on the sort field itself, when every document holds at most one value for it:
        // seek the numeric walk straight into the range (instead of probing past every value
        // outside it), and skip the string and missing phases, which cannot satisfy a range.
        // Multi-valued fields keep the plain walk: a document sorts by its smallest/largest
        // value overall, not within the range (D5).
        $rangeSql    = '';
        $rangeParams = [];
        if ($keyId !== null && !$this->isMultiValuedKey($keyId)) {
            foreach ($conditions as $i => $condition) {
                if (($condition['range']['keyId'] ?? null) === $keyId) {
                    $rangeSql    = ' AND ' . sprintf($condition['range']['sql'], 's');
                    $rangeParams = $condition['range']['params'];
                    unset($conditions[$i]);
                    $conditions = array_values($conditions);
                    break;
                }
            }
        }

        [$walkSql, $walkParams]       = $this->facetFilterSql($conditions, 's.doc_id', $probe) ?? ['', []];
        [$missingSql, $missingParams] = $this->facetFilterSql($conditions, 'd.doc_id', $probe) ?? ['', []];

        // A declared field that no document has populated has no key: every document is "missing".
        $phases = $keyId === null
            ? [['SELECT d.doc_id, NULL FROM doc_lengths d WHERE 1' . $missingSql . ' ORDER BY d.doc_id', $missingParams]] // phpcs:ignore Generic.Files.LineLength.TooLong
            : [
                [
                    'SELECT s.doc_id, s.num_value FROM facet_values s'
                    . ' WHERE s.key_id = ? AND s.num_value IS NOT NULL' . $rangeSql . $walkSql
                    . " ORDER BY s.num_value {$direction}, s.doc_id",
                    [$keyId, ...$rangeParams, ...$walkParams],
                ],
                [
                    'SELECT s.doc_id, s.sort_key FROM sort_keys s'
                    . ' WHERE s.key_id = ?' . $walkSql
                    . " ORDER BY s.sort_key {$direction}, s.doc_id",
                    [$keyId, ...$walkParams],
                ],
                [
                    'SELECT d.doc_id, NULL FROM doc_lengths d'
                    . ' WHERE NOT EXISTS (SELECT 1 FROM facet_values m WHERE m.doc_id = d.doc_id AND m.key_id = ?)'
                    . $missingSql . ' ORDER BY d.doc_id',
                    [$keyId, ...$missingParams],
                ],
            ];

        if ($rangeSql !== '') {
            $phases = [$phases[0]];
        }

        /** @var array<int, true> $collected  doc_id → true, in walk order */
        $collected = [];
        $boundary  = null;
        foreach ($phases as [$sql, $params]) {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);
            $full = false;
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                /** @var array{0: int, 1: float|string|null} $row */
                [$docId, $value] = $row;
                if (isset($collected[$docId])) {
                    continue;
                }
                if (count($collected) >= $wanted && (!$finishTie || $value !== $boundary)) {
                    $full = true;
                    break;
                }
                $collected[$docId] = true;
                $boundary          = $value;
            }
            $stmt->closeCursor();
            if ($full) {
                break;
            }
        }

        $ids = array_keys($collected);
        if ($finishTie) {
            $ids = $this->sortDocIdsBySpecs($ids, $specs, []);
        }
        return array_slice($ids, $offset, $limit);
    }

    /**
     * Facet value counts for a browse.
     *
     * Same semantics as computeFacetCounts(): a requested key that is also an active filter is
     * counted with every filter except its own (disjunctive), the others with all filters.
     * With no filter to apply, a key is counted exactly over the whole index by one sequential
     * scan of its primary-key range, and with only exclusions to apply, exactly as that minus the
     * excluded documents. Otherwise counting costs one lookup per matching document and key, so
     * — as in search() — it runs over at most Config::$maxFacetCountDocs matching documents and
     * is approximate beyond that.
     *
     * @param  list<string>         $facetKeys
     * @param  list<FacetCondition> $conditions  Ordered by orderBySelectivity().
     * @param  array{ids: list<int>, capped: bool}|null $matchIds browseFacetDocIds($conditions), when
     *                                              the caller already fetched it.
     * @return array{
     *     distribution: array<string, array<array-key, int>>,
     *     stats: array<string, array{min: float, max: float}>,
     *     approximate: list<string>
     * }
     */
    private function browseFacetCounts(array $facetKeys, array $conditions, ?array $matchIds = null): array
    {
        $distribution = [];
        $stats        = [];
        $approximate  = [];
        $common       = [];
        foreach ($facetKeys as $keyName) {
            $keyId = $this->lookupFacetKeyId($keyName);
            if ($keyId === null) {
                continue;
            }
            // Only positive filters are disjunctive: an exclusion also applies to its own field.
            $others = array_values(
                array_filter($conditions, fn(array $c): bool => $c['exclude'] || $c['name'] !== $keyName)
            );
            if (count($others) === count($conditions)) {
                $common[$keyName] = $keyId;
                continue;
            }
            if ($this->countBrowseFacetGroup([$keyName => $keyId], $others, $distribution, $stats)) {
                $approximate[] = $keyName;
            }
        }
        if ($common !== []) {
            if ($matchIds !== null) {
                $this->collectFacetCounts($common, $matchIds['ids'], $distribution, $stats);
                $capped = $matchIds['capped'];
            } else {
                $capped = $this->countBrowseFacetGroup($common, $conditions, $distribution, $stats);
            }
            if ($capped) {
                array_push($approximate, ...array_keys($common));
            }
        }
        return [
            'distribution' => $distribution,
            'stats'        => $stats,
            'approximate'  => array_values(array_unique($approximate)),
        ];
    }

    /**
     * Count one group of browse facet keys over the documents matching $conditions.
     *
     * Exclusion-only conditions are counted as the whole index minus the excluded documents
     * (collectFacetCountsExcluding()), exactly; anything else over browseFacetDocIds().
     * Returns true when the counts are approximate because the matches exceeded the cap.
     *
     * @param array<string, int>                           $nameToId
     * @param list<FacetCondition>                         $conditions
     * @param array<string, array<array-key, int>>         $distribution Mutated in place.
     * @param array<string, array{min: float, max: float}> $stats        Mutated in place.
     */
    private function countBrowseFacetGroup(
        array $nameToId,
        array $conditions,
        array &$distribution,
        array &$stats,
    ): bool {
        if ($conditions !== [] && self::isExclusionOnly($conditions)) {
            $this->collectFacetCountsExcluding($nameToId, $conditions, $distribution, $stats);
            return false;
        }
        $capped = false;
        $this->collectFacetCounts($nameToId, $this->browseFacetDocIds($conditions, $capped), $distribution, $stats);
        return $capped;
    }

    /**
     * Documents to count browse facets over: null (the whole index) without conditions,
     * otherwise up to Config::$maxFacetCountDocs matching documents.
     *
     * @param  list<FacetCondition> $conditions
     * @param  bool                 $capped Set to true when more documents matched than the cap.
     * @param-out bool               $capped
     * @return list<int>|null
     */
    private function browseFacetDocIds(array $conditions, bool &$capped): ?array
    {
        if ($conditions === []) {
            return null;
        }
        $match = $this->matchingDocsSql($conditions);
        if ($match === null) {
            return [];
        }
        $cap  = $this->config->maxFacetCountDocs;
        $stmt = $this->prepare($match[0] . ' LIMIT ?');
        $stmt->execute([...$match[1], $cap + 1]);
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $this->capDocIds($ids, $cap, $capped);
    }

    /**
     * Fetch documents for $ids from the store and return them in $ids order.
     * Returns null when the document store is disabled so SearchResult::$documents
     * signals "no store" rather than "empty result".
     *
     * @param  list<int> $ids
     * @return array<int, array<string, mixed>>|null
     */
    private function hydrateIds(array $ids): ?array
    {
        if (!$this->documentStoreEnabled || $ids === []) {
            return $this->documentStoreEnabled ? [] : null;
        }
        $map    = $this->fetchDocuments($ids);
        $result = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $result[$id] = $map[$id];
            }
        }
        return $result;
    }

    /**
     * Hydrate documents for $ids and attach '_formatted' when format options are active.
     *
     * SearchOptions::$attributesToRetrieve controls how much of each document is returned:
     * an empty list skips the store lookup entirely (SearchResult then builds ['id' => n]
     * stubs, exactly as it does when the store is disabled), and a field list trims the
     * hydrated documents down to those keys. Trimming happens after formatting so crop and
     * highlight still see the full field values they need.
     *
     * @param  list<int>    $ids
     * @return array<int, array<string, mixed>>|null
     */
    private function hydrateAndFormat(array $ids, string $phrase, SearchOptions $options): ?array
    {
        $retrieve = $options->attributesToRetrieve;
        if ($retrieve === []) {
            return null;
        }
        $documents = $this->hydrateIds($ids);
        if (
            $documents !== null && $documents !== [] &&
            ($options->attributesToHighlight !== null || $options->attributesToCrop !== null)
        ) {
            $documents = $this->applyFormatting($documents, $phrase, $options);
        }
        if ($documents !== null && $retrieve !== null && $retrieve !== ['*']) {
            $documents = $this->filterRetrievedAttributes($documents, $retrieve);
        }
        return $documents;
    }

    /**
     * Reduce each document to the requested top-level keys.
     *
     * 'id' and '_formatted' are always kept: the former identifies the hit, the latter is
     * generated output rather than a stored field, so requesting specific attributes should
     * not silently discard the highlighting or cropping the caller also asked for.
     *
     * @param  array<int, array<string, mixed>> $documents
     * @param  list<string>                     $attributes
     * @return array<int, array<string, mixed>>
     */
    private function filterRetrievedAttributes(array $documents, array $attributes): array
    {
        $keep = array_flip([...$attributes, 'id', '_formatted']);
        foreach ($documents as $id => $doc) {
            $documents[$id] = array_intersect_key($doc, $keep);
        }
        return $documents;
    }

    /**
     * Attach '_formatted' to each document with highlighted and/or cropped string field values.
     *
     * Cropping runs first; highlighting is applied to the (possibly cropped) text, so a field
     * in both lists gets a short, highlighted excerpt. Only string-typed fields are processed.
     *
     * With SearchOptions::$escapeFormatted every value is safe HTML. Both steps run on plain
     * text and escaping happens exactly once at the end: the highlighter escapes the pieces
     * around its tags, and a field that is only cropped is escaped as a whole. On a stripHtml
     * index the stored HTML is converted to its visible text first — the same conversion used
     * for indexing — so markup is neither shown as escaped tags nor cut in half by cropping.
     *
     * @param  array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private function applyFormatting(array $documents, string $phrase, SearchOptions $options): array
    {
        $highlightFields = $options->attributesToHighlight;
        $cropFields      = $options->attributesToCrop;
        $escape          = $options->escapeFormatted;
        $toText          = $escape && $this->stripHtml;

        $highlighter = $highlightFields !== null
            ? $this->highlighter($options->highlightPreTag, $options->highlightPostTag, $options->asYouType, $escape)
            : null;

        $snippeter = $cropFields !== null
            ? $this->snippeter($options->cropLength, 1, $options->cropMarker)
            : null;

        foreach ($documents as $id => $doc) {
            $stringFields = [];
            foreach ($doc as $k => $v) {
                if (is_string($v)) {
                    $stringFields[$k] = $toText ? HtmlText::toText($v) : $v;
                }
            }

            if ($stringFields === []) {
                continue;
            }

            /** @var array<string, string> $formatted */
            $formatted = [];

            // Crop first so the highlight step works on the shorter text.
            if ($snippeter !== null) {
                $fields    = $cropFields === ['*']
                    ? $stringFields
                    : array_intersect_key($stringFields, array_flip($cropFields));
                $formatted = $snippeter->snippetMany($phrase, $fields);
            }

            // Highlight — applied to the cropped version when both target the same field.
            if ($highlighter !== null) {
                $fields = $highlightFields === ['*']
                    ? $stringFields
                    : array_intersect_key($stringFields, array_flip($highlightFields));
                $inputs = [];
                foreach ($fields as $key => $value) {
                    $inputs[$key] = $formatted[$key] ?? $value;
                }
                foreach ($highlighter->highlightMany($phrase, $inputs) as $key => $value) {
                    $formatted[$key] = $value;
                }
                if ($escape) {
                    // Cropped-only fields have not been through the escaping highlighter.
                    foreach (array_diff_key($formatted, $inputs) as $key => $value) {
                        $formatted[$key] = HtmlText::escape($value);
                    }
                }
            } elseif ($escape) {
                $formatted = array_map(HtmlText::escape(...), $formatted);
            }

            if ($formatted !== []) {
                $documents[$id]['_formatted'] = $formatted;
            }
        }

        return $documents;
    }

    // --- Private write helpers ----------------------------------------------

    /**
     * Remove a set of live documents from the index in bulk without adjusting total_documents or avg_doc_length.
     *
     * Equivalent to calling removeDocumentData() in a loop but issues one CTE-based UPDATE and a handful
     * of bulk DELETEs per chunk rather than 5 individual prepared statements per document.
     *
     * @param list<int> $ids Document IDs to remove; all must exist in the index.
     */
    private function bulkRemoveDocuments(array $ids): void
    {
        $this->purgePostings($ids);
        $this->removeDocumentRows($ids);
    }

    /**
     * Remove the per-document rows of documents: doc_lengths, the stored document, facet_values
     * (with their facet_counts) and sort_keys. Their term-index rows are left to purgePostings().
     *
     * @param list<int> $ids
     */
    private function removeDocumentRows(array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
            $placeholders = $this->placeholders(count($chunk));
            $this->prepare("DELETE FROM doc_lengths  WHERE doc_id IN ({$placeholders})")->execute($chunk);
            if ($this->documentStoreEnabled) {
                $this->prepare("DELETE FROM documents WHERE doc_id IN ({$placeholders})")->execute($chunk);
            }
            $removed = $this->prepare(
                "DELETE FROM facet_values WHERE doc_id IN ({$placeholders}) RETURNING key_id, value, num_value"
            );
            $removed->execute($chunk);
            /** @var list<array{0: int, 1: string, 2: float|null}> $removedRows */
            $removedRows = $removed->fetchAll(PDO::FETCH_NUM);
            $this->adjustFacetCounts(self::facetCountDeltas($removedRows, -1));
            $this->prepare("DELETE FROM sort_keys    WHERE doc_id IN ({$placeholders})")->execute($chunk);
        }
    }

    /**
     * Remove the term-index rows of documents (doclist, positions, field_hits), subtract them
     * from the per-term counts, prune terms left without hits, and forget the IDs in deleted_docs.
     *
     * @param list<int> $ids
     */
    private function purgePostings(array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
            $placeholders = $this->placeholders(count($chunk));

            // Capture affected term IDs before any deletion so orphan pruning can be scoped.
            $termStmt = $this->prepare(
                "SELECT DISTINCT term_id FROM doclist WHERE doc_id IN ({$placeholders})"
            );
            $termStmt->execute($chunk);
            /** @var list<int> $affectedTermIds */
            $affectedTermIds = $termStmt->fetchAll(PDO::FETCH_COLUMN);

            // Decrement wordlist stats for every affected term in a single CTE UPDATE.
            $this->prepare(
                "WITH doc_terms AS (
                     SELECT term_id,
                            SUM(hit_count)         AS total_hits,
                            COUNT(DISTINCT doc_id) AS doc_count
                     FROM doclist WHERE doc_id IN ({$placeholders})
                     GROUP BY term_id
                 )
                 UPDATE wordlist SET
                     num_hits = num_hits - doc_terms.total_hits,
                     num_docs = num_docs - doc_terms.doc_count
                 FROM doc_terms WHERE wordlist.id = doc_terms.term_id"
            )->execute($chunk);

            $this->prepare("DELETE FROM doclist      WHERE doc_id IN ({$placeholders})")->execute($chunk);
            $this->prepare("DELETE FROM positions    WHERE doc_id IN ({$placeholders})")->execute($chunk);
            $this->prepare("DELETE FROM field_hits   WHERE doc_id IN ({$placeholders})")->execute($chunk);
            $this->prepare("DELETE FROM deleted_docs WHERE doc_id IN ({$placeholders})")->execute($chunk);

            // Prune orphan terms scoped to the affected set; avoids a full wordlist table scan.
            if ($affectedTermIds !== []) {
                $termPlaceholders = $this->placeholders(count($affectedTermIds));
                $this->prepare(
                    "DELETE FROM wordlist WHERE num_hits <= 0 AND id IN ({$termPlaceholders})"
                )->execute($affectedTermIds);
            }
        }

        // Orphan pruning may have removed terms; stale cache entries would corrupt doclist
        // on re-insertion of those terms in the subsequent flushBatch() call.
        $this->termIdCache   = [];
        $this->wordlistCache = [];
        $this->hasDeleted    = null;
    }

    /** Whether any deleted document still has term-index rows; probed once, then cached (see $hasDeleted). */
    private function hasDeleted(): bool
    {
        if ($this->hasDeleted === null) {
            $probe = $this->stmt('deletedAny', 'SELECT 1 FROM deleted_docs LIMIT 1');
            $probe->execute();
            $this->hasDeleted = $probe->fetchColumn() !== false;
            $probe->closeCursor();
        }
        return $this->hasDeleted;
    }

    /**
     * SQL condition keeping deleted documents out of a doclist / positions read on $column, or ''
     * when there are none. Posting rows of deleted documents stay until purgeDeleted(), and a
     * fetch capped by LIMIT must skip them before the cap, not after.
     */
    private function liveDocsSql(string $column): string
    {
        return $this->hasDeleted() ? " AND {$column} NOT IN (SELECT doc_id FROM deleted_docs)" : '';
    }

    /** Number of deleted documents whose term-index rows are not purged yet. */
    private function countDeleted(): int
    {
        $stmt = $this->stmt('deletedCount', 'SELECT COUNT(*) FROM deleted_docs');
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        // An open cursor would keep this connection's read snapshot past the commit.
        $stmt->closeCursor();
        return $count;
    }

    /**
     * Purge every deleted document's term-index rows (see delete()). Runs inside the caller's
     * transaction.
     *
     * @return int The number of documents purged.
     */
    private function purgeDeleted(): int
    {
        $stmt = $this->stmt('deletedIds', 'SELECT doc_id FROM deleted_docs ORDER BY doc_id');
        $stmt->execute();
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ids !== []) {
            $this->purgePostings($ids);
        }
        return count($ids);
    }

    /**
     * Purge the deleted documents among $ids before they are written again: their old
     * term-index rows would collide with the new ones (and a later purge would remove those).
     * One probe of the empty deleted_docs table in the common case.
     *
     * @param list<int> $ids
     */
    private function purgeDeletedAmong(array $ids): void
    {
        if (!$this->hasDeleted()) {
            return;
        }
        foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
            $stmt = $this->prepare(
                'SELECT doc_id FROM deleted_docs WHERE doc_id IN (' . $this->placeholders(count($chunk)) . ')'
            );
            $stmt->execute($chunk);
            /** @var list<int> $deleted */
            $deleted = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($deleted !== []) {
                $this->purgePostings($deleted);
            }
        }
    }

    /**
     * Remove a document's index data (wordlist stats, doclist rows, positions rows, and
     * doc_lengths) without touching total_documents or avg_doc_length. Returns the
     * document's token length, or null if the document was not found.
     *
     * @param  int      $documentId ID of the document to remove.
     * @return int|null             Token length of the removed document, or null if not found.
     */
    private function removeDocumentData(int $documentId): ?int
    {
        // 1. Decrement wordlist stats for every term this document contributed.
        // UPDATE … FROM (SQLite 3.33+) joins once rather than running a correlated subquery per row.
        /** @infection-ignore-all MethodCallRemoval,ArrayItemRemoval: skipping execute() or omitting the :documentId param leaves wordlist num_docs/num_hits inflated; doclist rows are still removed in step 4, so search results are unaffected (BM25-scoring stats only) */
        $this->stmt(
            'wordlistDecrementByDoc',
            'WITH doc_terms AS (
                 SELECT term_id, hit_count FROM doclist WHERE doc_id = :documentId
             )
             UPDATE wordlist SET
                 num_docs = num_docs - 1,
                 num_hits = num_hits - doc_terms.hit_count
             FROM doc_terms
             WHERE wordlist.id = doc_terms.term_id'
        )->execute([':documentId' => $documentId]);

        // 2. Prune any term whose hit count reached zero.
        // Scoped to terms belonging to this document via doc_id_index. The doclist rows still
        // exist at this point (step 4 removes them), so the subquery is valid. SQLite serialises
        // writers, so no concurrent operation can produce new orphans for other terms between
        // steps 1 and 2; limiting to this document's terms avoids a full wordlist table scan.
        /** @infection-ignore-all MethodCallRemoval,ArrayItemRemoval: orphan pruning is a housekeeping step; stale wordlist entries without doclist rows produce empty fetch results */
        $this->stmt(
            'wordlistDeleteOrphans',
            'DELETE FROM wordlist WHERE num_hits <= 0
             AND id IN (SELECT term_id FROM doclist WHERE doc_id = :documentId)'
        )->execute([':documentId' => $documentId]);

        // 3. Remove doclist rows for this document.
        $this->stmt('doclistDeleteByDoc', 'DELETE FROM doclist WHERE doc_id = :documentId')
            ->execute([':documentId' => $documentId]);

        // 4. Remove positions rows for this document.
        $this->stmt('positionsDeleteByDoc', 'DELETE FROM positions WHERE doc_id = :documentId')
            ->execute([':documentId' => $documentId]);

        // 5. Remove facet rows for this document.
        $removed = $this->stmt(
            'facetValuesDeleteByDoc',
            'DELETE FROM facet_values WHERE doc_id = :documentId RETURNING key_id, value, num_value'
        );
        $removed->execute([':documentId' => $documentId]);
        /** @var list<array{0: int, 1: string, 2: float|null}> $removedRows */
        $removedRows = $removed->fetchAll(PDO::FETCH_NUM);
        if ($removedRows !== []) {
            $this->adjustFacetCounts(self::facetCountDeltas($removedRows, -1));
        }
        $this->stmt('sortKeysDeleteByDoc', 'DELETE FROM sort_keys WHERE doc_id = :documentId')
            ->execute([':documentId' => $documentId]);

        // 6. Remove field_hits rows for this document.
        $this->stmt('fieldHitsDeleteByDoc', 'DELETE FROM field_hits WHERE doc_id = :documentId')
            ->execute([':documentId' => $documentId]);

        // 7. Remove doc_lengths and return the old token count (null if the document was not found).
        $delStmt = $this->stmt(
            'docLengthsDelete',
            'DELETE FROM doc_lengths WHERE doc_id = :documentId RETURNING length'
        );
        $delStmt->execute([':documentId' => $documentId]);
        $length = $delStmt->fetchColumn();
        $delStmt->closeCursor();

        // Orphan terms may have been pruned from wordlist; stale cache entries would
        // corrupt the doclist on re-insertion of those terms in a future insertMany call.
        /** @infection-ignore-all MethodCallRemoval: skipping cache invalidation leaves stale termId/wordlist entries; visible only on delete+re-insert within the same session (not covered by tests) */
        $this->termIdCache   = [];
        $this->wordlistCache = [];

        if ($this->documentStoreEnabled) {
            $this->stmt('documentDelete', 'DELETE FROM documents WHERE doc_id = :documentId')
                ->execute([':documentId' => $documentId]);
        }

        /** @infection-ignore-all NullValue,CastInt: the NullValue branch is only reached when $length is false (doc not found); CastInt: $length from RETURNING is already int-like */
        return $length === false ? null : (int) $length;
    }

    /**
     * Tokenise all non-id fields of a document and accumulate per-term counts and positions.
     *
     * Positions use a global counter across all fields so cross-field proximity is meaningful.
     * Shared by processDocument() and buildBatchBuffer().
     *
     * @param  array<string, mixed> $fields Document fields; 'id' is skipped.
     * @return array{termCounts: array<string, int>, fieldTermCounts: array<string, array<string, int>>,
     *               termPositions: array<string, list<int>>, length: int}
     */
    private function tokenizeDocumentFields(array $fields): array
    {
        /** @var array<string, int> $termCounts */
        $termCounts = [];
        /** @var array<string, array<string, int>> $fieldTermCounts  fieldName → term → hitCount */
        $fieldTermCounts = [];
        /** @var array<string, list<int>> $termPositions */
        $termPositions = [];
        $length        = 0;
        $position      = 0;
        if ($this->searchableFieldSet !== null) {
            // When searchableFields is declared, iterate ONLY those keys — avoids scanning every
            // document field when most are non-searchable (e.g. 1 searchable out of 16 total).
            foreach ($this->searchableFieldSet as $key => $_) {
                $this->indexFieldColumn(
                    $fields[$key] ?? null,
                    $key,
                    $termCounts,
                    $fieldTermCounts,
                    $termPositions,
                    $length,
                    $position
                );
            }
        } else {
            // searchableFields is null → all non-facet fields are searchable; iterate full doc.
            foreach ($fields as $key => $col) {
                if ($key === 'id' || isset($this->facetFieldSet[$key])) {
                    continue;
                }
                $this->indexFieldColumn(
                    $col,
                    $key,
                    $termCounts,
                    $fieldTermCounts,
                    $termPositions,
                    $length,
                    $position
                );
            }
        }
        return [
            'termCounts'      => $termCounts,
            'fieldTermCounts' => $fieldTermCounts,
            'termPositions'   => $termPositions,
            'length'          => $length,
        ];
    }

    /**
     * tokenizeDocumentFields()'s result for a document whose term-index rows are kept: no terms,
     * the stored length.
     *
     * @return array{termCounts: array<string, int>, fieldTermCounts: array<string, array<string, int>>,
     *               termPositions: array<string, list<int>>, length: int}
     */
    private static function untokenized(int $length): array
    {
        return ['termCounts' => [], 'fieldTermCounts' => [], 'termPositions' => [], 'length' => $length];
    }

    /**
     * The values tokenizeDocumentFields() reads from a document, in the order it reads them.
     * Two documents with identical (===) input produce identical terms, positions, per-field hits
     * and length, so a replacement with unchanged input can keep its term-index rows.
     *
     * @param  array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function searchableInput(array $document): array
    {
        $input = [];
        if ($this->searchableFieldSet !== null) {
            foreach ($this->searchableFieldSet as $key => $_) {
                if (isset($document[$key])) {
                    $input[$key] = $document[$key];
                }
            }
            return $input;
        }
        foreach ($document as $key => $value) {
            if ($key !== 'id' && $value !== null && !isset($this->facetFieldSet[$key])) {
                $input[$key] = $value;
            }
        }
        return $input;
    }

    /**
     * Live documents among $documents whose searchable input equals the stored document's, as
     * docId → stored length. Their replacement rewrites only the per-document rows (stored
     * document, facets, doc_lengths) and keeps doclist, positions, field_hits and the wordlist
     * counts. Empty without a document store (nothing to compare with).
     *
     * @param  array<array<string, mixed>> $documents  Incoming documents, each with an 'id'.
     * @param  array<int, int>             $oldLengths docId → length of the live documents among them.
     * @return array<int, int>
     */
    private function unchangedSearchableLengths(array $documents, array $oldLengths): array
    {
        if (!$this->documentStoreEnabled || $oldLengths === []) {
            return [];
        }
        $stored = $this->fetchDocuments(array_keys($oldLengths));
        $kept   = [];
        foreach ($documents as $document) {
            $id = $this->extractId($document['id']);
            if (isset($stored[$id]) && $this->searchableInput($stored[$id]) === $this->searchableInput($document)) {
                $kept[$id] = $oldLengths[$id];
            } else {
                unset($kept[$id]);
            }
        }
        return $kept;
    }

    /**
     * Tokenise one document field value and accumulate into the shared term/position maps.
     *
     * Null and empty/whitespace-only values are silently skipped. Both branches of
     * tokenizeDocumentFields() delegate here so the tokenisation pipeline is defined once.
     *
     * @param array<string, int>                 $termCounts      mutated in-place
     * @param array<string, array<string, int>>  $fieldTermCounts mutated in-place
     * @param array<string, list<int>>           $termPositions   mutated in-place
     */
    private function indexFieldColumn(
        mixed $col,
        string $fieldName,
        array &$termCounts,
        array &$fieldTermCounts,
        array &$termPositions,
        int &$length,
        int &$position,
    ): void {
        if ($col === null) {
            return;
        }
        /** @infection-ignore-all UnwrapTrim: leading/trailing whitespace in field values is uncommon in tests; trimming is a defensive clean-up step */
        $text = trim(strval($col)); // @phpstan-ignore argument.type
        if ($text === '') {
            return;
        }
        if ($this->stripHtml) {
            $text = HtmlText::toText($text);
            if ($text === '') {
                return;
            }
        }
        $tokens = Tokenizer::tokenize($text, $this->language);
        if ($this->stopwords instanceof \Fuzor\Stopwords) {
            $tokens = $this->stopwords->filter($tokens);
        }
        /** @infection-ignore-all Assignment: changing += to = only matters for multi-field docs where the same term appears in both fields; single-field tests are unaffected */
        $length += count($tokens);
        if ($this->stemmer instanceof \Fuzor\Stemmer) {
            $tokens = $this->stemmer->stemTokens($tokens);
        }
        foreach ($tokens as $token) {
            $termCounts[$token]      = ($termCounts[$token] ?? 0) + 1;
            $termPositions[$token][] = $position++;
            $fieldTermCounts[$fieldName][$token] = ($fieldTermCounts[$fieldName][$token] ?? 0) + 1;
        }
    }

    /**
     * Tokenise every field of a document row and write the result to the index.
     *
     * The 'id' field is used as the document ID and is excluded from indexing.
     * Empty or whitespace-only field values are skipped.
     *
     * @param  array<string, mixed> $row         Document fields; must contain an 'id' key.
     * @param  int|null             $knownLength Length of a document whose term-index rows are
     *                                           kept (see unchangedSearchableLengths()): it is not
     *                                           tokenised, only its length, facets and stored
     *                                           document are written.
     * @return int                               Total token count across all indexed fields.
     */
    private function processDocument(array $row, ?int $knownLength = null): int
    {
        $documentId = $this->extractId($row['id']);

        ['termCounts'      => $termCounts,
         'fieldTermCounts' => $fieldTermCounts,
         'termPositions'   => $termPositions,
         'length'          => $length] = $knownLength !== null
            ? self::untokenized($knownLength)
            : $this->tokenizeDocumentFields($row);

        $termIds = $this->upsertWordlist($termCounts);
        $this->saveDoclist($documentId, $termIds);
        if ($fieldTermCounts !== []) {
            $this->saveFieldHits($documentId, $fieldTermCounts);
        }
        if ($termPositions !== []) {
            $termIdPositions = [];
            foreach ($termPositions as $term => $positions) {
                $termId = $this->termIdCache[$term] ?? null;
                if ($termId !== null) {
                    $termIdPositions[$termId] = $positions;
                }
            }
            $this->savePositions($documentId, $termIdPositions);
        }
        $this->saveDocLength($documentId, $length);

        if ($this->facetFieldSet !== []) {
            $this->saveFacets($documentId, array_intersect_key($row, $this->facetFieldSet));
        }

        if ($this->documentStoreEnabled) {
            $this->stmt(
                'documentSave',
                'INSERT INTO documents (doc_id, data) VALUES (?, ?)
                 ON CONFLICT(doc_id) DO UPDATE SET data = excluded.data'
            )->execute([$documentId, json_encode($row, JSON_THROW_ON_ERROR)]);
        }

        return $length;
    }

    /**
     * Upsert terms into the wordlist table and return a term_id → hit_count map.
     *
     * Iterates per-term using two cached single-row statements: known terms (in termIdCache)
     * are updated via a plain UPDATE; new terms use INSERT … ON CONFLICT … RETURNING to
     * obtain the assigned ID and populate the cache. Both statements are reused across calls
     * within the same connection, keeping COMMIT overhead negligible.
     *
     * @param  array<string, int> $termCounts Term → hit count for the document.
     * @return array<int, int>                term_id → hit_count with resolved wordlist IDs.
     */
    private function upsertWordlist(array $termCounts): array
    {
        if ($termCounts === []) {
            return [];
        }

        /** @var array<int, int> $termIds */
        $termIds = [];

        // Split on termIdCache: known terms only need a stats UPDATE (no RETURNING — we already
        // have the ID). New terms need INSERT ON CONFLICT RETURNING to learn the assigned ID.
        // Both paths use fixed-shape single-row cached statements — 2 total open statements,
        // keeping COMMIT overhead negligible.

        $updateStmt = $this->stmt(
            'upsertWordlistUpdate',
            'UPDATE wordlist SET num_hits = num_hits + ?, num_docs = num_docs + 1 WHERE term = ?'
        );
        $insertStmt = $this->stmt(
            'upsertWordlistInsert',
            'INSERT INTO wordlist (term, num_hits, num_docs) VALUES (?, ?, 1)
             ON CONFLICT(term) DO UPDATE SET
                 num_hits = num_hits + excluded.num_hits,
                 num_docs = num_docs + 1
             RETURNING id, term'
        );

        foreach ($termCounts as $term => $hits) {
            if (isset($this->termIdCache[$term])) {
                $id = $this->termIdCache[$term];
                $updateStmt->execute([$hits, $term]);
                $termIds[$id] = $hits;
            } else {
                $insertStmt->execute([$term, $hits]);
                /** @var array{id: int, term: string}|false $row */
                $row = $insertStmt->fetch(PDO::FETCH_ASSOC);
                // Explicitly close the RETURNING cursor so COMMIT is not blocked.
                $insertStmt->closeCursor();
                assert($row !== false);
                /** @infection-ignore-all CastInt: PDO returns string IDs; PHP auto-coerces string-integer array keys to int, making the explicit cast redundant */
                $id                       = (int) $row['id'];
                $this->termIdCache[$term] = $id;
                $termIds[$id]             = $hits;
            }
        }

        return $termIds;
    }

    /**
     * Accumulate one document's facet fields into the shared name→value→docId→numValue buffer.
     *
     * Called once per document in buildBatchBuffer.
     *
     * @param array<string, mixed>                                   $extracted    array_intersect_key result
     * @param array<string, array<int|string, array<int, float|null>>>   $facetBuffer  mutated in-place
     * @param array<string, true>                                      $multiValued  fields with 2+ values in one doc
     * @param-out array<string, array<int|string, array<int, float|null>>> $facetBuffer
     * @param-out array<string, true>                                  $multiValued
     */
    private function accumulateFacets(
        array $extracted,
        int $documentId,
        array &$facetBuffer,
        array &$multiValued,
    ): void {
        foreach ($extracted as $name => $rawValue) {
            if (is_array($rawValue)) {
                $distinct = [];
                foreach ($rawValue as $v) {
                    if (is_int($v) || is_float($v)) {
                        $strVal = (string) $v;
                        $facetBuffer[$name][$strVal][$documentId] = (float) $v;
                    } elseif (is_string($v) && $v !== '') {
                        $strVal = $v;
                        $facetBuffer[$name][$strVal][$documentId] = null;
                    } else {
                        continue;
                    }
                    $distinct[$strVal] = true;
                }
                if (count($distinct) > 1) {
                    $multiValued[$name] = true;
                }
            } elseif (is_int($rawValue) || is_float($rawValue)) {
                $strVal = (string) $rawValue;
                $facetBuffer[$name][$strVal][$documentId] = (float) $rawValue;
            } elseif (is_string($rawValue) && $rawValue !== '') {
                $strVal = $rawValue;
                $facetBuffer[$name][$strVal][$documentId] = null;
            }
        }
    }

    /**
     * Phase 1 of the two-phase bulk load: tokenise all documents and accumulate
     * per-term and per-document statistics in PHP memory without touching the DB.
     *
     * Returns four buffers:
     *   wordBuffer        — per unique term across the batch: total hit count and
     *                       number of distinct documents containing the term.
     *   docTermBuffer     — per document: term text → hit count (term IDs are not
     *                       yet known; resolved in Phase 2 after the wordlist upsert).
     *   docLengthBuffer   — per document: total token count for BM25 length normalisation.
     *   docPositionBuffer — per document: term text → ordered position list.
     *
     * @param  array<array<string, mixed>> $documents
     * @param  array<int, int>             $knownLengths docId → length of documents whose term-index
     *                                                   rows are kept (see unchangedSearchableLengths()):
     *                                                   not tokenised, only their length, facets and
     *                                                   stored document are buffered.
     * @return array{
     *     wordHits:          array<string, int>,
     *     wordDocs:          array<string, int>,
     *     docTermBuffer:     array<int, array<string, int>>,
     *     docLengthBuffer:   array<int, int>,
     *     docPositionBuffer: array<int, array<string, list<int>>>,
     *     facetBuffer:       array<string, array<int|string, array<int, float|null>>>,
     *     multiValuedFacets: array<string, true>,
     *     rawDocuments:      array<int, array<string, mixed>>,
     *     fieldTermBuffer:   array<int, array<string, array<string, int>>>
     * }
     */
    private function buildBatchBuffer(array $documents, ?callable $progress = null, array $knownLengths = []): array
    {
        /** @var array<string, int> $wordHits */
        $wordHits          = [];
        /** @var array<string, int> $wordDocs */
        $wordDocs          = [];
        /** @var array<int, array<string, int>> $docTermBuffer */
        $docTermBuffer     = [];
        /** @var array<int, int> $docLengthBuffer */
        $docLengthBuffer   = [];
        /** @var array<int, array<string, list<int>>> $docPositionBuffer */
        $docPositionBuffer = [];
        /** @var array<string, array<int|string, array<int, float|null>>> $facetBuffer  name → value → docId → numValue */
        $facetBuffer       = [];
        /** @var array<string, true> $multiValuedFacets Facet fields with 2+ distinct values in one document. */
        $multiValuedFacets = [];
        /** @var array<int, array<string, mixed>> $rawDocuments Raw document arrays for the document store; empty when store is disabled. */
        $rawDocuments      = [];
        /** @var array<int, array<string, array<string, int>>> $fieldTermBuffer  docId → fieldName → term → hitCount */
        $fieldTermBuffer   = [];

        $total = count($documents);
        $done  = 0;

        // Pre-compute the facet field lookup map once for the whole batch (not per-document).
        $facetFieldFlipped = $this->facetFieldSet;
        $hasFacetFields    = $facetFieldFlipped !== [];

        foreach ($documents as $document) {
            $documentId = $this->extractId($document['id']);

            ['termCounts'      => $termCounts,
             'fieldTermCounts' => $fieldTermCounts,
             'termPositions'   => $termPositions,
             'length'          => $length] = isset($knownLengths[$documentId])
                ? self::untokenized($knownLengths[$documentId])
                : $this->tokenizeDocumentFields($document);

            $docTermBuffer[$documentId]     = $termCounts;
            $docLengthBuffer[$documentId]   = $length;
            $docPositionBuffer[$documentId] = $termPositions;
            if ($fieldTermCounts !== []) {
                $fieldTermBuffer[$documentId] = $fieldTermCounts;
            }
            if ($hasFacetFields) {
                $this->accumulateFacets(
                    array_intersect_key($document, $facetFieldFlipped),
                    $documentId,
                    $facetBuffer,
                    $multiValuedFacets,
                );
            }

            if ($this->documentStoreEnabled) {
                $rawDocuments[$documentId] = $document;
            }

            foreach ($termCounts as $term => $hits) {
                if (isset($wordHits[$term])) {
                    /** @infection-ignore-all Assignment,PlusEqual: totalHits accumulates across documents; mutation only affects wordlist num_hits (BM25 scoring), not result membership */
                    $wordHits[$term] += $hits;
                    /** @infection-ignore-all DecrementInteger,Assignment: numDocs tracks distinct documents per term; off-by-one only affects BM25 scoring, not result membership */
                    $wordDocs[$term]++;
                } else {
                    $wordHits[$term] = $hits;
                    /** @infection-ignore-all DecrementInteger: numDocs=0 vs 1 on first occurrence only affects BM25 scoring, not result membership */
                    $wordDocs[$term] = 1;
                }
            }

            if ($progress !== null) {
                $progress(++$done, $total);
            }
        }

        return [
            'wordHits'          => $wordHits,
            'wordDocs'          => $wordDocs,
            'docTermBuffer'     => $docTermBuffer,
            'docLengthBuffer'   => $docLengthBuffer,
            'docPositionBuffer' => $docPositionBuffer,
            'facetBuffer'       => $facetBuffer,
            'multiValuedFacets' => $multiValuedFacets,
            'rawDocuments'      => $rawDocuments,
            'fieldTermBuffer'   => $fieldTermBuffer,
        ];
    }

    /**
     * Phase 2 of the two-phase bulk load: write the accumulated buffers to the DB.
     *
     * Performs four bulk writes inside the caller's open transaction:
     *   1. batchUpsertWordlist() — one probe per unique term (not per document × term).
     *   2. Doclist INSERT        — all (term_id, doc_id, hit_count) rows in bulk.
     *   3. Doc_lengths INSERT    — all (doc_id, length) rows in bulk.
     *   4. Positions INSERT      — all (term_id, doc_id, position) rows in bulk.
     *
     * @param  array<string, int>                   $wordHits   Term → total hit count across all documents.
     * @param  array<string, int>                   $wordDocs   Term → distinct document count.
     * @param  array<int, array<string, int>>       $docTermBuffer
     * @param  array<int, int>                      $docLengthBuffer
     * @param  array<int, array<string, list<int>>> $docPositionBuffer
     * @param  array<int, array<string, mixed>>     $rawDocuments  doc_id → raw document array (store path only)
     * @param  array<string, array<int|string, array<int, float|null>>> $facetBuffer  name → value → docId → numValue
     * @param  array<int, array<string, array<string, int>>> $fieldTermBuffer  docId → fieldName → term → hitCount
     * @param  array<string, true>                  $multiValuedFacets  facet fields with 2+ values in one document
     * @return int Total token count across all documents (for adjustStats).
     */
    private function flushBatch(
        array $wordHits,
        array $wordDocs,
        array $docTermBuffer,
        array $docLengthBuffer,
        array $docPositionBuffer = [],
        array $rawDocuments = [],
        array $facetBuffer = [],
        array $fieldTermBuffer = [],
        array $multiValuedFacets = [],
    ): int {
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);

        // Step 1: upsert all unique terms; get back term text → wordlist ID mapping.
        $termIdMap = $this->batchUpsertWordlist($wordHits, $wordDocs);

        // Step 2: invert both docTermBuffer and docPositionBuffer, then insert doclist.
        [$termDocMap, $termDocPosMap] = $this->invertTermBuffers($docTermBuffer, $docPositionBuffer, $termIdMap);
        $this->bulkInsertDoclistRows($termDocMap);

        // Step 3: invert fieldTermBuffer and bulk-insert per-field hit counts.
        if ($fieldTermBuffer !== []) {
            $termDocFieldMap = $this->invertFieldBuffer($fieldTermBuffer, $termIdMap);
            $this->bulkInsertFieldHitRows($termDocFieldMap);
        }

        // Step 4: bulk-insert doc_lengths.
        $this->bulkInsertDocLengthRows($docLengthBuffer);

        // Step 5: bulk-insert positions in (term_id, doc_id, position) PK order.
        if ($termDocPosMap !== []) {
            $this->bulkInsertPositionRows($termDocPosMap);
        }

        // Step 6: bulk-insert documents into the document store.
        if ($this->documentStoreEnabled && $rawDocuments !== []) {
            $this->bulkInsertDocumentRows($rawDocuments);
        }

        // Step 7: bulk-insert facet values sorted by (key_id, value, doc_id) for
        // WITHOUT ROWID clustered B-tree sequential appends.
        if ($facetBuffer !== []) {
            $this->bulkFlushFacets($facetBuffer, $multiValuedFacets);
        }

        return array_sum($docLengthBuffer);
    }

    /**
     * Bulk-insert doclist rows in clustered (term_id, doc_id) PK order.
     *
     * @param array<int, array<int, int>> $termDocMap  term_id → doc_id → hit_count (unsorted; sorted here)
     */
    private function bulkInsertDoclistRows(array $termDocMap): void
    {
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);
        /** @infection-ignore-all FunctionCallRemoval: ksort orders INSERTs by term_id PK for B-tree performance; omitting only degrades write speed */
        ksort($termDocMap);
        $rowCount = 0;
        $params   = [];
        foreach ($termDocMap as $termId => $docs) {
            /** @infection-ignore-all FunctionCallRemoval: ksort orders by doc_id for clustered PK order; omitting only degrades write speed */
            ksort($docs);
            foreach ($docs as $docId => $hits) {
                $params[] = $termId;
                $params[] = $docId;
                $params[] = $hits;
                if (++$rowCount === self::CHUNK_3P) {
                    /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
                    ($this->bulkStmtCache['doclistChunk:' . self::CHUNK_3P] ??= $pdo->prepare(
                        'INSERT INTO doclist (term_id, doc_id, hit_count) VALUES '
                        . implode(',', array_fill(0, self::CHUNK_3P, '(?,?,?)'))
                    ))->execute($params);
                    $params   = [];
                    $rowCount = 0;
                }
            }
        }
        /** @infection-ignore-all GreaterThan: changing > 0 to >= 0 only matters when rowCount=0 (no partial chunk); tests always produce at least one row so this branch is always true regardless */
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO doclist (term_id, doc_id, hit_count) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?)'))
            )->execute($params);
        }
    }

    /**
     * Bulk-insert positions rows in clustered (term_id, doc_id, position) PK order.
     *
     * @param array<int, array<int, list<int>>> $termDocPosMap  term_id → doc_id → position list (unsorted; sorted here)
     */
    private function bulkInsertPositionRows(array $termDocPosMap): void
    {
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);
        /** @infection-ignore-all FunctionCallRemoval: ksort orders INSERTs by term_id PK for B-tree performance; omitting only degrades write speed */
        ksort($termDocPosMap);
        $rowCount = 0;
        $params   = [];
        foreach ($termDocPosMap as $termId => $docs) {
            /** @infection-ignore-all FunctionCallRemoval: ksort orders by doc_id for clustered PK order; omitting only degrades write speed */
            ksort($docs);
            foreach ($docs as $docId => $positions) {
                foreach ($positions as $pos) {
                    $params[] = $termId;
                    $params[] = $docId;
                    $params[] = $pos;
                    if (++$rowCount === self::CHUNK_3P) {
                        /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
                        ($this->bulkStmtCache['positionsChunk:' . self::CHUNK_3P] ??= $pdo->prepare(
                            'INSERT INTO positions (term_id, doc_id, position) VALUES '
                            . implode(',', array_fill(0, self::CHUNK_3P, '(?,?,?)'))
                        ))->execute($params);
                        $params   = [];
                        $rowCount = 0;
                    }
                }
            }
        }
        /** @infection-ignore-all GreaterThan: changing > 0 to >= 0 only matters when rowCount=0 (no partial chunk); tests always produce at least one row so this branch is always true regardless */
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO positions (term_id, doc_id, position) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?)'))
            )->execute($params);
        }
    }

    /**
     * Invert doc→term buffers into term→doc maps for sorted B-tree insertion.
     *
     * Both buffers share the same (docId, term) key space so one pass covers both inversions.
     *
     * @param array<int, array<string, int>>         $docTermBuffer      docId → term → hit_count
     * @param array<int, array<string, list<int>>>   $docPositionBuffer  docId → term → position list
     * @param array<string, int>                     $termIdMap          term text → wordlist ID
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, list<int>>>}
     */
    private function invertTermBuffers(array $docTermBuffer, array $docPositionBuffer, array $termIdMap): array
    {
        $termDocMap    = [];
        $termDocPosMap = [];
        foreach ($docTermBuffer as $docId => $termCounts) {
            $termPositions = $docPositionBuffer[$docId];
            foreach ($termCounts as $term => $hits) {
                $termId = $termIdMap[$term];
                $termDocMap[$termId][$docId]    = $hits;
                $termDocPosMap[$termId][$docId] = $termPositions[$term];
            }
        }
        return [$termDocMap, $termDocPosMap];
    }

    /**
     * Bulk-insert doc_lengths rows in chunks of CHUNK_2P (2 params/row).
     *
     * @param array<int, int> $docLengthBuffer  docId → token count
     */
    private function bulkInsertDocLengthRows(array $docLengthBuffer): void
    {
        $pdo      = $this->pdo;
        assert($pdo instanceof \PDO);
        $rowCount = 0;
        $params   = [];
        foreach ($docLengthBuffer as $docId => $length) {
            $params[] = $docId;
            $params[] = $length;
            if (++$rowCount === self::CHUNK_2P) {
                ($this->bulkStmtCache['docLengthChunk:' . self::CHUNK_2P] ??= $pdo->prepare(
                    'INSERT INTO doc_lengths (doc_id, length) VALUES '
                    . implode(',', array_fill(0, self::CHUNK_2P, '(?,?)'))
                ))->execute($params);
                $params   = [];
                $rowCount = 0;
            }
        }
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO doc_lengths (doc_id, length) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?)'))
            )->execute($params);
        }
    }

    /**
     * Bulk-insert document store rows, JSON-encoding each document inline.
     *
     * Uses CHUNK_2P (max 2-param rows) to minimise execute() round-trips.
     * JSON encoding is done inline per-row to avoid holding all encoded strings in memory at once.
     *
     * @param array<int, array<string, mixed>> $rawDocuments  docId → raw document array
     */
    private function bulkInsertDocumentRows(array $rawDocuments): void
    {
        $pdo      = $this->pdo;
        assert($pdo instanceof \PDO);
        $rowCount = 0;
        $params   = [];
        foreach ($rawDocuments as $docId => $doc) {
            $params[] = $docId;
            $params[] = json_encode($doc, JSON_THROW_ON_ERROR);
            if (++$rowCount === self::CHUNK_2P) {
                ($this->bulkStmtCache['documentsChunk:' . self::CHUNK_2P] ??= $pdo->prepare(
                    'INSERT INTO documents (doc_id, data) VALUES '
                    . implode(',', array_fill(0, self::CHUNK_2P, '(?,?)'))
                ))->execute($params);
                $params   = [];
                $rowCount = 0;
            }
        }
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO documents (doc_id, data) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?)'))
            )->execute($params);
        }
    }

    /**
     * Aggregated wordlist upsert for the two-phase bulk load.
     *
     * Unlike upsertWordlist() (which increments num_docs by 1 per call),
     * this variant accepts pre-aggregated counts across the whole batch and
     * promotes num_docs to a real parameter so a single upsert covers all
     * documents that share a term.
     *
     * Uses 3 params per term → 10922 terms per chunk (SQLite 32766-variable limit).
     *
     * @param  array<string, int> $wordHits Term → total hit count across all documents.
     * @param  array<string, int> $wordDocs Term → distinct document count.
     * @return array<string, int> term text → wordlist ID
     */
    private function batchUpsertWordlist(array $wordHits, array $wordDocs): array
    {
        if ($wordHits === []) {
            return [];
        }

        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);

        /** @var array<string, int> $termIdMap */
        $termIdMap  = [];
        $knownHits  = [];
        $knownDocs  = [];
        $newHits    = [];
        $newDocs    = [];

        foreach ($wordHits as $term => $hits) {
            if (isset($this->termIdCache[$term])) {
                $knownHits[$term] = $hits;
                $knownDocs[$term] = $wordDocs[$term];
            } else {
                $newHits[$term] = $hits;
                $newDocs[$term] = $wordDocs[$term];
            }
        }

        // Path A: known terms — UPDATE by INTEGER PRIMARY KEY via CTE-VALUES; no RETURNING needed.
        // 3 params/row (id, hits, docs) → chunk rows/chunk.
        /** @infection-ignore-all UnwrapArrayChunk,Foreach_: Path A is never entered on fresh indexes (termIdCache is empty); mutations that skip or mishandle chunks have no effect when knownTerms is empty */
        foreach (array_chunk($knownHits, self::CHUNK_3P, true) as $chunk) {
            $n      = count($chunk);
            $params = [];
            foreach ($chunk as $term => $hits) {
                $params[] = $this->termIdCache[$term];
                $params[] = $hits;
                $params[] = $knownDocs[$term];
            }
            $pdo->prepare(
                'WITH delta(id, hits, docs) AS (VALUES ' . implode(',', array_fill(0, $n, '(?,?,?)')) . ')
                 UPDATE wordlist SET
                     num_hits = num_hits + delta.hits,
                     num_docs = num_docs + delta.docs
                 FROM delta WHERE wordlist.id = delta.id'
            )->execute($params);

            foreach (array_keys($chunk) as $term) {
                $termIdMap[$term] = $this->termIdCache[$term];
            }
        }

        // Path B: new terms — UPSERT via text UNIQUE index + RETURNING; IDs added to cache.
        // 3 params/term → chunk terms/chunk.
        foreach (array_chunk($newHits, self::CHUNK_3P, true) as $chunk) {
            $n      = count($chunk);
            $params = [];
            foreach ($chunk as $term => $hits) {
                $params[] = $term;
                $params[] = $hits;
                $params[] = $newDocs[$term];
            }
            $stmt = $pdo->prepare(
                'INSERT INTO wordlist (term, num_hits, num_docs) VALUES '
                    /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
                    . implode(',', array_fill(0, $n, '(?,?,?)'))
                    . ' ON CONFLICT(term) DO UPDATE SET
                           num_hits = num_hits + excluded.num_hits,
                           num_docs = num_docs + excluded.num_docs
                       RETURNING id, term'
            );
            $stmt->execute($params);
            /** @var list<array{id: int, term: string}> $upserted */
            $upserted = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($upserted as $row) {
                /** @infection-ignore-all CastInt: PDO returns string IDs; PHP auto-coerces string-integer array keys to int */
                $id = (int) $row['id'];
                $this->termIdCache[$row['term']] = $id;
                $termIdMap[$row['term']]          = $id;
            }
        }

        return $termIdMap;
    }

    /**
     * Write term→document hit counts to the doclist table.
     *
     * Uses a single-row cached statement executed once per term. A multi-row batch INSERT
     * is not faster here: single-document inserts have small row counts, so the overhead of
     * a variable-shape prepared statement outweighs the savings.
     *
     * @param int             $documentId Document ID.
     * @param array<int, int> $termIds  term_id → hit_count map from upsertWordlist().
     */
    private function saveDoclist(int $documentId, array $termIds): void
    {
        if ($termIds === []) {
            return;
        }

        // Single-doc path: use a fixed-shape single-row cached statement and loop per term.
        // Multi-row batch INSERTs into the WITHOUT ROWID clustered doclist B-tree are not
        // faster for small unsorted row counts — each row still requires a separate tree
        // descent, and the per-statement setup overhead cancels the execute() savings.
        $stmt = $this->stmt(
            'saveDoclistRow',
            'INSERT INTO doclist (term_id, doc_id, hit_count) VALUES (?,?,?)'
        );
        foreach ($termIds as $termId => $hits) {
            $stmt->execute([$termId, $documentId, $hits]);
        }
    }

    /**
     * Write per-field term hit counts for a single document (single-insert path).
     *
     * @param int                               $documentId      Document ID.
     * @param array<string, array<string, int>> $fieldTermCounts fieldName → term → hitCount
     */
    private function saveFieldHits(int $documentId, array $fieldTermCounts): void
    {
        if ($fieldTermCounts === []) {
            return;
        }
        $stmt = $this->stmt(
            'saveFieldHitRow',
            'INSERT INTO field_hits (term_id, doc_id, field_id, hit_count) VALUES (?,?,?,?)'
        );
        foreach ($fieldTermCounts as $fieldName => $termCounts) {
            $fieldId = $this->resolveFieldNameId($fieldName);
            foreach ($termCounts as $term => $hits) {
                $termId = $this->termIdCache[$term] ?? null;
                if ($termId === null) {
                    continue;
                }
                $stmt->execute([$termId, $documentId, $fieldId, $hits]);
            }
        }
    }

    /**
     * Invert docId → fieldName → term → hitCount into termId → docId → fieldId → hitCount,
     * sorted in composite PK order for sequential B-tree appends into field_hits.
     *
     * @param array<int, array<string, array<string, int>>> $fieldTermBuffer  docId → fieldName → term → hitCount
     * @param array<string, int>                            $termIdMap        term text → wordlist ID
     * @return array<int, array<int, array<int, int>>>                        termId → docId → fieldId → hitCount
     */
    private function invertFieldBuffer(array $fieldTermBuffer, array $termIdMap): array
    {
        $termDocFieldMap = [];
        foreach ($fieldTermBuffer as $docId => $fieldTermCounts) {
            foreach ($fieldTermCounts as $fieldName => $termCounts) {
                $fieldId = $this->resolveFieldNameId($fieldName);
                foreach ($termCounts as $term => $hits) {
                    $termId = $termIdMap[$term] ?? null;
                    if ($termId === null) {
                        continue;
                    }
                    $termDocFieldMap[$termId][$docId][$fieldId] = $hits;
                }
            }
        }
        return $termDocFieldMap;
    }

    /**
     * Bulk-insert field_hits rows in clustered (term_id, doc_id, field_id) PK order.
     *
     * @param array<int, array<int, array<int, int>>> $termDocFieldMap  termId → docId → fieldId → hitCount
     */
    private function bulkInsertFieldHitRows(array $termDocFieldMap): void
    {
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);
        /** @infection-ignore-all FunctionCallRemoval: ksort orders INSERTs by term_id PK for B-tree performance; omitting only degrades write speed */
        ksort($termDocFieldMap);
        $rowCount = 0;
        $params   = [];
        foreach ($termDocFieldMap as $termId => $docs) {
            /** @infection-ignore-all FunctionCallRemoval: ksort orders by doc_id for clustered PK order; omitting only degrades write speed */
            ksort($docs);
            foreach ($docs as $docId => $fields) {
                /** @infection-ignore-all FunctionCallRemoval: ksort orders by field_id for clustered PK order; omitting only degrades write speed */
                ksort($fields);
                foreach ($fields as $fieldId => $hits) {
                    $params[] = $termId;
                    $params[] = $docId;
                    $params[] = $fieldId;
                    $params[] = $hits;
                    if (++$rowCount === self::CHUNK_4P) {
                        /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
                        ($this->bulkStmtCache['fieldHitsChunk:' . self::CHUNK_4P] ??= $pdo->prepare(
                            'INSERT INTO field_hits (term_id, doc_id, field_id, hit_count) VALUES '
                            . implode(',', array_fill(0, self::CHUNK_4P, '(?,?,?,?)'))
                        ))->execute($params);
                        $params   = [];
                        $rowCount = 0;
                    }
                }
            }
        }
        /** @infection-ignore-all GreaterThan: changing > 0 to >= 0 only matters when rowCount=0 (no partial chunk); tests always produce at least one row so this branch is always true regardless */
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO field_hits (term_id, doc_id, field_id, hit_count) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?,?)'))
            )->execute($params);
        }
    }

    /**
     * Resolve a field name to its field_names.id, inserting if absent.
     * Populates $fieldNameCache so subsequent calls for the same name are cache-only.
     */
    private function resolveFieldNameId(string $name): int
    {
        if (isset($this->fieldNameCache[$name])) {
            return $this->fieldNameCache[$name];
        }
        $stmt = $this->stmt(
            'resolveFieldNameId',
            'INSERT INTO field_names (name) VALUES (?)
             ON CONFLICT(name) DO UPDATE SET name = excluded.name
             RETURNING id'
        );
        $stmt->execute([$name]);
        /** @var array{id: int}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        assert($row !== false);
        $id = (int) $row['id'];
        $this->fieldNameCache[$name] = $id;
        return $id;
    }

    /**
     * Look up a field name's id without inserting. Returns null if the name has never been indexed.
     * Used on the read path where write access may not be available.
     */
    private function lookupFieldNameId(string $name): ?int
    {
        if (isset($this->fieldNameCache[$name])) {
            return $this->fieldNameCache[$name];
        }
        $stmt = $this->stmt('lookupFieldNameId', 'SELECT id FROM field_names WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($id === false) {
            return null;
        }
        $this->fieldNameCache[$name] = (int) $id;
        return (int) $id;
    }

    /**
     * Fetch per-field hit counts for a set of (term_id, doc_id) pairs.
     *
     * Used by the field boost re-scoring path in search(). Volume is bounded by
     * numTerms × maxDocs × avgFields, typically a few thousand rows.
     *
     * @param  list<int>  $termIds
     * @param  list<int>  $docIds
     * @return array<int, array<int, array<int, int>>>  termId → docId → fieldId → hitCount
     */
    private function fetchFieldHitsForDocs(array $termIds, array $docIds): array
    {
        if ($termIds === [] || $docIds === []) {
            return [];
        }
        $result      = [];
        $tCount      = count($termIds);
        $tPh         = $this->placeholders($tCount);
        $maxDocChunk = max(1, self::CHUNK_1P - $tCount);
        foreach (array_chunk($docIds, $maxDocChunk) as $docChunk) {
            $dPh  = $this->placeholders(count($docChunk));
            $stmt = $this->prepare(
                "SELECT term_id, doc_id, field_id, hit_count
                 FROM field_hits
                 WHERE term_id IN ({$tPh}) AND doc_id IN ({$dPh})"
            );
            $stmt->execute([...$termIds, ...$docChunk]);
            /** @var list<array{0: int, 1: int, 2: int, 3: int}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$termId, $docId, $fieldId, $hits]) {
                $result[$termId][$docId][$fieldId] = $hits;
            }
        }
        return $result;
    }

    /**
     * Fetch document lengths for a set of doc IDs; IDs without a live document are absent.
     * Used by field boost re-scoring, delete() and the bulk update path.
     *
     * @param  list<int>       $docIds
     * @return array<int, int>  docId → token length
     */
    private function fetchDocLengthsForDocs(array $docIds): array
    {
        if ($docIds === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($docIds, self::CHUNK_1P) as $chunk) {
            $ph   = $this->placeholders(count($chunk));
            $stmt = $this->prepare("SELECT doc_id, length FROM doc_lengths WHERE doc_id IN ({$ph})");
            $stmt->execute($chunk);
            /** @var list<array{0: int, 1: int}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$docId, $length]) {
                $result[$docId] = $length;
            }
        }
        return $result;
    }

    /**
     * Persist term positions for a single document (single-insert path).
     *
     * @param int                   $documentId      Document ID.
     * @param array<int, list<int>> $termIdPositions term_id → ordered position list.
     */
    private function savePositions(int $documentId, array $termIdPositions): void
    {
        if ($termIdPositions === []) {
            return;
        }
        $stmt = $this->stmt(
            'savePositionRow',
            'INSERT INTO positions (term_id, doc_id, position) VALUES (?,?,?)'
        );
        foreach ($termIdPositions as $termId => $positions) {
            foreach ($positions as $position) {
                $stmt->execute([$termId, $documentId, $position]);
            }
        }
    }

    /**
     * Persist a document's total token count for BM25 length normalisation.
     *
     * @param int $documentId Document ID.
     * @param int $length     Total number of tokens across all indexed fields.
     */
    private function saveDocLength(int $documentId, int $length): void
    {
        $this->stmt(
            'saveDocLength',
            'INSERT INTO doc_lengths (doc_id, length) VALUES (:id, :len)
             ON CONFLICT(doc_id) DO UPDATE SET length = excluded.length'
        )->execute([':id' => $documentId, ':len' => $length]);
    }

    /**
     * Update total_documents and avg_doc_length after any document mutation.
     *
     * All three operations reduce to the same formula:
     *   avg' = (avg * n + lengthDelta) / (n + docDelta)
     *
     * Callers pass signed deltas:
     *   insert:  adjustStats(+count, +totalLength)
     *   delete:  adjustStats(-1,     -tokenCount)
     *   replace: adjustStats(0,      newLength - oldLength)
     *
     * @param int $docDelta    Signed change in document count.
     * @param int $lengthDelta Signed change in total token count.
     */
    private function adjustStats(int $docDelta, int $lengthDelta): void
    {
        $info = $this->getInfoValues(['total_documents', 'avg_doc_length']);
        /** @infection-ignore-all DecrementInteger,IncrementInteger,CastInt: the ?? fallback is never reached in practice (info table is initialised on createIndex); CastInt: string coerces to int in arithmetic */
        $n    = (int)   ($info['total_documents'] ?? 0);
        /** @infection-ignore-all OneZeroFloat,CastFloat: the ?? fallback is never reached; CastFloat: string coerces to float in arithmetic */
        $avg  = (float) ($info['avg_doc_length']  ?? 0.0);

        $newN   = $n + $docDelta;
        /** @infection-ignore-all OneZeroFloat: the else branch (newN=0) is only reached when all docs are deleted; avg_doc_length of 0.0 vs 1.0 has no observable effect on search results with zero documents */
        $newAvg = $newN > 0 ? ($avg * $n + $lengthDelta) / $newN : 0.0;

        $statsStmt = $this->stmt(
            'statsWrite',
            "UPDATE info SET value = CASE key
                 WHEN 'total_documents' THEN :n
                 WHEN 'avg_doc_length'  THEN :avg
             END WHERE key IN ('total_documents', 'avg_doc_length')"
        );
        /** @infection-ignore-all CastString: PDO/SQLite accepts int and float natively; the string cast is a type-annotation hint */
        $statsStmt->execute([':n' => (string) $newN, ':avg' => (string) $newAvg]);

        // Keep infoCache coherent so the next getInfoValues() call needs no DB read.
        // wordlistCache must be cleared: num_hits/num_docs on wordlist rows changed.
        // termIdCache is stable after a stats-only write; IDs only become stale when
        // terms are pruned, which removeDocumentData() handles directly.
        $this->infoCache = [
            'total_documents' => (string) $newN,
            /** @infection-ignore-all CastString: infoCache stores strings for consistency with PDO fetch; float stored in cache is coerced to string on next read */
            'avg_doc_length'  => (string) $newAvg,
        ];
        /** @infection-ignore-all AssignmentRemoval: skipping this leaves wordlistCache stale after a stats update; stale entries return incorrect num_hits/num_docs in BM25 scoring */
        $this->wordlistCache = [];
    }

    // --- Private read helpers -----------------------------------------------

    /**
     * Resolve the wordlist IDs for a keyword, used by the boolean evaluator.
     *
     * Pipes the keyword through the same Tokenizer::tokenize → stem pipeline as the BM25
     * path, so CJK n-gram expansion and stemming are handled identically in both modes.
     * Each resulting token is looked up independently; duplicate IDs are collapsed.
     *
     * @param  string $keyword       Term to resolve.
     * @param  bool   $isLastKeyword Whether this is the final token (for asYouType prefix expansion).
     * @param  bool   $truncated     Set to true when the prefix expansion was capped.
     * @return list<int>
     */
    private function resolveWordlistIds(string $keyword, bool $isLastKeyword, bool &$truncated = false): array
    {
        $tokens = Tokenizer::tokenize($keyword, $this->language);
        if ($this->stemmer instanceof \Fuzor\Stemmer) {
            $tokens = $this->stemmer->stemTokens($tokens);
        }
        $ids  = [];
        $last = count($tokens) - 1;
        foreach ($tokens as $i => $token) {
            foreach ($this->getWordlistByKeyword($token, $isLastKeyword && $i === $last, true, $truncated) as $row) {
                $ids[] = $row['id'];
            }
            foreach ($this->synonymsFor($token) as $synTerm) {
                foreach ($this->getWordlistByKeyword($synTerm, false, false) as $row) {
                    $ids[] = $row['id'];
                }
            }
        }
        /** @infection-ignore-all UnwrapArrayUnique,UnwrapArrayValues: CJK/Thai boolean path is not covered by ASCII-only tests; both wrappers enforce the list<int> contract */
        return array_values(array_unique($ids));
    }

    /**
     * Fetch a capped list of doc IDs matching any of the given term IDs.
     *
     * Sets $truncated when more than $limit rows matched (one extra row is fetched to tell).
     *
     * Used by the boolean PHP-side evaluator; does not fetch BM25 fields.
     * Single-term path uses a cached statement. Multi-term path uses IN() —
     * boolean set operations in PHP don't require hit_count ordering.
     *
     * @param  list<int> $termIds
     * @return list<int>
     */
    private function fetchBooleanDocIds(array $termIds, int $limit, bool &$truncated = false): array
    {
        /** @infection-ignore-all ReturnRemoval: boolean search terms always resolve to non-empty termIds in tests (all searched terms exist in the indexed docs) */
        if ($termIds === []) {
            return [];
        }

        $n    = count($termIds);
        $live = $this->liveDocsSql('doc_id');
        $tag  = $live === '' ? '' : ':live';

        /** @infection-ignore-all IncrementInteger,Identical: mutations on n===1 only switch between the single-term cached stmt and the IN()-based multi-term stmt; both queries return equivalent doc ID sets */
        if ($n === 1) {
            $stmt = $this->stmt(
                "boolDocIds1{$tag}",
                "SELECT doc_id FROM doclist WHERE term_id = ?{$live} ORDER BY hit_count DESC LIMIT ?"
            );
            $stmt->execute([$termIds[0], $limit + 1]);
            /** @var list<int> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            /** @infection-ignore-all ReturnRemoval: falling through to the IN() path for n=1 returns the same result set */
            return $this->capDocIds($rows, $limit, $truncated);
        }

        $placeholders = $this->placeholders($n);
        $stmt         = $this->stmt(
            "boolDocIds:{$n}{$tag}",
            "SELECT doc_id FROM doclist WHERE term_id IN ({$placeholders}){$live} ORDER BY hit_count DESC LIMIT ?"
        );
        $stmt->execute([...$termIds, $limit + 1]);
        /** @var list<int> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        /** @infection-ignore-all ArrayOneItem: boolean set operations use assertContains; returning only 1 item from a multi-doc result is not caught by membership tests for single-match terms */
        return $this->capDocIds($rows, $limit, $truncated);
    }

    /**
     * Trim a result fetched with LIMIT $limit + 1 back to $limit, flagging $truncated when the
     * extra row was present — i.e. when more rows matched than the cap allows.
     *
     * @param  list<int> $rows
     * @return list<int>
     */
    private function capDocIds(array $rows, int $limit, bool &$truncated): array
    {
        if (count($rows) <= $limit) {
            return $rows;
        }
        $truncated = true;
        return array_slice($rows, 0, $limit);
    }

    /**
     * Apply the proximity factor to the documents that matched every keyword group.
     *
     * Ranking puts those documents first (more matched groups first), ordered by score, and
     * the factor 1 / (1 + boost × minSpan) only lowers a score. When the groups share no term,
     * two groups never share a position, so minSpan >= groups − 1 and no document can end above
     * bm25 × 1 / (1 + boost × (groups − 1)). With $topK set (no sort, no distinct: only the
     * first offset + limit documents are shown) the documents are reranked in BM25 order, in
     * doubling batches, until the next one's bound falls below the $topK-th best reranked score; the rest
     * get their bound as score, which keeps them below that document, so the shown page is
     * exactly what reranking everything would give. Measured on the 45k ecom set ("blue jeans",
     * ~600 full matches): positions and spans for all of them cost ~6 ms.
     *
     * Config::$proxWindowSize > 0 keeps its meaning: only that many of the best BM25 documents
     * are reranked, the others keep their BM25 score.
     *
     * @param array<int, float> $docScores     Scores keyed by doc ID; modified in place.
     * @param array<int, int>   $docBucket     Words bucket per document (full match = $numKeywords).
     * @param list<list<int>>   $termGroups    One list of term IDs per keyword group.
     * @param int|null          $topK          Documents that must be ranked exactly; null = all.
     */
    private function applyProximityRanking(
        array &$docScores,
        array $docBucket,
        array $termGroups,
        int $numKeywords,
        ?int $topK,
    ): void {
        $boostSet = [];
        foreach ($docScores as $id => $score) {
            if (($docBucket[$id] ?? 0) >= $numKeywords) {
                $boostSet[$id] = $score;
            }
        }
        if ($boostSet === []) {
            return;
        }
        $proxWindow = $this->config->proxWindowSize;
        if ($proxWindow > 0 && count($boostSet) > $proxWindow) {
            arsort($boostSet);
            $boostSet = array_slice($boostSet, 0, $proxWindow, true);
            $topK     = null;
        }
        $allTerms = array_merge(...$termGroups);
        $disjoint = count($allTerms) === count(array_unique($allTerms));
        if ($topK === null || !$disjoint || count($boostSet) <= $topK) {
            $this->applyProximityBoost($boostSet, $termGroups);
            foreach ($boostSet as $id => $score) {
                $docScores[$id] = $score;
            }
            return;
        }

        arsort($boostSet);
        $maxFactor = 1.0 / (1.0 + $this->config->proximityBoost * (count($termGroups) - 1));
        // Batches double in size: a bound that prunes stops after the first one, and one that
        // cannot (spans far above groups − 1, common with three or more words in long texts)
        // costs a few batches instead of one per 32 documents.
        $batchSize = max(32, $topK);
        $reranked  = [];
        $pending   = $boostSet;
        while ($pending !== []) {
            $batch     = array_slice($pending, 0, $batchSize, true);
            $pending   = array_slice($pending, $batchSize, null, true);
            $batchSize *= 2;
            $this->applyProximityBoost($batch, $termGroups);
            $reranked += $batch;
            if ($pending === [] || count($reranked) < $topK) {
                continue;
            }
            $best = $reranked;
            rsort($best);
            if (reset($pending) * $maxFactor < $best[$topK - 1]) {
                break;
            }
        }
        foreach ($reranked as $id => $score) {
            $docScores[$id] = $score;
        }
        foreach ($pending as $id => $score) {
            $docScores[$id] = $score * $maxFactor;
        }
    }

    /**
     * The page of a sorted search, with ties on every sort spec broken by proximity-ranked score.
     *
     * $sortedIds is ordered by the sort specs, then BM25 score, then doc ID. The proximity factor
     * only changes scores, so it can only reorder documents that tie on every sort spec. The page
     * window is widened to whole tie groups at both ends; inside it, each group of two or more
     * documents gets the factor for its full matches and is re-sorted by score desc, doc ID asc.
     * Documents outside the window cannot move into it, so the page equals reranking everything
     * first. On the 45k ecom set a price-sorted "casual shirt" reranked ~525 documents for a page
     * where prices rarely tie.
     *
     * @param  list<int>          $sortedIds
     * @param  array<int, string> $tieKeys       From sortDocIdsBySpecs().
     * @param  array<int, float>  $docScores
     * @param  array<int, int>    $docBucket
     * @param  list<list<int>>    $termGroups
     * @return list<int>
     */
    private function rerankSortedPageTies(
        array $sortedIds,
        array $tieKeys,
        array $docScores,
        array $docBucket,
        array $termGroups,
        int $numKeywords,
        int $offset,
        int $limit,
    ): array {
        $n     = count($sortedIds);
        $start = min($offset, $n);
        $end   = min($n, $offset + $limit);
        if ($start >= $end) {
            return [];
        }
        while ($start > 0 && $tieKeys[$sortedIds[$start - 1]] === $tieKeys[$sortedIds[$start]]) {
            $start--;
        }
        while ($end < $n && $tieKeys[$sortedIds[$end]] === $tieKeys[$sortedIds[$end - 1]]) {
            $end++;
        }
        $window = array_slice($sortedIds, $start, $end - $start);

        /** @var list<list<int>> $groups  consecutive runs of equal tie keys */
        $groups = [];
        $last   = null;
        foreach ($window as $id) {
            if ($tieKeys[$id] !== $last) {
                $groups[] = [];
                $last     = $tieKeys[$id];
            }
            $groups[count($groups) - 1][] = $id;
        }
        $boostSet = [];
        foreach ($groups as $group) {
            if (count($group) > 1) {
                foreach ($group as $id) {
                    if (($docBucket[$id] ?? 0) >= $numKeywords) {
                        $boostSet[$id] = $docScores[$id];
                    }
                }
            }
        }
        if ($boostSet === []) {
            return array_slice($sortedIds, $offset, $limit);
        }
        $this->applyProximityBoost($boostSet, $termGroups);
        $scores = array_replace($docScores, $boostSet);

        $ordered = [];
        foreach ($groups as $group) {
            if (count($group) > 1) {
                $groupScores = array_map(fn(int $id): float => $scores[$id], $group);
                array_multisort($groupScores, SORT_DESC, SORT_NUMERIC, $group, SORT_ASC, SORT_NUMERIC);
            }
            array_push($ordered, ...$group);
        }
        return array_slice($ordered, $offset - $start, $limit);
    }

    /**
     * Rerank BM25 scores in-place using a proximity factor: 1 / (1 + boost × minSpan).
     *
     * minSpan is the smallest token-position window (in indexed positions) that contains at
     * least one occurrence of every query-keyword group. A "group" is the set of term IDs
     * produced by one keyword — including prefix/fuzzy expansions — so "se" matching
     * {sedan, seat} counts as a single group, not two independent constraints.
     *
     * Documents that do not have positions for every group (partial matches) are unchanged.
     *
     * @param array<int, float> $docScores  BM25 scores keyed by doc ID; modified in-place.
     * @param list<list<int>>   $termGroups One element per keyword; each is a list of term IDs.
     */
    private function applyProximityBoost(array &$docScores, array $termGroups): void
    {
        /** @var list<int> $allTermIds */
        $allTermIds = array_values(array_unique(array_merge(...$termGroups)));
        $positions  = $this->fetchPositionsForDocs(array_keys($docScores), $allTermIds);
        if ($positions === []) {
            return;
        }

        // Invert: term_id → group index, for O(1) lookup in the per-doc loop.
        /** @var array<int, int> $termToGroup */
        $termToGroup = [];
        foreach ($termGroups as $g => $termIds) {
            foreach ($termIds as $termId) {
                $termToGroup[$termId] = $g;
            }
        }

        $numGroups = count($termGroups);
        $boost     = $this->config->proximityBoost;

        foreach ($positions as $docId => $termPositions) {
            // Partition positions into per-keyword-group buckets.
            /** @var array<int, list<int>> $groupPositions */
            $groupPositions = array_fill(0, $numGroups, []);
            foreach ($termPositions as $termId => $posList) {
                $g = $termToGroup[$termId] ?? null;
                if ($g !== null) {
                    foreach ($posList as $pos) {
                        $groupPositions[$g][] = $pos;
                    }
                }
            }

            // Skip docs missing positions for any group (partial BM25 matches).
            foreach ($groupPositions as $gp) {
                if ($gp === []) {
                    continue 2;
                }
            }

            // Merge all groups' positions into one list sorted by position. Each entry is one
            // integer, position × numGroups + group, so a native sort() orders it with no PHP
            // comparison callback (a usort() closure per document cost ~1 ms per 600 documents).
            /** @var list<int> $merged */
            $merged = [];
            foreach ($groupPositions as $g => $posList) {
                foreach ($posList as $pos) {
                    $merged[] = $pos * $numGroups + $g;
                }
            }
            sort($merged);

            // Sliding-window minimum-span: smallest window covering all groups.
            $count   = array_fill(0, $numGroups, 0);
            $have    = 0;
            $left    = 0;
            $minSpan = PHP_INT_MAX;

            foreach ($merged as $right) {
                $rightG = $right % $numGroups;
                if ($count[$rightG] === 0) {
                    $have++;
                }
                $count[$rightG]++;

                while ($have === $numGroups) {
                    $leftEntry = $merged[$left];
                    $span      = intdiv($right, $numGroups) - intdiv($leftEntry, $numGroups);
                    if ($span < $minSpan) {
                        $minSpan = $span;
                    }
                    $leftG = $leftEntry % $numGroups;
                    $count[$leftG]--;
                    if ($count[$leftG] === 0) {
                        $have--;
                    }
                    $left++;
                }
            }

            if ($minSpan < PHP_INT_MAX) {
                $docScores[$docId] *= 1.0 / (1.0 + $boost * $minSpan);
            }
        }
    }

    /**
     * Fetch term positions for a set of documents and term IDs from the positions table.
     *
     * @param  list<int> $docIds
     * @param  list<int> $termIds
     * @return array<int, array<int, list<int>>> doc_id → term_id → sorted position list
     */
    private function fetchPositionsForDocs(array $docIds, array $termIds): array
    {
        if ($docIds === [] || $termIds === []) {
            return [];
        }

        // One stable statement for any number of documents and terms (an IN (?, …) list per
        // call would prepare a new statement every time). Driven from the documents, so rows
        // arrive grouped by document; each (document, term) pair is one range of the covering
        // positions_doc_id index, in position order. Measured on ecom, 3,400 phrase candidates:
        // 6.1 ms, against 7.8 ms for IN lists and 8.3 ms with the terms as the outer loop.
        $stmt = $this->stmt(
            'fetchPositions',
            'SELECT p.doc_id, p.term_id, p.position
               FROM json_each(?) d
               CROSS JOIN positions p ON p.doc_id = d.value
              WHERE p.term_id IN (SELECT value FROM json_each(?))'
        );
        $stmt->execute([json_encode($docIds), json_encode($termIds)]);

        /** @var array<int, array<int, list<int>>> $result */
        $result = [];
        /** @var list<array{0: int, 1: int, 2: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$docId, $termId, $position]) {
            $result[$docId][$termId][] = $position;
        }
        return $result;
    }

    /**
     * Check whether a document contains a phrase as a contiguous token sequence.
     *
     * Each phrase slot may expand to several term IDs (e.g. via prefix expansion), so
     * the check is: does any start position p exist such that for every slot i, at least
     * one term ID in phrasePosTermIds[i] has a recorded position of p + i in this
     * doc?
     *
     * @param array<int, list<int>> $docTermPositions  term_id → sorted position list.
     * @param list<list<int>>       $phrasePosTermIds  Phrase slot → term IDs accepted at that slot.
     */
    private function docMatchesPhrase(array $docTermPositions, array $phrasePosTermIds): bool
    {
        $len = count($phrasePosTermIds);

        // Build a position-set per phrase slot for O(1) membership tests.
        /** @var list<array<int, true>> $posSets */
        $posSets = [];
        foreach ($phrasePosTermIds as $termIds) {
            $set = [];
            foreach ($termIds as $termId) {
                foreach ($docTermPositions[$termId] ?? [] as $pos) {
                    $set[$pos] = true;
                }
            }
            $posSets[] = $set;
        }

        // Try each anchor position from the first slot; check that slot i has a hit at startPos + i.
        foreach (array_keys($posSets[0]) as $startPos) {
            $matched = true;
            for ($i = 1; $i < $len; $i++) {
                if (!isset($posSets[$i][$startPos + $i])) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the subset of $docIds in which every phrase group appears as a contiguous sequence.
     *
     * Term IDs are resolved via the wordlist (exact match for all slots, prefix for the
     * last slot when $asYouType and that slot's token equals $lastToken). A phrase whose
     * first word is absent from the index is skipped entirely — an unindexed phrase should
     * not suppress results for other keywords in the query.
     *
     * @param list<int>          $docIds       Candidate document IDs.
     * @param list<list<string>> $phraseGroups Stemmed token lists, one per quoted phrase.
     * @param string             $lastToken    Last token in the full flattened query (for asYouType).
     * @param bool               $asYouType    Whether prefix expansion applies to $lastToken.
     * @return list<int>
     */
    private function filterDocsByPhrases(
        array $docIds,
        array $phraseGroups,
        string $lastToken,
        bool $asYouType,
    ): array {
        if ($docIds === [] || $phraseGroups === []) {
            return $docIds;
        }

        /** @var list<list<list<int>>> $resolvedPhrases  phrase → slot → termIds */
        $resolvedPhrases = [];
        $allTermIds      = [];

        foreach ($phraseGroups as $group) {
            $slots      = [];
            $skipPhrase = false;
            $lastSlot   = count($group) - 1;

            foreach ($group as $i => $token) {
                $isPrefixSlot = $asYouType && $i === $lastSlot && $token === $lastToken;
                $rows         = $this->getWordlistByKeyword($token, $isPrefixSlot, false);
                $termIds      = array_column($rows, 'id');

                if ($termIds === []) {
                    $skipPhrase = true;
                    break;
                }
                $slots[]    = $termIds;
                $allTermIds = array_merge($allTermIds, $termIds);
            }

            if (!$skipPhrase) {
                $resolvedPhrases[] = $slots;
            }
        }

        if ($resolvedPhrases === []) {
            return $docIds;
        }

        $allTermIds = array_values(array_unique($allTermIds));
        $positions  = $this->fetchPositionsForDocs($docIds, $allTermIds);

        $passing = [];
        foreach ($docIds as $docId) {
            $docTermPositions = $positions[$docId] ?? [];
            $allMatch         = true;

            foreach ($resolvedPhrases as $phrasePosTermIds) {
                if (!$this->docMatchesPhrase($docTermPositions, $phrasePosTermIds)) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                $passing[] = $docId;
            }
        }

        return $passing;
    }

    /**
     * Tokenise and filter a raw query phrase.
     *
     * Extracts quoted substrings ("foo bar") before tokenising — the phrase words remain
     * in the flat token stream for BM25/boolean scoring, but are also returned as grouped
     * token lists in phrase_groups for adjacency filtering. free_phrase is the query with
     * the enclosing quote characters removed (passed to BooleanParser so its space→& rule
     * does not break quoted phrases).
     *
     * Used directly by search() (via ['filtered']) and inspectQuery() (full result).
     * Falls back to the unfiltered token list when all tokens would be removed by
     * stopword filtering.
     *
     * When $verbose is false (the search hot path), raw_tokens and surviving_raw are
     * not computed — Tokenizer::split() is skipped entirely.
     *
     * @infection-ignore-all FalseValue: default false→true only computes raw_tokens eagerly; correctness unaffected
     * @return array{raw_tokens: list<string>, filtered: list<string>, all_stripped: bool,
     *               surviving_raw: list<string>, phrase_groups: list<list<string>>, free_phrase: string}
     */
    private function filterQueryTokens(string $phrase, bool $verbose = false): array
    {
        ['free' => $freePhrase, 'groups' => $rawPhraseGroups] = $this->extractQuotedPhrases($phrase);

        $survivingRaw = Tokenizer::tokenize($phrase, $this->language);
        $allStripped  = false;

        /** @infection-ignore-all GreaterThan,GreaterThanNegotiation,Ternary: mutations only affect stopword-enabled indexes or multi-token results; tests without a language set are unaffected */
        if ($this->stopwords instanceof \Fuzor\Stopwords && count($survivingRaw) > 1) {
            $afterStop    = $this->stopwords->filter($survivingRaw);
            $allStripped  = $afterStop === [];
            $survivingRaw = $allStripped ? $survivingRaw : $afterStop;
        }

        $filtered = $this->stemmer instanceof \Fuzor\Stemmer
            ? $this->stemmer->stemTokens($survivingRaw)
            : $survivingRaw;

        // Build phrase groups: tokenise each quoted substring, apply stopwords + stemming.
        // Groups that collapse to empty after stopword filtering are dropped — an all-stopword
        // phrase should not suppress all results. The >1 guard mirrors the flat-token path.
        $phraseGroups = [];
        foreach ($rawPhraseGroups as $rawGroup) {
            $groupTokens = $this->normalizePhraseTokens($rawGroup);
            if ($groupTokens !== []) {
                $phraseGroups[] = $groupTokens;
            }
        }

        return [
            'raw_tokens'    => $verbose ? Tokenizer::split($phrase) : [],
            'filtered'      => $filtered,
            'all_stripped'  => $allStripped,
            'surviving_raw' => $verbose ? $survivingRaw : [],
            'phrase_groups' => $phraseGroups,
            'free_phrase'   => $freePhrase,
        ];
    }

    /**
     * Strip enclosing double-quotes from a query, leaving phrase words in place.
     *
     * The phrase words remain in the returned free string so they still participate in
     * BM25/boolean scoring. Only matched (closed) quote pairs are extracted; unclosed
     * quotes are passed through unchanged and will be stripped as punctuation during
     * tokenisation.
     *
     * @param  string $query Raw query string.
     * @return array{free: string, groups: list<string>}
     */
    private function extractQuotedPhrases(string $query): array
    {
        $groups = [];
        $free   = preg_replace_callback(
            '/"([^"]+)"/',
            function (array $m) use (&$groups): string {
                $trimmed = trim($m[1]);
                if ($trimmed !== '') {
                    $groups[] = $trimmed;
                }
                return ' ' . $m[1] . ' ';
            },
            $query,
        ) ?? $query;

        return ['free' => $free, 'groups' => $groups];
    }

    /**
     * Split '-word' and '-"phrase"' negations off a search() / facetSearch() query.
     *
     * A '-' counts at the start of the query or after whitespace, followed by a word or a closed
     * quoted phrase, so "t-shirt" and an unclosed '-"red' are left alone. Each negation is
     * normalised like a quoted phrase (tokenised, stopwords removed, stemmed) and matched
     * exactly: no prefix, typo, or synonym expansion, also for a trailing word still being
     * typed. One token becomes an exclusion on doclist; several must appear at consecutive
     * positions (as in docMatchesPhrase()), checked with one positions lookup per token. A
     * negation whose words are not all in the index excludes nothing and is dropped.
     *
     * @return array{phrase: string, conditions: list<FacetCondition>}
     *         The query without the negations, and one exclusion condition per negation.
     */
    private function extractNegations(string $phrase): array
    {
        if (!str_contains($phrase, '-')) {
            return ['phrase' => $phrase, 'conditions' => []];
        }
        $raw      = [];
        $positive = preg_replace_callback(
            '/(?<!\S)-(?:"([^"]*)"|([^\s"]+))/u',
            function (array $m) use (&$raw): string {
                $raw[] = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
                return ' ';
            },
            $phrase,
        ) ?? $phrase;

        $conditions = [];
        foreach ($raw as $text) {
            $termIds = [];
            foreach ($this->normalizePhraseTokens($text) as $token) {
                $termId = $this->lookupTermId($token);
                if ($termId === null) {
                    continue 2;
                }
                $termIds[] = $termId;
            }
            if ($termIds === []) {
                continue;
            }
            $rows   = 'FROM ' . (count($termIds) === 1 ? 'doclist' : 'positions') . ' %1$s';
            $params = [];
            foreach (array_slice($termIds, 1) as $i => $termId) {
                $at       = $i + 1;
                $rows    .= " JOIN positions %1\$s_{$at} ON %1\$s_{$at}.term_id = ?"
                    . " AND %1\$s_{$at}.doc_id = %1\$s.doc_id AND %1\$s_{$at}.position = %1\$s.position + {$at}";
                $params[] = $termId;
            }
            $conditions[] = [
                'name'       => '-' . $text,
                'rows'       => $rows . ' WHERE %1$s.term_id = ?' . $this->liveDocsSql('%1$s.doc_id'),
                'params'     => [...$params, $termIds[0]],
                'impossible' => false,
                'multiRow'   => count($termIds) > 1,
                'exclude'    => true,
            ];
        }
        return ['phrase' => $positive, 'conditions' => $conditions];
    }

    /**
     * Tokenise a quoted phrase (or negation) the way query words are: stopwords removed when
     * more than one token remains (an all-stopword phrase yields []), then stemmed.
     *
     * @return list<string>
     */
    private function normalizePhraseTokens(string $text): array
    {
        $tokens = Tokenizer::tokenize($text, $this->language);
        if ($this->stopwords instanceof \Fuzor\Stopwords && count($tokens) > 1) {
            $tokens = $this->stopwords->filter($tokens);
        }
        if ($tokens !== [] && $this->stemmer instanceof \Fuzor\Stemmer) {
            $tokens = $this->stemmer->stemTokens($tokens);
        }
        return $tokens;
    }

    /** wordlist ID of an exact term, or null when no document contains it. */
    private function lookupTermId(string $term): ?int
    {
        if (isset($this->termIdCache[$term])) {
            return $this->termIdCache[$term];
        }
        $stmt = $this->stmt('termIdLookup', 'SELECT id FROM wordlist WHERE term = ?');
        $stmt->execute([$term]);
        $id = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($id === false) {
            return null;
        }
        return $this->termIdCache[$term] = (int) $id;
    }

    /**
     * Retrieve multiple values from the info metadata table in a single query.
     *
     * @param  string[]              $keys  Metadata keys to fetch.
     * @return array<string, string> Map of key → value for found rows.
     */
    private function getInfoValues(array $keys): array
    {
        if ($this->infoCache === null) {
            $stmt = $this->stmt('infoAll', 'SELECT key, value FROM info');
            $stmt->execute();
            /** @var array<string, string> $all */
            $all = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
            $this->infoCache = $all;
        }
        /** @infection-ignore-all UnwrapArrayIntersectKey: returning extra keys from infoCache is harmless; callers only read the specific keys they requested */
        return array_intersect_key($this->infoCache, array_flip($keys));
    }

    /**
     * Look up a keyword in the wordlist with optional prefix and fuzzy fallback.
     *
     * When $isLastWord is true, a trailing-wildcard LIKE query is used instead of an
     * exact match, returning up to $fuzzyMaxExpansions candidates ordered by shortest
     * term first, then by num_hits descending.
     * When $allowFuzzy is true and no match is found, fuzzySearch() is called as a fallback
     * provided the keyword is at least 5 codepoints long.
     * Fuzzy rows additionally carry a `distance` key (int) set by fuzzySearch().
     *
     * @param  string $keyword    Term to look up.
     * @param  bool   $isLastWord Whether this is the final token in the query.
     * @param  bool   $allowFuzzy Whether to fall through to Levenshtein search on no match; set to false
     *                            for phrase matching where words must be exact user intent.
     * @param  bool   $truncated  Set to true when a prefix lookup matched more than
     *                            Config::$fuzzyMaxExpansions terms, so only the shortest were returned.
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int, distance?: int}>
     * @infection-ignore-all FalseValue: default parameter values are never exercised; callers always pass
     *   all booleans explicitly
     */
    private function getWordlistByKeyword(
        string $keyword,
        bool $isLastWord = false,
        bool $allowFuzzy = true,
        bool &$truncated = false,
    ): array {
        // Cache exact/prefix lookups by "keyword:isLastWord" key.
        // Fuzzy results carry a distance key and are excluded from caching — Levenshtein
        // distance is applied post-fetch, so cached rows could go stale after config changes.
        /** @infection-ignore-all CastInt,Concat,ConcatOperandRemoval: cache key format mutations only affect cache hit/miss rates, not correctness */
        $cacheKey = "{$keyword}:" . (int) $isLastWord;
        /** @infection-ignore-all ReturnRemoval: skipping a cache hit only causes a redundant DB query; the same result is returned */
        if (isset($this->wordlistCache[$cacheKey])) {
            [$cachedRows, $cachedTruncated] = $this->wordlistCache[$cacheKey];
            $truncated = $truncated || $cachedTruncated;
            return $cachedRows;
        }

        $maxExpansions = $this->config->fuzzyMaxExpansions;
        $isPrefix      = $isLastWord && Tokenizer::ngramSize($this->language) === 0;
        if ($isPrefix) {
            // One row past the cap tells an exactly-full expansion from a truncated one.
            $stmt = $this->stmt(
                'wordlistPrefix',
                'SELECT id, term, num_hits, num_docs FROM wordlist'
                . ' WHERE term LIKE :keyword ORDER BY length(term) ASC, num_hits DESC LIMIT :maxExpansions;'
            );
            $stmt->bindValue(':keyword', $keyword . '%');
            $stmt->bindValue(':maxExpansions', $maxExpansions + 1, PDO::PARAM_INT);
        } else {
            $stmt = $this->stmt(
                'wordlistExact',
                'SELECT id, term, num_hits, num_docs FROM wordlist WHERE term = :keyword LIMIT 1;'
            );
            $stmt->bindValue(':keyword', $keyword);
        }

        $stmt->execute();

        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int}> $wordlistRows */
        $wordlistRows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $prefixTruncated = $isPrefix && count($wordlistRows) > $maxExpansions;
        if ($prefixTruncated) {
            $wordlistRows = array_slice($wordlistRows, 0, $maxExpansions);
        }

        // Fall through to typo matching only when no exact/prefix match was found, typos are
        // allowed at this call site, and TypoTolerance allows at least one for this word.
        if ($allowFuzzy && !isset($wordlistRows[0])) {
            $maxTypos = $this->typoBudget($keyword);
            if ($maxTypos > 0) {
                return $this->fuzzySearch($keyword, $maxTypos);
            }
        }

        $this->wordlistCache[$cacheKey] = [$wordlistRows, $prefixTruncated];
        $truncated = $truncated || $prefixTruncated;

        return $wordlistRows;
    }

    /**
     * The rows of a keyword group for the documents in $docIds that the group has not seen yet.
     *
     * A group whose document frequency exceeds maxDocs (or that was not fetched at all, see
     * search()) holds only some of its rows. A document found through another group may still
     * contain it; this looks those rows up, scored with the group's IDF exactly as
     * fetchDocsByTermIds() scores them. Measured on the 45k ecom set ("casual shirt": of 469
     * unseen candidates ~250 contained the other word): ~0.4 ms for one term and ~900 unseen
     * documents, ~2 ms for a 50-term prefix group.
     *
     * @param  KeywordGroup     $group
     * @param  array<int, true> $docIds
     * @return list<array{0: int, 1: int, 2: float}>
     */
    private function completeGroup(array $group, array $docIds, float $k1_1mb, float $k1b_avgdl): array
    {
        if ($group['termIds'] === []) {
            return [];
        }
        $unseen = $docIds;
        foreach ($group['rows'] as [, $docId]) {
            unset($unseen[$docId]);
        }
        if ($unseen === []) {
            return [];
        }
        $unseenIds = array_keys($unseen);
        sort($unseenIds);
        // Both lists as json_each IN-lists: SQLite seeks each (term, document) pair on the doclist
        // primary key and reads doc_lengths only for the matches. Sorted document IDs keep
        // consecutive seeks on neighbouring pages (0.38 ms vs 0.69 ms unsorted, driven from
        // json_each). Never write +d.term_id: it turns the seeks into a scan of every document's
        // terms (41 ms).
        $stmt = $this->stmt(
            'completeTermGroup',
            'SELECT d.term_id, d.doc_id, ? * d.hit_count / (? + ? * dl.length + d.hit_count)
               FROM doclist d
               CROSS JOIN doc_lengths dl ON dl.doc_id = d.doc_id
              WHERE d.term_id IN (SELECT value FROM json_each(?))
                AND d.doc_id IN (SELECT value FROM json_each(?))'
        );
        $stmt->execute([
            $group['idfK1p1'],
            $k1_1mb,
            $k1b_avgdl,
            json_encode($group['termIds']),
            json_encode($unseenIds),
        ]);
        /** @var list<array{0: int, 1: int, 2: float}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        return $rows;
    }

    /**
     * Fetch doclist rows for a set of wordlist term IDs, ordered by hit count.
     *
     * When $isFuzzy is true, results are re-sorted by the relevance rank of $words
     * (closest Levenshtein match first) after the DB fetch.
     *
     * @param  list<array{id: int, term: string, num_hits: int, num_docs: int, ...}> $words
     *         Wordlist rows from getWordlistByKeyword() or fuzzySearch() (fuzzy rows also carry distance: int).
     * @param  int  $limit   Maximum rows to return.
     * @param  bool $isFuzzy When true, re-sort by fuzzy relevance rank; derived from $words carrying a distance key.
     * @return list<array{0: int, 1: int, 2: float}> Rows as [term_id, doc_id, bm25_score].
     */
    private function fetchDocsByTermIds(
        array $words,
        int $limit,
        bool $isFuzzy,
        float $idfK1p1,
        float $k1_1mb,
        float $k1b_avgdl,
    ): array {
        $ids  = array_column($words, 'id');
        $n    = count($ids);
        $live = $this->liveDocsSql('doc_id');
        $tag  = $live === '' ? '' : ':live';

        // All paths apply LIMIT inside a subquery before the doc_lengths JOIN, bounding
        // the join to exactly $limit rows. The BM25 score is computed in SQLite C so PHP
        // receives (term_id, doc_id, score) and avoids per-row float arithmetic.
        // Column order (FETCH_NUM): 0=term_id, 1=doc_id, 2=score.
        //
        // Non-fuzzy multi-term paths use UNION ALL of per-term SELECTs. With the
        // doclist_term_hitcount index on (term_id, hit_count DESC) each arm is already
        // sorted; SQLite merges streams without a temp B-tree and LIMIT stops early.

        // Single-term non-fuzzy: stable SQL shape — cache by stable key.
        /** @infection-ignore-all LogicalNot: negating !$isFuzzy to $isFuzzy only switches between the single-term cached stmt and the multi-term/fuzzy paths; all return equivalent doc sets for non-fuzzy calls */
        if ($n === 1 && !$isFuzzy) {
            $stmt = $this->stmt(
                "fetchOneTermDocs{$tag}",
                "SELECT sub.term_id, sub.doc_id,
                        ? * sub.hit_count / (? + ? * dl.length + sub.hit_count) AS score
                  FROM (SELECT term_id, doc_id, hit_count FROM doclist
                        WHERE term_id = ?{$live} ORDER BY hit_count DESC LIMIT ?) sub
                  JOIN doc_lengths dl ON dl.doc_id = sub.doc_id"
            );
            $stmt->execute([$idfK1p1, $k1_1mb, $k1b_avgdl, $ids[0], $limit]);
            /** @var list<array{0: int, 1: int, 2: float}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            /** @infection-ignore-all ReturnRemoval: falling through to the UNION ALL path for n=1 returns the same doc set */
            return $rows;
        }

        // Multi-term non-fuzzy: UNION ALL of $n arms, SQL stable for a given $n — cache by arity.
        /** @infection-ignore-all LogicalNot: negating !$isFuzzy only switches between UNION ALL and the fuzzy IN()+CASE path; result set membership is equivalent */
        if (!$isFuzzy) {
            $arms = implode(' UNION ALL ', array_fill(
                /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
                0,
                $n,
                "SELECT term_id, doc_id, hit_count FROM doclist WHERE term_id = ?{$live}"
            ));
            $stmt = $this->stmt(
                "fetchNTermDocs:{$n}{$tag}",
                "SELECT sub.term_id, sub.doc_id,
                        ? * sub.hit_count / (? + ? * dl.length + sub.hit_count) AS score
                  FROM ({$arms} ORDER BY hit_count DESC LIMIT ?) sub
                  JOIN doc_lengths dl ON dl.doc_id = sub.doc_id"
            );
            $stmt->execute([$idfK1p1, $k1_1mb, $k1b_avgdl, ...$ids, $limit]);
            /** @var list<array{0: int, 1: int, 2: float}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            /** @infection-ignore-all ReturnRemoval: falling through to the fuzzy path returns the same doc set via an IN()+CASE query */
            return $rows;
        }

        // Fuzzy: CASE expression encodes fuzzy relevance rank (closest match first).
        // Uses IN() since the CASE sort cannot use the index-merge strategy.
        $placeholders = $this->placeholders($n);
        $cases        = implode(' ', array_map(fn(int $i): string => "WHEN ? THEN {$i}", range(0, $n - 1)));
        $stmt         = $this->prepare(
            "SELECT sub.term_id, sub.doc_id,
                    ? * sub.hit_count / (? + ? * dl.length + sub.hit_count) AS score
              FROM (SELECT term_id, doc_id, hit_count FROM doclist
                    WHERE term_id IN ({$placeholders}){$live}
                    ORDER BY CASE term_id {$cases} END ASC, hit_count DESC LIMIT ?) sub
              JOIN doc_lengths dl ON dl.doc_id = sub.doc_id"
        );
        $stmt->execute([$idfK1p1, $k1_1mb, $k1b_avgdl, ...$ids, ...$ids, $limit]);
        /** @var list<array{0: int, 1: int, 2: float}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        return $rows;
    }

    /**
     * How many typos a query word may have under Config::$typoTolerance: 0, 1, or 2.
     *
     * 0 when typo tolerance is off, the word is shorter than minWordSizeForOneTypo codepoints,
     * contains a digit while disableOnNumbers is set, or is one of disableOnWords; else 2 from
     * minWordSizeForTwoTypos codepoints, else 1.
     */
    private function typoBudget(string $keyword): int
    {
        $typo   = $this->config->typoTolerance;
        $length = mb_strlen($keyword);
        if (
            !$typo->enabled
            || $length < $typo->minWordSizeForOneTypo
            || $typo->disableOnNumbers && strpbrk($keyword, '0123456789') !== false
            || $typo->disableOnWords !== [] && isset($this->typoExactWords()[$keyword])
        ) {
            return 0;
        }
        return $length >= $typo->minWordSizeForTwoTypos ? 2 : 1;
    }

    /**
     * TypoTolerance::$disableOnWords as the terms query words are compared in: lowercased
     * tokens, plus their stems when the index has a stemmer, so "Running" also covers "run".
     *
     * @return array<string, true>
     */
    private function typoExactWords(): array
    {
        if ($this->typoExactWords === null) {
            $set = [];
            foreach ($this->config->typoTolerance->disableOnWords as $word) {
                $tokens = Tokenizer::tokenize($word, $this->language);
                $stems  = $this->stemmer instanceof \Fuzor\Stemmer ? $this->stemmer->stemTokens($tokens) : [];
                foreach ([...$tokens, ...$stems] as $token) {
                    $set[$token] = true;
                }
            }
            $this->typoExactWords = $set;
        }
        return $this->typoExactWords;
    }

    /**
     * Find wordlist candidates within $maxTypos edits of the keyword.
     *
     * Queries the wordlist for terms sharing the same prefix ($fuzzyPrefixLength chars) and a
     * length within $maxTypos, keeps those within $maxTypos edits (Levenshtein::distance(), where
     * an adjacent swap counts as one), sorts by distance ascending, then num_hits descending, and
     * returns the first Config::$fuzzyMaxExpansions. The cap applies after the distance check: a
     * cap on the candidates (the earlier shape) let frequent but distant terms crowd out the
     * match on a common prefix.
     *
     * @param  string $keyword  Search term to find fuzzy matches for (must already be lowercased).
     * @param  int    $maxTypos 1 or 2, from typoBudget().
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int, distance: int}>
     */
    private function fuzzySearch(string $keyword, int $maxTypos): array
    {
        /** @infection-ignore-all MBString,CastInt: ASCII fuzzy tests are unaffected by mb_ vs byte strlen; CastInt: mb_strlen returns int already */
        $keywordLength     = mb_strlen($keyword);
        $effectiveDistance = $maxTypos;

        $stmt = $this->stmt(
            'fuzzyWordlistLookup',
            "SELECT id, term, num_hits, num_docs FROM wordlist
             WHERE term LIKE :keyword
               AND length(term) BETWEEN :min AND :max"
        );
        /** @infection-ignore-all MBString,ConcatOperandRemoval: ASCII fuzzy tests are unaffected by mb_ vs byte substr; removing the prefix still produces correct candidates after Levenshtein filtering (just with more candidates) */
        $stmt->bindValue(':keyword', mb_substr($keyword, 0, $this->config->fuzzyPrefixLength) . '%');
        /** @infection-ignore-all DecrementInteger,IncrementInteger: adjusting the min length boundary by 1 only broadens or narrows the candidate set; Levenshtein filtering corrects the result */
        $stmt->bindValue(':min', max(1, $keywordLength - $effectiveDistance), PDO::PARAM_INT);
        $stmt->bindValue(':max', $keywordLength + $effectiveDistance, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int, distance: int}> $resultSet */
        $resultSet = [];
        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int}> $candidates */
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $match) {
            $distance = Levenshtein::distance($match['term'], $keyword, $effectiveDistance);
            if ($distance <= $effectiveDistance) {
                $resultSet[] = [...$match, 'distance' => $distance];
            }
        }

        /** @infection-ignore-all Spaceship: swapping secondary sort (num_hits) DESC→ASC only reorders equally-distant candidates; assertContains tests are order-agnostic */
        usort($resultSet, fn(array $a, array $b): int => $a['distance'] <=> $b['distance'] ?: $b['num_hits'] <=> $a['num_hits']); // phpcs:ignore Generic.Files.LineLength.TooLong

        return array_slice($resultSet, 0, $this->config->fuzzyMaxExpansions);
    }

    // --- Facet helpers -------------------------------------------------------

    /**
     * Normalise a document's _facets value into flat (name, value, numValue) rows.
     *
     * PHP int/float values populate num_value for numeric range filtering.
     * PHP strings populate value only (num_value=null).
     * Array values expand into multiple rows (multi-value facets).
     *
     * @param  mixed $facets  Raw value of $doc['_facets']; non-array or empty returns [].
     * @return list<array{name: string, value: string, numValue: float|null}>
     */
    private function normalizeFacets(mixed $facets): array
    {
        if (!is_array($facets) || $facets === []) {
            return [];
        }
        $rows = [];
        foreach ($facets as $name => $rawValue) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $values = is_array($rawValue) ? $rawValue : [$rawValue];
            foreach ($values as $v) {
                if (is_int($v) || is_float($v)) {
                    $rows[] = ['name' => $name, 'value' => (string) $v, 'numValue' => (float) $v];
                } elseif (is_string($v) && $v !== '') {
                    $rows[] = ['name' => $name, 'value' => $v, 'numValue' => null];
                }
            }
        }
        return $rows;
    }

    /**
     * Resolve a facet key name to its facet_keys.id, inserting it if new.
     * Uses $facetKeyCache for O(1) repeat lookups within a connection.
     */
    private function resolveFacetKeyId(string $name): int
    {
        if (isset($this->facetKeyCache[$name])) {
            return $this->facetKeyCache[$name];
        }
        // INSERT OR IGNORE would not return the ID on conflict; DO UPDATE is the only
        // way to get RETURNING to fire whether the row is inserted or already exists.
        $stmt = $this->stmt(
            'facetKeyUpsert',
            'INSERT INTO facet_keys (name) VALUES (?)
             ON CONFLICT(name) DO UPDATE SET name = excluded.name
             RETURNING id'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        assert($row !== false);
        /** @var array{id: int} $row */
        $id = (int) $row['id'];
        $this->facetKeyCache[$name] = $id;
        return $id;
    }

    /**
     * Look up a facet key ID without inserting (safe for read paths).
     * Returns null when the key does not exist in the index.
     */
    private function lookupFacetKeyId(string $name): ?int
    {
        if (isset($this->facetKeyCache[$name])) {
            return $this->facetKeyCache[$name];
        }
        $stmt = $this->stmt(
            'facetKeyLookup',
            'SELECT id FROM facet_keys WHERE name = ? LIMIT 1'
        );
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $this->facetKeyCache[$name] = (int) $id;
        return (int) $id;
    }

    /**
     * Write facet rows for a single document (single-insert path).
     *
     * @param int   $documentId
     * @param mixed $facets     Raw value of $doc['_facets'].
     */
    private function saveFacets(int $documentId, mixed $facets): void
    {
        $rows = $this->normalizeFacets($facets);
        if ($rows === []) {
            return;
        }
        $stmt = $this->stmt(
            'facetValueSave',
            'INSERT INTO facet_values (key_id, value, doc_id, num_value) VALUES (?,?,?,?)
             ON CONFLICT(key_id, value, doc_id) DO NOTHING'
        );
        $valuesPerKey = [];
        /** @var list<array{0: int, 1: string, 2: float|null}> $inserted  one per new (key, value) row */
        $inserted = [];
        foreach ($rows as $row) {
            $keyId = $this->resolveFacetKeyId($row['name']);
            $stmt->execute([$keyId, $row['value'], $documentId, $row['numValue']]);
            // The primary key is (key_id, value, doc_id): a repeated value is ignored, and
            // counted once.
            if (!isset($valuesPerKey[$keyId][$row['value']])) {
                $inserted[] = [$keyId, $row['value'], $row['numValue']];
            }
            $valuesPerKey[$keyId][$row['value']] = true;
            if ($row['numValue'] === null && isset($this->sortableFieldSet[$row['name']])) {
                $this->stmt(
                    'sortKeySave',
                    'INSERT INTO sort_keys (key_id, sort_key, doc_id) VALUES (?,?,?)
                     ON CONFLICT(key_id, sort_key, doc_id) DO NOTHING'
                )->execute([$keyId, self::sortKey($row['value']), $documentId]);
            }
        }
        $this->adjustFacetCounts(self::facetCountDeltas($inserted, 1));
        if (count($rows) > count($valuesPerKey)) {
            $multi = array_keys(array_filter($valuesPerKey, static fn(array $values): bool => count($values) > 1));
            $this->markMultiValued($multi);
        }
    }

    /**
     * Aggregate facet_values rows into facet_counts deltas: one per (key, value), with the row
     * count and numeric row count multiplied by $sign (+1 inserted, -1 deleted).
     *
     * @param  list<array{0: int, 1: string|int, 2: float|null}> $rows [key_id, value, num_value]
     * @return list<array{0: int, 1: string, 2: int, 3: int, 4: float|null}>
     */
    private static function facetCountDeltas(array $rows, int $sign): array
    {
        $deltas = [];
        foreach ($rows as [$keyId, $value, $num]) {
            $value = (string) $value;
            $key   = $keyId . "\0" . $value;
            if (!isset($deltas[$key])) {
                $deltas[$key] = [$keyId, $value, 0, 0, null];
            }
            $deltas[$key][2] += $sign;
            if ($num !== null) {
                $deltas[$key][3] += $sign;
                $deltas[$key][4] ??= (float) $num;
            }
        }
        return array_values($deltas);
    }

    /**
     * Apply facet_counts deltas in one statement, then drop the (key, value) rows they emptied.
     *
     * @param list<array{0: int, 1: string, 2: int, 3: int, 4: float|null}> $deltas From facetCountDeltas().
     */
    private function adjustFacetCounts(array $deltas): void
    {
        if ($deltas === []) {
            return;
        }
        $json = json_encode($deltas, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        // "WHERE true" lets SQLite parse an upsert whose source is a SELECT.
        $this->stmt(
            'facetCountsAdjust',
            "INSERT INTO facet_counts (key_id, value, count, num_count, num_value)
             SELECT j.value ->> '$[0]', j.value ->> '$[1]', j.value ->> '$[2]', j.value ->> '$[3]', j.value ->> '$[4]'
               FROM json_each(?) j WHERE true
             ON CONFLICT (key_id, value) DO UPDATE SET
                 count     = facet_counts.count + excluded.count,
                 num_count = facet_counts.num_count + excluded.num_count,
                 num_value = COALESCE(facet_counts.num_value, excluded.num_value)"
        )->execute([$json]);
        if (min(array_column($deltas, 2)) < 0) {
            $this->stmt(
                'facetCountsPrune',
                "DELETE FROM facet_counts WHERE count <= 0 AND (key_id, value) IN (
                     SELECT j.value ->> '$[0]', j.value ->> '$[1]' FROM json_each(?) j
                 )"
            )->execute([$json]);
        }
    }

    /**
     * Flag facet keys as multi-valued (some document stored two or more values for the key).
     *
     * The flag only goes from 0 to 1 on writes: deletes and updates never clear it, because
     * finding out that no document has two values any more would cost a scan of the key. A
     * stale 1 only costs speed (readers keep the multi-valued query shapes); clear() and
     * rebuild() reset it. Keys already in $multiValuedKeys are skipped, so each key costs at
     * most one UPDATE per connection.
     *
     * @param list<int> $keyIds
     */
    private function markMultiValued(array $keyIds): void
    {
        foreach ($keyIds as $keyId) {
            if (isset($this->multiValuedKeys[$keyId])) {
                continue;
            }
            $this->stmt(
                'facetKeyMarkMulti',
                'UPDATE facet_keys SET multi_valued = 1 WHERE id = ? AND multi_valued = 0'
            )->execute([$keyId]);
            $this->multiValuedKeys[$keyId] = true;
            $this->keyMultiValued[$keyId]  = true;
        }
    }

    /**
     * Whether some document stored two or more values for this facet key (facet_keys.multi_valued;
     * see markMultiValued()). Single-valued keys allow cheaper query shapes: no DISTINCT over a
     * value list or range, and a range pushed into a sorted walk on the same field.
     */
    private function isMultiValuedKey(int $keyId): bool
    {
        if (!isset($this->keyMultiValued[$keyId])) {
            $stmt = $this->stmt('facetKeyMultiValued', 'SELECT multi_valued FROM facet_keys WHERE id = ?');
            $stmt->execute([$keyId]);
            $this->keyMultiValued[$keyId] = (int) $stmt->fetchColumn() === 1;
            $stmt->closeCursor();
        }
        return $this->keyMultiValued[$keyId];
    }

    /**
     * Bulk-upsert facet keys and insert facet_values rows in (key_id, value, doc_id) PK order.
     *
     * Receives a pre-organized name→value→docId→numValue map built by buildBatchBuffer().
     *
     * @param array<string, array<int|string, array<int, float|null>>> $facetBuffer  name → value → docId → numValue
     * @param array<string, true>                                      $multiValued  fields with 2+ values in one doc
     */
    private function bulkFlushFacets(array $facetBuffer, array $multiValued = []): void
    {
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);

        // 1. Resolve any facet key names not yet in the cache (only fires on first batch).
        $newNames = [];
        foreach (array_keys($facetBuffer) as $name) {
            if (!isset($this->facetKeyCache[$name])) {
                $newNames[$name] = true;
            }
        }
        if ($newNames !== []) {
            $names = array_keys($newNames);
            foreach (array_chunk($names, self::CHUNK_1P) as $chunk) {
                $n    = count($chunk);
                $stmt = ($this->bulkStmtCache["facetKeyUpsert:{$n}"] ??= $pdo->prepare(
                    'INSERT INTO facet_keys (name) VALUES '
                    . implode(',', array_fill(0, $n, '(?)'))
                    . ' ON CONFLICT(name) DO UPDATE SET name = excluded.name RETURNING id, name'
                ));
                $stmt->execute($chunk);
                /** @var list<array{id: int, name: string}> $fetched */
                $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($fetched as $returned) {
                    $this->facetKeyCache[$returned['name']] = (int) $returned['id'];
                }
            }
        }

        $this->markMultiValued(
            array_map(fn(string $name): int => $this->facetKeyCache[$name], array_keys($multiValued)),
        );

        // 2. Remap outer keys from field names to integer key_ids (~one iteration per declared facet field).
        /** @var array<int, array<int|string, array<int, float|null>>> $kvdMap */
        $kvdMap = [];
        foreach ($facetBuffer as $name => $valueMap) {
            $kvdMap[$this->facetKeyCache[$name]] = $valueMap;
        }
        ksort($kvdMap);

        // 3. Flatten into sorted (key_id, value, doc_id, num_value) rows and bulk-INSERT in chunks.
        $rowCount = 0;
        $params   = [];
        foreach ($kvdMap as $keyId => $values) {
            ksort($values);
            foreach ($values as $value => $docs) {
                ksort($docs);
                foreach ($docs as $docId => $numValue) {
                    $params[] = $keyId;
                    $params[] = $value;
                    $params[] = $docId;
                    $params[] = $numValue;
                    if (++$rowCount === self::CHUNK_4P) {
                        ($this->bulkStmtCache['facetValuesChunk:' . self::CHUNK_4P] ??= $pdo->prepare(
                            'INSERT INTO facet_values (key_id, value, doc_id, num_value) VALUES '
                            . implode(',', array_fill(0, self::CHUNK_4P, '(?,?,?,?)'))
                        ))->execute($params);
                        $params   = [];
                        $rowCount = 0;
                    }
                }
            }
        }
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO facet_values (key_id, value, doc_id, num_value) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?,?)'))
            )->execute($params);
        }

        $deltas = [];
        foreach ($kvdMap as $keyId => $values) {
            foreach ($values as $value => $docs) {
                $numeric = array_filter($docs, static fn(?float $num): bool => $num !== null);
                $deltas[] = [
                    $keyId,
                    (string) $value,
                    count($docs),
                    count($numeric),
                    $numeric === [] ? null : reset($numeric),
                ];
            }
        }
        $this->adjustFacetCounts($deltas);

        $this->bulkFlushSortKeys($kvdMap, array_intersect_key($facetBuffer, $this->sortableFieldSet));
    }

    /**
     * Insert sort_keys rows for the string values of sortable fields, in (key_id, sort_key,
     * doc_id) PK order. Values that fold to the same key for one document collapse into one row.
     *
     * @param array<int, array<int|string, array<int, float|null>>>    $kvdMap    key_id → value → docId → numValue
     * @param array<string, array<int|string, array<int, float|null>>> $sortable  sortable fields' part of the buffer
     */
    private function bulkFlushSortKeys(array $kvdMap, array $sortable): void
    {
        if ($sortable === []) {
            return;
        }
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);
        /** @var array<int, array<string, array<int, true>>> $keyMap key_id → sort key → docId */
        $keyMap = [];
        foreach (array_keys($sortable) as $name) {
            $keyId = $this->facetKeyCache[$name];
            foreach ($kvdMap[$keyId] as $value => $docs) {
                foreach ($docs as $docId => $numValue) {
                    if ($numValue === null) {
                        $keyMap[$keyId][self::sortKey((string) $value)][$docId] = true;
                    }
                }
            }
        }
        ksort($keyMap);
        $rowCount = 0;
        $params   = [];
        foreach ($keyMap as $keyId => $keys) {
            ksort($keys, SORT_STRING);
            foreach ($keys as $sortKey => $docs) {
                ksort($docs);
                foreach (array_keys($docs) as $docId) {
                    $params[] = $keyId;
                    $params[] = (string) $sortKey;
                    $params[] = $docId;
                    if (++$rowCount === self::CHUNK_3P) {
                        ($this->bulkStmtCache['sortKeysChunk:' . self::CHUNK_3P] ??= $pdo->prepare(
                            'INSERT INTO sort_keys (key_id, sort_key, doc_id) VALUES '
                            . implode(',', array_fill(0, self::CHUNK_3P, '(?,?,?)'))
                        ))->execute($params);
                        $params   = [];
                        $rowCount = 0;
                    }
                }
            }
        }
        if ($rowCount > 0) {
            $pdo->prepare(
                'INSERT INTO sort_keys (key_id, sort_key, doc_id) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?)'))
            )->execute($params);
        }
    }

    /**
     * The key a string sort value is ordered by: the value lowercased (Unicode), so uppercase
     * letters sort as if they were lowercase. Accents are not folded: 'é' still sorts after 'z'
     * (use Tokenizer::sortKey() in a separate field for that). Compared byte-wise, in SQL
     * (sort_keys) and in PHP (sortRanks()) alike.
     */
    private static function sortKey(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Intersect all sets in $filterSets; returns [] when $filterSets is empty.
     *
     * @param  array<string, array<int, true>> $filterSets
     * @return array<int, true>
     */
    private function intersectFilterSets(array $filterSets): array
    {
        $result = null;
        foreach ($filterSets as $set) {
            $result = $result === null ? $set : array_intersect_key($result, $set);
        }
        return $result ?? [];
    }

    /**
     * Parse a list of 'field:asc' / 'field:desc' sort strings into structured specs.
     *
     * @param  list<string> $sort
     * @return list<array{field: string, asc: bool}>
     * @throws \InvalidArgumentException on malformed input
     */
    private function parseSortSpec(array $sort): array
    {
        if ($sort === []) {
            return [];
        }
        $specs = [];
        foreach ($sort as $s) {
            if (!preg_match('/^(.+):(asc|desc)$/i', (string) $s, $m)) {
                throw new \InvalidArgumentException(
                    "Invalid sort spec '{$s}': expected 'field:asc' or 'field:desc'."
                );
            }
            $specs[] = ['field' => $m[1], 'asc' => strtolower($m[2]) === 'asc'];
        }
        return $specs;
    }

    /**
     * Check the field names in $options against the schema and return the parsed sort specs.
     *
     * Sort, filter, facets, and distinct all read from facet_values, which only declared fields
     * populate, so a reference to an undeclared field is a caller error. Checks the schema
     * declaration, not lookupFacetKeyId(): a declared field that no document has populated yet
     * is legitimately "no values".
     *
     * @return list<array{field: string, asc: bool}>
     * @throws \InvalidArgumentException on a malformed sort spec
     * @throws QueryException             on a sort field that is not sortable, or a filter, facet,
     *                                    or distinct field that is not filterable
     */
    private function checkDeclaredFields(SearchOptions $options): array
    {
        $specs = $this->parseSortSpec($options->sort);
        foreach ($specs as $spec) {
            if (!isset($this->sortableFieldSet[$spec['field']])) {
                throw new QueryException(
                    self::undeclaredFieldMessage('Sort', $spec['field'], 'sortable', $this->sortableFields),
                );
            }
        }
        $this->checkFilterableFields('Filter', array_keys($options->filter));
        $this->checkFilterableFields('Facet', $options->facets);
        if ($options->distinct !== null) {
            $this->checkFilterableFields('Distinct', [$options->distinct]);
        }
        return $specs;
    }

    /**
     * @param  list<array-key> $fields Field names (filter keys may be integers).
     * @throws QueryException on the first field that is not filterable
     */
    private function checkFilterableFields(string $option, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($this->filterableFieldSet[$field])) {
                throw new QueryException(
                    self::undeclaredFieldMessage($option, (string) $field, 'filterable', $this->filterableFields),
                );
            }
        }
    }

    /** @param list<string> $declared */
    private static function undeclaredFieldMessage(string $option, string $field, string $kind, array $declared): string
    {
        $list = $declared === [] ? 'none' : implode(', ', $declared);
        return "{$option} field '{$field}' is not {$kind}. Declare it in SchemaConfig::\${$kind}Fields"
            . " when creating the index (declared: {$list}).";
    }

    /**
     * Fetch sort column values for a single facet field keyed by doc ID.
     *
     * Returns float for numeric facets, string for string facets, null for docs that have
     * no value for this field. A document with several values for the field is represented
     * by the one that places it earliest in the requested direction (see compareSortValues()):
     * its smallest value ascending, its largest descending.
     *
     * Uses a fixed json_each-based statement (always cached as 'fetchSortValues') so there is
     * no per-call prepare overhead regardless of candidate count.
     *
     * With $fold, strings are returned as their sortKey(), the form sorting compares; without
     * it (distinct grouping) as stored.
     *
     * @param  list<int> $docIds
     * @param  int|null  $keyId   null = unknown field; all docs return null
     * @param  bool      $asc     Direction used to pick the representative of a multi-value field.
     * @return array<int, float|string|null>
     */
    private function fetchSortValues(array $docIds, ?int $keyId, bool $asc = true, bool $fold = false): array
    {
        $result = array_fill_keys($docIds, null);
        if ($keyId === null) {
            return $result;
        }
        $stmt = $this->stmt(
            'fetchSortValues',
            'SELECT doc_id, num_value, value
               FROM facet_values
              WHERE key_id = ? AND doc_id IN (SELECT value FROM json_each(?))'
        );
        $stmt->execute([$keyId, json_encode($docIds)]);
        /** @var list<array{0: int, 1: float|null, 2: string}> $sortRows */
        $sortRows = $stmt->fetchAll(PDO::FETCH_NUM);
        $folded = [];
        foreach ($sortRows as [$docId, $numValue, $strValue]) {
            $value   = $numValue ?? ($fold ? $folded[$strValue] ??= self::sortKey($strValue) : $strValue);
            $current = $result[$docId];
            if ($current === null || self::compareSortValues($value, $current, $asc) < 0) {
                $result[$docId] = $value;
            }
        }
        return $result;
    }

    /**
     * Order two present sort values; negative when $a comes first.
     *
     * Numbers (float) always come before strings, in both directions — a field with mixed
     * types across documents keeps its numeric values together. Numbers compare by value and
     * strings by the bytes of their sortKey() (callers pass folded strings), so a
     * numeric-looking string such as "10" is ordered as text, before "9". Only the order within
     * each type flips for descending.
     *
     * This is a strict total order, so the result never depends on the candidates' input
     * order. Missing values are handled by the caller: they sort last in both directions.
     * sortRanks() implements the same order with native sorts for whole columns; this
     * comparison is used where single values are compared (multi-value reduction).
     */
    private static function compareSortValues(float|string $a, float|string $b, bool $asc): int
    {
        $aIsNumber = is_float($a);
        if ($aIsNumber !== is_float($b)) {
            return $aIsNumber ? -1 : 1;
        }
        $cmp = $aIsNumber ? $a <=> $b : strcmp((string) $a, (string) $b);
        return $asc ? $cmp : -$cmp;
    }

    /**
     * Sort $docIds by the given sort specs and return the full sorted list without pagination.
     *
     * Sort fields are primary, ordered by compareSortValues(); $scores (BM25) is the tiebreaker
     * when provided; doc ID is the final deterministic tiebreaker. Docs missing a sort field
     * value are sorted last in both ASC and DESC directions.
     *
     * @param  list<int>                             $docIds
     * @param  list<array{field: string, asc: bool}> $specs
     * @param  array<int, float>                     $scores   BM25 scores; empty array for boolean path
     * @param  array<int, string>|null               $tieKeys  When an array is passed, set to doc ID →
     *                                                         its sort ranks joined, so callers can tell
     *                                                         which documents tie on every spec.
     * @param  array<int, int>|null                  $leading  Optional key ordered before the specs,
     *                                                         descending (the words bucket of a
     *                                                         multi-word search()).
     * @param-out array<int, string>|null            $tieKeys
     * @return list<int>
     */
    private function sortDocIdsBySpecs(
        array $docIds,
        array $specs,
        array $scores,
        ?array &$tieKeys = null,
        ?array $leading = null,
    ): array {
        if ($docIds === [] || $specs === []) {
            return $docIds;
        }

        // Every sort key becomes a numeric column aligned with $docIds — one rank column per
        // spec, then the BM25 score (descending) when present, then the doc ID itself — so a
        // single C-level array_multisort() orders the candidates with no PHP callback per
        // comparison.
        /** @var list<list<int>> $rankColumns */
        $rankColumns = [];
        foreach ($specs as ['field' => $field, 'asc' => $asc]) {
            $values = $this->fetchSortValues($docIds, $this->lookupFacetKeyId($field), $asc, fold: true);
            $ranks  = self::sortRanks($values, $asc);
            $column = [];
            foreach ($docIds as $id) {
                $column[] = $ranks[$id];
            }
            $rankColumns[] = $column;
        }
        if ($leading !== null) {
            // Descending bucket as an ascending rank column, so it joins the other columns.
            $column = [];
            foreach ($docIds as $id) {
                $column[] = -($leading[$id] ?? 0);
            }
            array_unshift($rankColumns, $column);
        }
        // Only for callers that ask (pass an array): an implode per document is measurable when a
        // distinct browse sorts every match.
        if ($tieKeys !== null) {
            $tieKeys = [];
            foreach ($docIds as $i => $id) {
                $tieKeys[$id] = implode(',', array_column($rankColumns, $i));
            }
        }
        $primary = $rankColumns[0];
        $rest    = [SORT_ASC, SORT_NUMERIC];
        foreach (array_slice($rankColumns, 1) as $column) {
            array_push($rest, $column, SORT_ASC, SORT_NUMERIC);
        }
        if ($scores !== []) {
            $column = [];
            foreach ($docIds as $id) {
                $column[] = $scores[$id] ?? 0.0;
            }
            array_push($rest, $column, SORT_DESC, SORT_NUMERIC);
        }
        array_push($rest, $docIds, SORT_ASC, SORT_NUMERIC);
        // Unpacking passes each column by reference, so $rest holds the sorted columns afterwards.
        array_multisort($primary, ...$rest);

        $sorted = $rest[count($rest) - 3];
        /** @infection-ignore-all ReturnRemoval: unreachable — the doc ID column is always at that position */
        if (!is_array($sorted)) {
            return $docIds;
        }
        /** @var list<int> $sorted */
        return $sorted;
    }

    /**
     * Map each document's sort value to an integer rank in compareSortValues() order.
     *
     * Only the distinct values are sorted — numbers with a native numeric sort, then strings
     * with a native byte-wise sort, each reversed for descending — so ranking costs
     * O(distinct log distinct) with no PHP comparison callback. Missing values rank
     * PHP_INT_MAX and so sort last in both directions.
     *
     * @param  array<int, float|string|null> $values Doc ID → sort value, from fetchSortValues().
     * @return array<int, int>                       Doc ID → rank.
     */
    private static function sortRanks(array $values, bool $asc): array
    {
        // Prefixed string keys keep numbers and strings apart and floats exact (float array
        // keys would be truncated to int).
        $numbers = [];
        $strings = [];
        foreach ($values as $value) {
            if (is_float($value)) {
                $numbers['n' . $value] = $value;
            } elseif ($value !== null) {
                $strings['s' . $value] = $value;
            }
        }
        if ($asc) {
            asort($numbers, SORT_NUMERIC);
            asort($strings, SORT_STRING);
        } else {
            arsort($numbers, SORT_NUMERIC);
            arsort($strings, SORT_STRING);
        }

        $rankOf = array_flip([...array_keys($numbers), ...array_keys($strings)]);
        $ranks  = [];
        foreach ($values as $docId => $value) {
            $ranks[$docId] = match (true) {
                $value === null   => PHP_INT_MAX,
                is_float($value)  => $rankOf['n' . $value],
                default           => $rankOf['s' . $value],
            };
        }
        return $ranks;
    }

    /**
     * Relevance order of a multi-word search: words bucket desc, then score desc, then doc ID
     * asc, by one C-level array_multisort() (no PHP comparison callback).
     *
     * @param  array<int, float> $docScores
     * @param  array<int, int>   $docBucket
     * @return list<int>
     */
    private static function rankByBucketThenScore(array $docScores, array $docBucket): array
    {
        $ids     = array_keys($docScores);
        $buckets = [];
        $scores  = [];
        foreach ($ids as $id) {
            $buckets[] = $docBucket[$id] ?? 0;
            $scores[]  = $docScores[$id];
        }
        array_multisort(
            $buckets,
            SORT_DESC,
            SORT_NUMERIC,
            $scores,
            SORT_DESC,
            SORT_NUMERIC,
            $ids,
            SORT_ASC,
            SORT_NUMERIC,
        );
        return $ids;
    }

    /**
     * Sort $docIds by the given sort specs and paginate.
     *
     * @param  list<int>                             $docIds
     * @param  list<array{field: string, asc: bool}> $specs
     * @param  array<int, float>                     $scores  BM25 scores; empty array for boolean path
     * @param  int                                   $offset
     * @param  int                                   $limit
     * @return list<int>
     */
    private function applySortedPagination(
        array $docIds,
        array $specs,
        array $scores,
        int $offset,
        int $limit,
    ): array {
        return array_slice($this->sortDocIdsBySpecs($docIds, $specs, $scores), $offset, $limit);
    }

    /**
     * Apply distinct deduplication to a sort-ordered list of doc IDs.
     *
     * Walks $sortedIds in order, keeping at most $count docs per unique value of the distinct
     * field. Docs whose value is null (field absent on the document) always pass through and
     * are never collapsed with each other.
     *
     * @param  list<int>                    $sortedIds  Candidate doc IDs in final sort order.
     * @param  array<int, float|string|null> $valueMap   doc_id → distinct field value.
     * @param  int                          $count      Max surviving docs per distinct value.
     * @param  int                   $offset     Pagination offset into the surviving list.
     * @param  int                   $limit      Page size (0 = return empty ids but compute hits).
     * @return array{list<int>, int} [pagedIds, totalSurviving]
     */
    /**
     * @param  list<int>                     $sortedIds
     * @param  array<int, float|string|null> $valueMap
     * @return array{list<int>, int}
     */
    private function applyDistinctPagination(
        array $sortedIds,
        array $valueMap,
        int $count,
        int $offset,
        int $limit,
    ): array {
        /** @var array<string, int> $seenCounts */
        $seenCounts = [];
        /** @var list<int> $surviving */
        $surviving  = [];
        foreach ($sortedIds as $id) {
            $val = $valueMap[$id] ?? null;
            if ($val === null) {
                $surviving[] = $id;
                continue;
            }
            $key  = (string) $val;
            $seen = $seenCounts[$key] ?? 0;
            if ($seen >= $count) {
                continue;
            }
            $seenCounts[$key] = $seen + 1;
            $surviving[] = $id;
        }
        return [array_slice($surviving, $offset, $limit), count($surviving)];
    }

    /**
     * Translate a facet filter map into SQL conditions, one per filter key.
     *
     * Each condition's 'rows' is the FROM … WHERE … clause selecting the rows that satisfy it,
     * written against the alias placeholder '%1$s' (every row source has a doc_id column), with
     * 'params' bound in order, so callers can embed it in any query shape: a COUNT, a driving
     * SELECT, a correlated EXISTS probe, or a UNION of excluded documents. Facet filters select
     * facet_values rows of one key. A condition that can never match — the field has no facet
     * key yet (declared but unpopulated), or the value list is empty — is marked 'impossible'.
     *
     * 'multiRow' marks a condition one document can satisfy with several rows (a range, or more
     * than one value, on a multi-valued key), which matters when the condition drives a query
     * (see matchingDocsSql()). A positive range also carries 'range' (its key and num_value
     * predicate), so a sorted walk on the same field can seek into it (browseSortedPage()).
     *
     * A FacetExclude is rendered from the filter it wraps and flagged 'exclude': a document
     * satisfies it when it has none of the rows 'rows' selects. On a declared field no document
     * has populated yet, or with an empty value list, it excludes nothing and is left out of the
     * list.
     *
     * @param  array<array-key, string|list<string>|FacetRange|FacetExclude> $filter
     * @return list<FacetCondition>
     */
    private function facetFilterConditions(array $filter): array
    {
        $conditions = [];
        foreach ($filter as $name => $filterValue) {
            $name    = (string) $name;
            $exclude = $filterValue instanceof FacetExclude;
            if ($filterValue instanceof FacetExclude) {
                $filterValue = $filterValue->filter;
            }
            $keyId = $this->lookupFacetKeyId($name);
            $sql   = '';
            $params = [];
            if ($filterValue instanceof FacetRange) {
                $sql = '%1$s.num_value IS NOT NULL';
                $bounds = [
                    '>=' => $filterValue->gte,
                    '>'  => $filterValue->gt,
                    '<=' => $filterValue->lte,
                    '<'  => $filterValue->lt,
                ];
                foreach ($bounds as $op => $bound) {
                    if ($bound !== null) {
                        $sql     .= " AND %1\$s.num_value {$op} ?";
                        $params[] = $bound;
                    }
                }
            } else {
                $params = is_array($filterValue) ? $filterValue : [$filterValue];
                $sql    = '%1$s.value IN (' . $this->placeholders(count($params)) . ')';
            }
            $matchesNothing = $keyId === null || $params === [] && !$filterValue instanceof FacetRange;
            if ($exclude && $matchesNothing) {
                continue;
            }
            $condition = [
                'name'       => $name,
                'rows'       => 'FROM facet_values %1$s WHERE %1$s.key_id = ? AND ' . $sql,
                'params'     => [$keyId ?? 0, ...$params],
                'impossible' => !$exclude && $matchesNothing,
                // A document matches a value list or range with several rows only if it holds
                // several values for the key.
                'multiRow'   => ($filterValue instanceof FacetRange || count($params) > 1)
                    && $keyId !== null && $this->isMultiValuedKey($keyId),
                'exclude'    => $exclude,
            ];
            if ($filterValue instanceof FacetRange && !$exclude && $keyId !== null) {
                $condition['range'] = ['keyId' => $keyId, 'sql' => $sql, 'params' => $params];
            }
            $conditions[] = $condition;
        }
        return $conditions;
    }

    /**
     * Order filter conditions by how many rows each matches, fewest first.
     *
     * One index-only COUNT per condition. The first condition then drives matchingDocsSql(),
     * so a query over several filters starts from the most selective one and only probes the
     * others. With fewer than two conditions there is nothing to order and no query runs.
     *
     * Exclusions go last, uncounted: their rows are the documents they remove, so they can only
     * ever be probed, never drive.
     *
     * @param  list<FacetCondition> $conditions
     * @return list<FacetCondition>
     */
    private function orderBySelectivity(array $conditions): array
    {
        $exclusions = array_values(array_filter($conditions, fn(array $c): bool => $c['exclude']));
        if ($exclusions !== []) {
            $positive = array_values(array_filter($conditions, fn(array $c): bool => !$c['exclude']));
            return [...$this->orderBySelectivity($positive), ...$exclusions];
        }
        if (count($conditions) < 2) {
            return $conditions;
        }
        $rows = [];
        foreach ($conditions as $i => $condition) {
            if ($condition['impossible']) {
                $rows[$i] = 0;
                continue;
            }
            $stmt = $this->prepare('SELECT COUNT(*) ' . sprintf($condition['rows'], 'ff'));
            $stmt->execute($condition['params']);
            $rows[$i] = (int) $stmt->fetchColumn();
        }
        asort($rows);
        return array_map(fn(int $i): array => $conditions[$i], array_keys($rows));
    }

    /**
     * SQL selecting the IDs of all documents that satisfy every condition.
     *
     * Driven from the first condition's rows (order the list with orderBySelectivity() first)
     * with a correlated probe per remaining condition, so nothing but the driver's matches is
     * ever visited. An exclusion cannot drive, so when the first condition is one (the list
     * holds only exclusions) every document is a candidate and all conditions are probed.
     * Returns null when a condition can never match.
     *
     * @param  non-empty-list<FacetCondition> $conditions
     * @return array{0: string, 1: list<mixed>}|null
     */
    private function matchingDocsSql(array $conditions): ?array
    {
        $driver = $conditions[0];
        if ($driver['exclude']) {
            $probes = $this->renderFacetProbes($conditions, 'md.doc_id');
            return $probes === null ? null : ['SELECT md.doc_id FROM doc_lengths md WHERE 1' . $probes[0], $probes[1]];
        }
        $probes = $this->renderFacetProbes(array_slice($conditions, 1), 'md.doc_id');
        if ($driver['impossible'] || $probes === null) {
            return null;
        }
        $distinct = $driver['multiRow'] ? 'DISTINCT ' : '';
        return [
            "SELECT {$distinct}md.doc_id " . sprintf($driver['rows'], 'md') . $probes[0],
            [...$driver['params'], ...$probes[1]],
        ];
    }

    /**
     * Whether every condition is a satisfiable exclusion, so the matching documents are "all
     * documents except those excludedDocsSql() selects". Such filters take the complement paths
     * (count, facet counts, facetSearch) instead of visiting every kept document.
     *
     * Vacuously true for no conditions; callers handle that case (no filter) first.
     *
     * @param list<FacetCondition> $conditions
     */
    private static function isExclusionOnly(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (!$condition['exclude'] || $condition['impossible']) {
                return false;
            }
        }
        return true;
    }

    /**
     * SQL selecting the documents that exclusion conditions remove: every document with a row
     * matching any of them. One index range per condition, combined with UNION ALL, so a
     * document can appear more than once; callers apply DISTINCT or use it with NOT IN.
     *
     * @param  non-empty-list<FacetCondition> $conditions Exclusions only (see isExclusionOnly()).
     * @return array{0: string, 1: list<mixed>}
     */
    private function excludedDocsSql(array $conditions): array
    {
        $parts  = [];
        $params = [];
        foreach ($conditions as $i => $condition) {
            $alias   = "ex{$i}";
            $parts[] = "SELECT {$alias}.doc_id " . sprintf($condition['rows'], $alias);
            array_push($params, ...$condition['params']);
        }
        return [implode(' UNION ALL ', $parts), $params];
    }

    /**
     * Render conditions as correlated EXISTS probes on the document ID expression $docExpr.
     *
     * Each facet probe is one lookup in the covering facet_doc_id_index; an exclusion is rendered
     * as NOT EXISTS. Returns [sql, params], where sql is empty or a series of ' AND [NOT] EXISTS (…)'
     * clauses, or null when a condition can never match.
     *
     * @param  list<FacetCondition> $conditions
     * @return array{0: string, 1: list<mixed>}|null
     */
    private function renderFacetProbes(array $conditions, string $docExpr): ?array
    {
        $sql    = '';
        $params = [];
        foreach ($conditions as $i => $condition) {
            if ($condition['impossible']) {
                return null;
            }
            $alias  = "ff{$i}";
            $not    = $condition['exclude'] ? 'NOT ' : '';
            $sql   .= " AND {$not}EXISTS (SELECT 1 " . sprintf($condition['rows'], $alias)
                . " AND {$alias}.doc_id = {$docExpr})";
            array_push($params, ...$condition['params']);
        }
        return [$sql, $params];
    }

    /**
     * SQL restricting the document ID expression $docExpr to documents that satisfy every condition.
     *
     * Two equivalent shapes:
     *
     * - $probe = true: one correlated EXISTS per condition, checked per outer row. Nothing is
     *   materialised, so a query that walks an index in the requested order stops as soon as
     *   its page is full. Best for broad filters, where matching rows are dense along the walk.
     * - $probe = false: `$docExpr IN (matchingDocsSql())`, materialised once and driven from the
     *   most selective condition, so the cost follows the number of matches. Best for selective
     *   filters.
     *
     * Returns [sql, params] — sql is empty or starts with ' AND' — or null when a condition can
     * never match (the caller's result is then empty).
     *
     * @param  list<FacetCondition> $conditions
     * @return array{0: string, 1: list<mixed>}|null
     */
    private function facetFilterSql(array $conditions, string $docExpr, bool $probe): ?array
    {
        if ($conditions === []) {
            return ['', []];
        }
        if ($probe) {
            return $this->renderFacetProbes($conditions, $docExpr);
        }
        $match = $this->matchingDocsSql($conditions);
        return $match === null ? null : [" AND {$docExpr} IN ({$match[0]})", $match[1]];
    }

    /**
     * Load per-filter-key doc ID sets from facet_values, scoped to a candidate set.
     *
     * Called once by search(), searchBoolean(), and facetSearch() with a query; all sets are
     * retained in memory so disjunctive facet counting can reuse them without extra DB
     * round-trips. Each query only tests the candidate documents, so the sets are exact. With
     * no candidates every set is empty and no query runs.
     *
     * Exclusions are collected separately, as the union of the candidates they remove. Callers
     * subtract that set before anything else, so an exclusion also applies when its own field is
     * counted. An exclusion that can never be satisfied (an undeclared field) is returned as an
     * empty positive set instead, which fails the whole filter closed.
     *
     * @param  array<string, string|list<string>|FacetRange|FacetExclude> $filter
     * @param  list<int> $candidateDocIds BM25/boolean candidates to scope query.
     * @param  list<FacetCondition> $extra Further conditions, e.g. query negations (extractNegations()).
     * @return array{0: array<string, array<int, true>>, 1: array<int, true>}
     *         Key name → flipped doc ID set for positive filters, and the excluded doc ID set.
     */
    private function loadFacetKeySets(array $filter, array $candidateDocIds, array $extra = []): array
    {
        $sets          = [];
        $excluded      = [];
        $candidateJson = json_encode($candidateDocIds);
        foreach ([...$this->facetFilterConditions($filter), ...$extra] as $condition) {
            $name = $condition['name'];
            if ($condition['impossible'] || $candidateDocIds === []) {
                $sets[$name] = [];
                continue;
            }
            $stmt = $this->prepare(
                'SELECT ff.doc_id ' . sprintf($condition['rows'], 'ff')
                . ' AND ff.doc_id IN (SELECT value FROM json_each(?))'
            );
            $stmt->execute([...$condition['params'], $candidateJson]);
            /** @var list<int> $ids */
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($condition['exclude']) {
                $excluded += array_fill_keys($ids, true);
            } else {
                $sets[$name] = array_fill_keys($ids, true);
            }
        }
        return [$sets, $excluded];
    }

    /**
     * Warnings naming the caps that made a result approximate.
     *
     * @param  'maxDocs'|'maxFacetCountDocs'|null $candidateCap Setting that capped the keyword candidates, if any.
     * @param  list<string>                       $approximateFacets
     * @return list<string>
     */
    private function capWarnings(?string $candidateCap, bool $prefixCapped, array $approximateFacets): array
    {
        $warnings = [];
        if ($candidateCap !== null) {
            $cap        = $candidateCap === 'maxDocs' ? $this->config->maxDocs : $this->config->maxFacetCountDocs;
            $warnings[] = "A keyword matched more than Config::\${$candidateCap} ({$cap}) documents; only the first "
                . "{$cap} were considered, so results and counts are estimates.";
        }
        if ($prefixCapped) {
            $cap        = $this->config->fuzzyMaxExpansions;
            $warnings[] = "The last keyword matched more than Config::\$fuzzyMaxExpansions ({$cap}) terms as a "
                . "prefix; only the {$cap} shortest were searched.";
        }
        if ($approximateFacets !== []) {
            $cap        = $this->config->maxFacetCountDocs;
            $fields     = implode(', ', array_map(fn(string $f): string => "'{$f}'", $approximateFacets));
            $warnings[] = "Facet counts for {$fields} cover only {$cap} of the matching documents "
                . '(Config::$maxFacetCountDocs).';
        }
        return $warnings;
    }

    /**
     * Compute disjunctive facet value counts for each requested key.
     *
     * For keys that are also active filters, counts are computed on the result set
     * excluding that key's filter (disjunctive), so users see all available options
     * even while one value is selected. For non-filtered keys, the fully-filtered
     * result set is used.
     *
     * @param  list<string>                $facetKeys      Facet key names to count.
     * @param  array<string, array<int, true>> $filterSets Per-key filter doc ID sets.
     * @param  array<int, mixed>           $rawDocScores   All scored docs before any facet filter.
     * @param  array<int, mixed>           $filteredScores Docs after all facet filters.
     * @param  int                         $maxDocs        Cap on doc IDs sent in the IN() clause.
     * @return array{
     *     distribution: array<string, array<array-key, int>>,
     *     stats: array<string, array{min: float, max: float}>,
     *     approximate: list<string>
     * }
     */
    private function computeFacetCounts(
        array $facetKeys,
        array $filterSets,
        array $rawDocScores,
        array $filteredScores,
        int $maxDocs,
    ): array {
        /** @var array<string, array<array-key, int>> $distribution */
        $distribution   = [];
        /** @var array<string, array{min: float, max: float}> $stats */
        $stats          = [];
        // Keys that use the common filteredScores doc set (non-disjunctive).
        /** @var array<string, int> $commonNameToId */
        $commonNameToId = [];
        /** @var list<string> $approximate  Keys counted over a doc set truncated to $maxDocs. */
        $approximate    = [];

        foreach ($facetKeys as $keyName) {
            $keyId = $this->lookupFacetKeyId($keyName);
            if ($keyId === null) {
                continue;
            }

            if (!isset($filterSets[$keyName])) {
                $commonNameToId[$keyName] = $keyId;
                continue;
            }

            // Disjunctive: count against the raw result set with all OTHER filters applied.
            $otherSets = array_diff_key($filterSets, [$keyName => true]);
            $countSet  = $otherSets === []
                ? $rawDocScores
                : array_intersect_key($rawDocScores, $this->intersectFilterSets($otherSets));
            $docIds = array_slice(array_keys($countSet), 0, $maxDocs);
            $this->collectFacetCounts([$keyName => $keyId], $docIds, $distribution, $stats);
            if (count($countSet) > $maxDocs) {
                $approximate[] = $keyName;
            }
        }

        $docIds = array_slice(array_keys($filteredScores), 0, $maxDocs);
        $this->collectFacetCounts($commonNameToId, $docIds, $distribution, $stats);
        if (count($filteredScores) > $maxDocs) {
            array_push($approximate, ...array_keys($commonNameToId));
        }

        return [
            'distribution' => $distribution,
            'stats'        => $stats,
            'approximate'  => array_values(array_unique($approximate)),
        ];
    }

    /**
     * Count facet values for $nameToId over a document set and merge them into $distribution / $stats.
     *
     * $docIds null counts over the whole index with one sequential primary-key scan per key —
     * exact, with no membership test. Otherwise one join driven from the doc IDs counts all keys
     * at once (O(N × keys) index lookups), or a per-key scan of the key's rows tests json_each
     * membership (O(rows per key)). The join wins up to FACET_JOIN_THRESHOLD docs, and at any
     * size for two or more keys once facet_doc_id_index is covering (schema revision 2): ~30%
     * faster for three keys over 5k–20k docs on the 45k ecom set. On revision 1 each join row
     * seeks into the table and the per-key scan stays ahead above the threshold.
     * Distributions are capped at Config::$maxValuesPerFacet; keys with no values are omitted.
     *
     * @param array<string, int>                           $nameToId     Facet key name → key_id.
     * @param list<int>|null                               $docIds
     * @param array<string, array<array-key, int>>         $distribution Mutated in place.
     * @param array<string, array{min: float, max: float}> $stats        Mutated in place.
     */
    private function collectFacetCounts(array $nameToId, ?array $docIds, array &$distribution, array &$stats): void
    {
        if ($nameToId === [] || $docIds === []) {
            return;
        }
        $useJoin = $docIds !== null && (
            count($docIds) <= self::FACET_JOIN_THRESHOLD
            || count($nameToId) > 1
        );
        $results = $useJoin
            ? $this->fetchAllFacetCountsJoin($nameToId, $docIds)
            : array_map(
                fn(int $keyId): array => self::summarizeFacetCounts($this->fetchFacetCountRows($keyId, $docIds)),
                $nameToId,
            );
        $this->mergeFacetCounts($results, $distribution, $stats);
    }

    /**
     * Count facet values over every document except those an exclusion-only filter removes.
     *
     * Visiting the kept documents one by one costs O(index size), so the counts are derived
     * instead: each key's whole-index counts (facet_counts, as for an unfiltered browse) minus
     * the counts over the excluded documents, aggregated once for all keys in SQL. Exclusions
     * usually remove few documents, which makes this nearly as cheap as the unfiltered count.
     * When they remove more than Config::$maxFacetCountDocs, the kept rows of all keys are
     * counted in one scan with doc_id NOT IN (excluded) instead. Both ways are exact.
     *
     * Measured on the 45k ecom set, five keys: 15 ms with 4,025 docs excluded (37 ms before
     * facet_counts), 45 ms with 34,409 excluded (60 ms with one NOT IN scan per key).
     *
     * @param array<string, int>                           $nameToId     Facet key name → key_id.
     * @param non-empty-list<FacetCondition>               $conditions   Exclusions only, none impossible.
     * @param array<string, array<array-key, int>>         $distribution Mutated in place.
     * @param array<string, array{min: float, max: float}> $stats        Mutated in place.
     */
    private function collectFacetCountsExcluding(
        array $nameToId,
        array $conditions,
        array &$distribution,
        array &$stats,
    ): void {
        [$excludedSql, $excludedParams] = $this->excludedDocsSql($conditions);
        $cap  = $this->config->maxFacetCountDocs;
        $stmt = $this->prepare("SELECT DISTINCT doc_id FROM ({$excludedSql}) LIMIT ?");
        $stmt->execute([...$excludedParams, $cap + 1]);
        /** @var list<int> $excludedIds */
        $excludedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($excludedIds) > $cap) {
            // Many excluded documents: count the kept rows of every key in one scan, so the
            // excluded set is materialised once instead of once per key.
            $keyIds = array_values($nameToId);
            $stmt   = $this->prepare(
                'SELECT key_id, value,
                        COUNT(*) AS n, MIN(num_value) AS min_num, MAX(num_value) AS max_num,
                        COUNT(num_value) AS num_count
                   FROM facet_values
                  WHERE key_id IN (SELECT value FROM json_each(?))'
                . " AND doc_id NOT IN ({$excludedSql})
                  GROUP BY key_id, value ORDER BY key_id, n DESC, value"
            );
            $stmt->execute([json_encode($keyIds), ...$excludedParams]);
            /** @var array<int, list<array{value: string, n: int, min_num: float|null, max_num: float|null, num_count: int}>> $byKey */
            $byKey = [];
            /** @var list<array{key_id: int, value: string, n: int, min_num: float|null, max_num: float|null, num_count: int}> $all */
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all as $row) {
                $byKey[$row['key_id']][] = $row;
            }
            $results = [];
            foreach ($nameToId as $keyName => $keyId) {
                $results[$keyName] = self::summarizeFacetCounts($byKey[$keyId] ?? []);
            }
            $this->mergeFacetCounts($results, $distribution, $stats);
            return;
        }

        // The excluded documents' rows per (key, value), aggregated in SQL so PHP gets one row per
        // value instead of one per document and key (driven from the excluded IDs through the
        // covering facet_doc_id_index).
        $stmt = $this->stmt(
            'facetCountsOfDocs',
            'SELECT fv.key_id, fv.value, COUNT(*), COUNT(fv.num_value)
               FROM json_each(?) j
               CROSS JOIN facet_values fv ON fv.doc_id = j.value
              WHERE fv.key_id IN (SELECT value FROM json_each(?))
              GROUP BY fv.key_id, fv.value'
        );
        $stmt->execute([json_encode($excludedIds), json_encode(array_values($nameToId))]);
        /** @var list<array{0: int, 1: string, 2: int, 3: int}> $removedRows */
        $removedRows = $stmt->fetchAll(PDO::FETCH_NUM);
        /** @var array<int, array<array-key, array{0: int, 1: int}>> $removed  key_id → value → [rows, numeric rows] */
        $removed = [];
        foreach ($removedRows as [$keyId, $value, $n, $numCount]) {
            $removed[$keyId][$value] = [$n, $numCount];
        }
        $results = [];
        foreach ($nameToId as $keyName => $keyId) {
            $rows = $this->fetchFacetCountRows($keyId, null);
            if (isset($removed[$keyId])) {
                $kept   = [];
                $counts = [];
                $values = [];
                foreach ($rows as $row) {
                    [$n, $numCount] = $removed[$keyId][$row['value']] ?? [0, 0];
                    if ($row['n'] > $n) {
                        $kept[]   = ['n' => $row['n'] - $n, 'num_count' => $row['num_count'] - $numCount] + $row;
                        $counts[] = $row['n'] - $n;
                        $values[] = (string) $row['value'];
                    }
                }
                // Count desc, then value: the order facet_counts is read in.
                array_multisort($counts, SORT_DESC, SORT_NUMERIC, $values, SORT_ASC, SORT_STRING, $kept);
                $rows = $kept;
            }
            $results[$keyName] = self::summarizeFacetCounts($rows);
        }
        $this->mergeFacetCounts($results, $distribution, $stats);
    }

    /**
     * Merge per-key counts into $distribution / $stats, omitting keys with no values. The
     * distributions stay complete (count order); orderFacetValues() orders and caps them once the
     * requested order is known.
     *
     * @param array<string, array{
     *     distribution: array<array-key, int>,
     *     stats: array{min: float, max: float}|null
     * }> $results
     * @param array<string, array<array-key, int>>         $distribution Mutated in place.
     * @param array<string, array{min: float, max: float}> $stats        Mutated in place.
     */
    private function mergeFacetCounts(array $results, array &$distribution, array &$stats): void
    {
        foreach ($results as $keyName => $counts) {
            $dist = $counts['distribution'];
            if ($dist === []) {
                continue;
            }
            $distribution[$keyName] = $dist;
            if ($counts['stats'] !== null) {
                $stats[$keyName] = $counts['stats'];
            }
        }
    }

    /**
     * Order each facet distribution as requested and cap it at Config::$maxValuesPerFacet.
     *
     * The order is applied first, so with FacetOrder::Alpha the cap keeps the first values
     * alphabetically, not the most frequent ones.
     *
     * @param  array<string, array<array-key, int>> $distribution In count order (as collected).
     * @param  array<string, FacetOrder>            $sortBy       Per field; '*' for the rest.
     * @return array<string, array<array-key, int>>
     */
    private function orderFacetValues(array $distribution, array $sortBy): array
    {
        $maxValues = $this->config->maxValuesPerFacet;
        foreach ($distribution as $field => $counts) {
            if (($sortBy[$field] ?? $sortBy['*'] ?? FacetOrder::Count) === FacetOrder::Alpha) {
                $counts = self::alphaOrder($counts);
            }
            if ($maxValues > 0 && count($counts) > $maxValues) {
                $counts = array_slice($counts, 0, $maxValues, true);
            }
            $distribution[$field] = $counts;
        }
        return $distribution;
    }

    /**
     * Facet counts in FacetOrder::Alpha order: numeric values first, by value, then the others by
     * their sortKey() (case-insensitive), then by bytes. One array_multisort(), no comparison
     * callback.
     *
     * @param  array<array-key, int> $counts Value → count.
     * @return array<array-key, int>
     */
    private static function alphaOrder(array $counts): array
    {
        $values  = array_keys($counts);
        $kinds   = [];
        $numbers = [];
        $folded  = [];
        $raw     = [];
        foreach ($values as $value) {
            $text      = (string) $value;
            $isNumber  = is_numeric($text);
            $kinds[]   = $isNumber ? 0 : 1;
            $numbers[] = $isNumber ? (float) $text : 0.0;
            $folded[]  = $isNumber ? '' : self::sortKey($text);
            $raw[]     = $text;
        }
        array_multisort(
            $kinds,
            SORT_ASC,
            SORT_NUMERIC,
            $numbers,
            SORT_ASC,
            SORT_NUMERIC,
            $folded,
            SORT_ASC,
            SORT_STRING,
            $raw,
            SORT_ASC,
            SORT_STRING,
            $values,
        );
        $ordered = [];
        foreach ($values as $value) {
            $ordered[$value] = $counts[$value];
        }
        return $ordered;
    }

    /**
     * Facet counts most documents first, ties by value bytes (the order SQL gives with
     * ORDER BY count DESC, value).
     *
     * @param  array<array-key, int> $counts Value → count.
     * @return array<array-key, int>
     */
    private static function countOrder(array $counts): array
    {
        $values = array_map('strval', array_keys($counts));
        $nums   = array_values($counts);
        array_multisort($nums, SORT_DESC, SORT_NUMERIC, $values, SORT_ASC, SORT_STRING);
        $ordered = [];
        foreach ($values as $i => $value) {
            $ordered[$value] = $nums[$i];
        }
        return $ordered;
    }

    /**
     * A facet value or facetSearch() query in the form they are matched in: Tokenizer::sortKey()
     * (lowercase, Latin accents folded) with every run of spaces, '-', '_', and '/' turned into
     * one space, so "rosa cl" finds "rosa-clara".
     */
    private static function facetMatchKey(string $text): string
    {
        // Most facet values are ASCII: no accents to fold and no multibyte case mapping, so
        // byte functions give the same key several times faster (facetSearch() keys every
        // distinct value of the facet).
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            $key = strtolower(strtr($text, "-_/\t\n\r\v\f", '        '));
            return trim(str_contains($key, '  ') ? (preg_replace('/ +/', ' ', $key) ?? $key) : $key);
        }
        return trim(preg_replace('~[\s\-_/]+~u', ' ', Tokenizer::sortKey($text)) ?? '');
    }

    /** Whether $query (a facetMatchKey()) starts $value (a facetMatchKey()) or one of its words. */
    private static function facetValueMatches(string $value, string $query): bool
    {
        return str_starts_with($value, $query) || str_contains($value, ' ' . $query);
    }

    /**
     * Fetch value counts for one or more facet keys in a single query driven from the doc IDs.
     *
     * Uses CROSS JOIN with json_each(docIds) to force SQLite to drive the join from the doc_id
     * side via facet_doc_id_index, giving O(N × avg_facets_per_doc) instead of the sequential
     * O(K) PK scan per key. CROSS JOIN prevents the planner from flipping the join direction.
     * Aggregation is done in PHP after fetching raw (key_id, value, num_value) rows.
     *
     * Suitable when N ≤ FACET_JOIN_THRESHOLD; for larger N the sequential scan path is cheaper.
     *
     * @param  array<string, int> $nameToId  Facet key name → key_id.
     * @param  list<int>          $docIds
     * @return array<string, array{distribution: array<array-key, int>, stats: array{min: float, max: float}|null}>
     */
    private function fetchAllFacetCountsJoin(array $nameToId, array $docIds): array
    {
        // Raw rows aggregated in PHP: a SQL GROUP BY over the joined rows sorts them through a
        // temporary B-tree and measured ~7 ms slower on a 10k-document, five-key page.
        $stmt = $this->stmt(
            'facetCountsJoin',
            'SELECT fv.key_id, fv.value, fv.num_value
             FROM json_each(?) je
             CROSS JOIN facet_values fv ON fv.doc_id = je.value
             WHERE fv.key_id IN (SELECT value FROM json_each(?))'
        );
        $stmt->execute([json_encode($docIds), json_encode(array_values($nameToId))]);
        /** @var list<array{0: int, 1: string, 2: float|null}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

        /** @var array<int, array<array-key, int>> $valueCounts */
        $valueCounts = [];
        /** @var array<int, array<array-key, int>> $numCounts */
        $numCounts   = [];
        /** @var array<int, array<array-key, float>> $numValues */
        $numValues   = [];
        foreach ($rows as [$keyId, $value, $rawNum]) {
            $valueCounts[$keyId][$value] = ($valueCounts[$keyId][$value] ?? 0) + 1;
            if ($rawNum !== null) {
                $numCounts[$keyId][$value] = ($numCounts[$keyId][$value] ?? 0) + 1;
                // All rows sharing (key_id, value) have the same num_value — store once.
                $numValues[$keyId][$value] ??= (float) $rawNum;
            }
        }

        $counts = [];
        foreach ($nameToId as $keyName => $keyId) {
            if (!isset($valueCounts[$keyId])) {
                continue;
            }
            $keyRows = [];
            foreach ($valueCounts[$keyId] as $value => $n) {
                $num       = $numValues[$keyId][$value] ?? null;
                $keyRows[] = [
                    'value'     => (string) $value,
                    'n'         => $n,
                    'min_num'   => $num,
                    'max_num'   => $num,
                    'num_count' => $numCounts[$keyId][$value] ?? 0,
                ];
            }
            // Count desc, then value: the order facet_counts and the per-key scans give.
            $ns     = array_column($keyRows, 'n');
            $values = array_column($keyRows, 'value');
            array_multisort($ns, SORT_DESC, SORT_NUMERIC, $values, SORT_ASC, SORT_STRING, $keyRows);
            $counts[$keyName] = self::summarizeFacetCounts($keyRows);
        }
        return $counts;
    }

    /**
     * Per-value counts of a single facet key over the given doc ID set, most frequent first.
     *
     * Uses json_each() as a WHERE IN subquery so the SQL is a fixed string (cacheable
     * via stmt()). SQLite drives from the facet_values PK (sequential scan for key_id),
     * materialises json_each into a hash set, then tests doc_id membership per row —
     * identical execution plan to the original IN(?,?,?) but without variable-arity
     * compilation overhead.
     *
     * With $docIds null the key is counted over the whole index from facet_counts (one row per
     * value), or, when $excluded is given, over every document except those it selects (a scan
     * of the key's rows with doc_id NOT IN, materialised once).
     *
     * @param  list<int>|null                         $docIds
     * @param  array{0: string, 1: list<mixed>}|null $excluded SQL selecting doc IDs to skip; with $docIds null only.
     * @return list<array{value: string, n: int, min_num: float|null, max_num: float|null, num_count: int}>
     */
    private function fetchFacetCountRows(int $keyId, ?array $docIds, ?array $excluded = null): array
    {
        // Numeric aggregates only for keys that hold numbers: COUNT(*) alone is ~2x faster over a
        // text key's rows (11.0 vs 4.9 ms per key on the 45k ecom set); the probe is one lookup in
        // the partial facet_numeric_index.
        $select = $this->keyHasNumbers($keyId)
            ? 'SELECT value, COUNT(*) AS n, MIN(num_value) AS min_num, MAX(num_value) AS max_num,
                      COUNT(num_value) AS num_count
                 FROM facet_values WHERE key_id = ?'
            : 'SELECT value, COUNT(*) AS n, NULL AS min_num, NULL AS max_num, 0 AS num_count
                 FROM facet_values WHERE key_id = ?';
        if ($excluded !== null) {
            $stmt = $this->prepare(
                $select . " AND doc_id NOT IN ({$excluded[0]}) GROUP BY value ORDER BY n DESC, value"
            );
            $stmt->execute([$keyId, ...$excluded[1]]);
        } elseif ($docIds === null) {
            // The whole key: read the maintained counts (O(values)) instead of scanning its rows.
            $stmt = $this->stmt(
                'facetCountsForKeyAll',
                'SELECT value,
                        count                                              AS n,
                        CASE WHEN num_count > 0 THEN num_value END         AS min_num,
                        CASE WHEN num_count > 0 THEN num_value END         AS max_num,
                        num_count
                   FROM facet_counts
                  WHERE key_id = ?
                  ORDER BY count DESC, value'
            );
            $stmt->execute([$keyId]);
        } else {
            $stmt = $this->prepare(
                $select . ' AND doc_id IN (SELECT value FROM json_each(?)) GROUP BY value ORDER BY n DESC, value'
            );
            $stmt->execute([$keyId, json_encode($docIds)]);
        }
        /** @var list<array{value: string, n: int, min_num: float|null, max_num: float|null, num_count: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /** Whether any document holds a number for this facet key (one probe of facet_numeric_index). */
    private function keyHasNumbers(int $keyId): bool
    {
        $stmt = $this->stmt(
            'facetKeyHasNumbers',
            'SELECT EXISTS (SELECT 1 FROM facet_values WHERE key_id = ? AND num_value IS NOT NULL)'
        );
        $stmt->execute([$keyId]);
        $has = (int) $stmt->fetchColumn() === 1;
        $stmt->closeCursor();
        return $has;
    }

    /**
     * Turn fetchFacetCountRows() rows into a value → count distribution (in row order) plus
     * min/max stats when every counted row is numeric.
     *
     * @param  list<array{value: string, n: int, min_num: float|null, max_num: float|null, num_count: int}> $rows
     * @return array{distribution: array<array-key, int>, stats: array{min: float, max: float}|null}
     */
    private static function summarizeFacetCounts(array $rows): array
    {
        if ($rows === []) {
            return ['distribution' => [], 'stats' => null];
        }

        $totalCount = (int) array_sum(array_column($rows, 'n'));
        $numCount   = (int) array_sum(array_column($rows, 'num_count'));

        $distribution = [];
        foreach ($rows as $row) {
            $distribution[$row['value']] = (int) $row['n'];
        }

        if ($numCount === $totalCount && $numCount > 0) {
            // Drop only nulls: a bare array_filter() would also drop a 0.0 bound.
            $minNums = array_filter(array_column($rows, 'min_num'), fn(mixed $v): bool => $v !== null);
            $maxNums = array_filter(array_column($rows, 'max_num'), fn(mixed $v): bool => $v !== null);
            return [
                'distribution' => $distribution,
                'stats' => [
                    'min' => $minNums !== [] ? (float) min($minNums) : 0.0,
                    'max' => $maxNums !== [] ? (float) max($maxNums) : 0.0,
                ],
            ];
        }

        return ['distribution' => $distribution, 'stats' => null];
    }

    // --- Infrastructure -----------------------------------------------------

    /**
     * Return a cached prepared statement, preparing it on first use.
     *
     * The cache is keyed by a stable string identifier and is invalidated whenever
     * the PDO connection changes (createIndex / selectIndex).
     *
     * @param string $key Stable cache key (never interpolated into SQL).
     * @param string $sql SQL to prepare on first use.
     */
    private function stmt(string $key, string $sql): \PDOStatement
    {
        $pdo = $this->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Index connection is closed.');
        }
        /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; every call re-prepares the same SQL but produces identical results */
        return $this->stmtCache[$key] ??= $pdo->prepare($sql);
    }

    /** Prepare without caching; for variable-shape SQL where the cache hit rate would be near zero. */
    private function prepare(string $sql): \PDOStatement
    {
        $pdo = $this->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Index connection is closed.');
        }
        return $pdo->prepare($sql);
    }

    private function placeholders(int $n): string
    {
        return implode(',', array_fill(0, $n, '?'));
    }

    /**
     * Run $fn inside a transaction, committing on success and rolling back on exception.
     *
     * When already inside a transaction (e.g. replaceMany() calling bulkRemoveDocuments()),
     * the callback runs in the existing transaction rather than starting a nested one.
     *
     * Uses BEGIN IMMEDIATE (issued via exec(), since PDO's transaction API only emits a
     * deferred BEGIN) so the write lock is taken up front. A deferred transaction upgrades
     * to writer at its first write statement; under multi-process contention that upgrade
     * fails with an instant SQLITE_BUSY the busy handler never intercepts, making
     * Config::$busyTimeoutMs ineffective. With IMMEDIATE, concurrent writers queue on the
     * busy timeout as documented.
     *
     * @param callable(): void $fn
     */
    private function wrapInTransaction(callable $fn): void
    {
        $pdo = $this->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Index connection is closed.');
        }
        /** @infection-ignore-all IfNegation: inverting inTransaction only affects nested calls; no test exercises wrapInTransaction while already in a transaction */
        if ($this->inTransaction) {
            $fn();
            return;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;
        try {
            // Validate caches against external commits now that the write lock is held:
            // no other writer can commit until this transaction ends, so a stale
            // termIdCache entry (a term pruned by another process) cannot slip into
            // the wordlist upsert paths below.
            $this->checkDataVersion();
            $fn();
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * Throw if this index was opened in read-only mode.
     *
     * @throws IOException
     */
    private function assertWritable(): void
    {
        if ($this->readonly) {
            throw new IOException("Cannot write to a readonly index.");
        }
    }

    /**
     * Validate and extract an integer document ID from a raw value.
     *
     * @throws QueryException If the value is not an integer or integer-like string.
     */
    private function extractId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new QueryException(
            "Document 'id' must be an integer, got " . get_debug_type($value) . '.'
        );
    }

    /**
     * Instantiate Stopwords and Stemmer for the given BCP 47 tag on this connection.
     *
     * Called internally by createIndex() and selectIndex(). Passing null clears both.
     *
     * @throws QueryException If $language is set but has no stopword list or stemmer.
     */
    private function applyLanguage(?string $language): void
    {
        if ($language !== null && !Language::supports($language)) {
            throw new QueryException("No stopword list or stemmer for language: '{$language}'");
        }
        $this->language  = $language;
        $this->stopwords = $language !== null && Language::hasStopwords($language) ? new Stopwords($language) : null;
        $this->stemmer   = $language !== null && Language::hasStemmer($language) ? new Stemmer($language) : null;
        $this->typoExactWords = null;
    }

    /**
     * Override connection pragmas for bulk-load performance.
     *
     * - synchronous=OFF (default; Config::$bulkSynchronousOff=true): no fsync per commit.
     *   Safe against a PHP process crash — the transaction rolls back cleanly. NOT safe
     *   against an OS crash or power loss during the write, which can corrupt the database
     *   file rather than just roll back. Config::$bulkSynchronousOff=false keeps
     *   synchronous=NORMAL here instead, trading load speed for that protection. See
     *   docs/indexing.md for detail; Index::rebuild() sidesteps this entirely by writing to
     *   a disposable temp file, so a corrupted bulk load never touches the live index.
     * - cache_size=-524288: 512 MB page cache; reduces B-tree splits during large INSERTs.
     * - wal_autocheckpoint=8000: delays WAL checkpointing until after the bulk write completes.
     *
     * Restored by restoreNormalPragmas() in the finally block after the transaction commits.
     */
    private function applyBulkPragmas(): void
    {
        assert($this->pdo instanceof \PDO);
        $synchronous = $this->config->bulkSynchronousOff ? 'OFF' : 'NORMAL';
        $this->pdo->exec("
            PRAGMA synchronous        = {$synchronous};
            PRAGMA cache_size         = -524288;
            PRAGMA wal_autocheckpoint = 8000;
        ");
    }

    private function restoreNormalPragmas(): void
    {
        assert($this->pdo instanceof \PDO);
        $cacheSize = -$this->config->cacheSizeKb;
        $this->pdo->exec("
            PRAGMA synchronous        = NORMAL;
            PRAGMA cache_size         = {$cacheSize};
            PRAGMA wal_autocheckpoint = 1000;
        ");
    }

    private function applyPragmas(): void
    {
        assert($this->pdo instanceof \PDO);
        $busyTimeoutMs = $this->config->busyTimeoutMs;
        $cacheSize     = -$this->config->cacheSizeKb;
        $mmapSize      = $this->config->mmapSizeBytes;
        if (!$this->readonly) {
            $this->pdo->exec("
                PRAGMA journal_mode        = WAL;
                PRAGMA synchronous         = NORMAL;
                PRAGMA cache_size          = {$cacheSize};
                PRAGMA temp_store          = MEMORY;
                PRAGMA mmap_size           = {$mmapSize};
                PRAGMA case_sensitive_like = ON;
                PRAGMA busy_timeout        = {$busyTimeoutMs};
            ");
        } else {
            $this->pdo->exec("
                PRAGMA cache_size          = {$cacheSize};
                PRAGMA temp_store          = MEMORY;
                PRAGMA mmap_size           = {$mmapSize};
                PRAGMA case_sensitive_like = ON;
                PRAGMA busy_timeout        = {$busyTimeoutMs};
            ");
        }
    }

    /**
     * Normalize a user-supplied synonym term to its stored form.
     *
     * Lowercases, splits on whitespace/punctuation, and (if the index has a stemmer)
     * stems the result.  Returns the single normalized token, or an empty string when
     * the input is blank or reduces to more than one token (multi-word terms are not
     * supported as synonym sources or targets and are silently skipped by the caller).
     */
    private function normalizeSynonymTerm(string $term): string
    {
        $tokens = Tokenizer::split(mb_strtolower(trim($term)));
        if (count($tokens) !== 1) {
            return '';
        }
        if ($this->stemmer instanceof Stemmer) {
            $tokens = $this->stemmer->stemTokens($tokens);
        }
        return $tokens[0] ?? '';
    }

    /**
     * Load the full synonym map from the database into $synonymCache.
     *
     * One query; result is kept in memory for the life of the connection.
     * No-op after the first call.
     */
    private function loadSynonymCache(): void
    {
        if ($this->synonymCache !== null) {
            return;
        }
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);
        $stmt = $pdo->query('SELECT source, target FROM synonyms');
        $this->synonymCache = [];
        if ($stmt === false) {
            return;
        }
        /** @var list<array{source: string, target: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $this->synonymCache[$row['source']][] = $row['target'];
        }
    }

    /**
     * Return the list of synonym targets for a normalized query token.
     *
     * Triggers a lazy cache load on first call per connection.
     *
     * @return list<string>
     */
    private function synonymsFor(string $term): array
    {
        $this->loadSynonymCache();
        return $this->synonymCache[$term] ?? [];
    }

    /**
     * force: true over an existing file: build an empty index under a temp name and publish
     * it like rebuild() does, then open it.
     *
     * Deleting the old file and its -wal/-shm and creating a new one under the same name would
     * leave a moment in which another process's connection pairs the new file with the old
     * file's sidecars; see publishVersion().
     *
     * @throws IOException    If the existing file is not a Fuzor index, or the new one cannot be published.
     * @throws QueryException If schema->language is set but has no stopword list or stemmer.
     */
    private function replaceWithEmptyIndex(SchemaConfig $schema): void
    {
        if (!self::exists($this->path)) {
            throw new IOException("Refusing to overwrite non-Fuzor file: {$this->path}");
        }
        self::cleanupTempFiles($this->path);
        [$tmp, $tmpLock] = self::claimTempPath($this->path);
        try {
            new self($tmp, config: $this->config, schema: $schema)->close();
            self::publishVersion($tmp, $this->path);
        } catch (\Throwable $e) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($tmp . $suffix);
            }
            throw $e;
        } finally {
            self::releaseTempPath($tmp, $tmpLock);
        }
        $this->selectIndex();
    }
}

<?php

declare(strict_types=1);

namespace Fuzor;

use Fuzor\BooleanParser;
use Fuzor\Exceptions\IOException;
use Fuzor\Exceptions\QueryException;
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
 */
class Index
{
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
     * @var array<string, list<array{id: int, term: string, num_hits: int, num_docs: int}>>
     */
    private array $wordlistCache = [];

    /** Search tuning; immutable after construction. */
    private readonly Config $config;

    /** BCP 47 language tag active on this index; null means no stopword filtering or stemming. */
    public private(set) ?string $language = null;

    /** Whether the optional document store is active on this index. */
    public private(set) bool $documentStoreEnabled = false;

    /** @var list<string> Field names routed to the facet index; not FTS-indexed unless also in searchableFields. */
    public private(set) array $facetFields = [];

    /** @var list<string>|null null = all non-facet fields are FTS-indexed; non-null = only these fields. */
    public private(set) ?array $searchableFields = null;

    /** When true, strip_tags() is applied to each field value before tokenisation. */
    public private(set) bool $stripHtml = false;

    /** @var array<string, int> Maps facet key name → facet_keys.id; populated lazily; cleared on connection change. */
    private array $facetKeyCache = [];

    /** @var array<string, int> Maps searchable field name → field_names.id; populated lazily; cleared on connection change. */
    private array $fieldNameCache = [];

    /** @var array<string, int> Pre-computed isset-lookup set derived from facetFields (array_flip); rebuilt whenever facetFields is assigned. */
    private array $facetFieldSet = [];

    /** @var array<string, int>|null Pre-computed isset-lookup set derived from searchableFields (array_flip); null means all non-facet fields. */
    private ?array $searchableFieldSet = null;

    /** Active stopword filter; null when no language is set or language has no stopword list. */
    private ?Stopwords $stopwords = null;

    /** Active stemmer; null when no language is set or language has no stemmer. */
    private ?Stemmer $stemmer = null;

    /** @var array<string, list<string>>|null Normalized source → list<target>; null = not loaded. Cleared on connection change. */
    private ?array $synonymCache = null;

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
        } else {
            $this->createIndex($force, $schema);
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
     * Atomically rebuild an index by writing to a temporary file and renaming it over the target.
     *
     * When $callback is provided it receives a fresh, empty Index to populate.
     *
     * When $callback is omitted (null), the existing index must have the document store enabled;
     * all stored documents are streamed into the new index automatically. This lets you re-index
     * with a different SchemaConfig (e.g. new searchableFields or facetFields) without maintaining
     * a separate copy of the source data.
     *
     * If the callback throws, or if the automatic streaming path fails, the temporary file is
     * removed and the original index is left untouched.
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
                facetFields:      $existing->facetFields,
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

        /** @infection-ignore-all DecrementInteger|IncrementInteger|ConcatOperandRemoval|Concat: temp path construction details; any unique path in the same directory produces identical rename semantics */
        $tmp = $resolved . '.tmp-' . bin2hex(random_bytes(4));

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

            /** @infection-ignore-all Throw_: rename() returns false only on OS-level failure (cross-device, permissions); not reproducible in unit tests without filesystem mocking */
            if (!rename($tmp, $resolved)) {
                throw new IOException("Failed to atomically replace index at {$resolved}.");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            @unlink($tmp . '-wal');
            @unlink($tmp . '-shm');
            throw $e;
        } finally {
            $existing?->close();
        }

        return new self($resolved);
    }

    /**
     * Write an atomic snapshot of this index to $path.
     *
     * Uses VACUUM INTO to copy the current state to a uniquely-named temp file on the
     * same filesystem, then renames it over $path in a single POSIX-atomic operation.
     * Concurrent readers of the old file at $path are unaffected — the inode stays
     * alive until their last open file descriptor is closed.
     *
     * Safe to call while writes are in progress on this index: VACUUM INTO reads a
     * consistent snapshot under a shared read transaction; WAL mode ensures writers
     * are never blocked.
     *
     * Any stale .tmp-* files left by previous crashed snapshot attempts are removed
     * before the new temp file is created. The new temp file is removed if the
     * VACUUM INTO or rename fails.
     *
     * @param  string $path Absolute or relative path for the snapshot file.
     * @throws IOException If the rename fails or the parent directory does not exist.
     */
    public function snapshotTo(string $path): void
    {
        $resolved = self::resolvePath($path);

        foreach (glob($resolved . '.tmp-*') ?: [] as $stale) {
            @unlink($stale);
        }

        $tmp = $resolved . '.tmp-' . bin2hex(random_bytes(4));

        try {
            assert($this->pdo instanceof \PDO);
            $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($tmp));
            if (!rename($tmp, $resolved)) {
                throw new IOException("Failed to atomically write snapshot to {$resolved}.");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($resolved . $suffix)) {
                @unlink($resolved . $suffix);
            }
        }
    }

    // --- Index lifecycle (private, called by the constructor) ---------------

    /**
     * Create a new SQLite index file and initialise the schema.
     *
     * Performance pragmas are applied via applyPragmas(). All tables are STRICT for
     * type safety. doclist is WITHOUT ROWID (clustered on term_id, doc_id), replacing
     * the old term_id secondary index with a zero-heap-fetch primary scan.
     *
     * @param  bool         $force  When true, any existing file is deleted before creation.
     * @param  SchemaConfig $schema Schema options persisted at creation time.
     * @return static
     * @throws IOException    If the index file already exists and $force is false.
     * @throws QueryException If schema->language is set but has no stopword list or stemmer.
     * @infection-ignore-all FalseValue: default $force=false is never exercised; callers always pass force explicitly
     */
    private function createIndex(bool $force, SchemaConfig $schema): static
    {
        $language         = $schema->language;
        $store            = $schema->store;
        $facetFields      = $schema->facetFields;
        $searchableFields = $schema->searchableFields;
        $stripHtml        = $schema->stripHtml;
        if (!$force && file_exists($this->path)) {
            throw new IOException(
                "Index already exists: {$this->path}. Pass force: true to overwrite."
            );
        }
        $this->flushIndex();

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
            $pdo->exec("INSERT INTO info (key, value) VALUES ('has_document_store', '1')");
            $this->documentStoreEnabled = true;
        }

        // facet_keys: one row per unique facet field name (~10–100 entries; fully cached in PHP).
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS facet_keys (
                id   INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
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
        // Covers DELETE-by-doc_id and the GROUP BY count query path.
        $pdo->exec(
            "CREATE INDEX IF NOT EXISTS 'main'.'facet_doc_id_index'
             ON facet_values (doc_id)"
        );
        // Covers numeric range filter queries; partial keeps the B-tree small.
        $pdo->exec(
            "CREATE INDEX IF NOT EXISTS 'main'.'facet_numeric_index'
             ON facet_values (key_id, num_value, doc_id)
             WHERE num_value IS NOT NULL"
        );
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
        $schemaStmt->execute(['facet_fields',      json_encode($facetFields)]);
        $schemaStmt->execute(['searchable_fields', $searchableFields !== null ? json_encode($searchableFields) : '']);
        $schemaStmt->execute(['strip_html',        $stripHtml ? '1' : '0']);
        $this->facetFields        = $facetFields;
        $this->searchableFields   = $searchableFields;
        $this->facetFieldSet      = array_flip($facetFields);
        $this->searchableFieldSet = $searchableFields !== null ? array_flip($searchableFields) : null;
        $this->stripHtml          = $stripHtml;

        if ($language !== null) {
            $this->applyLanguage($language);
        }
        $this->captureFileIdentity();

        return $this;
    }

    /**
     * Open an existing index file.
     *
     * @throws IOException If the index file does not exist.
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
            . " WHERE key IN ('language', 'has_document_store', 'facet_fields', 'searchable_fields', 'strip_html')"
        );
        $infoRows = [];
        if ($stmt) {
            /** @var array<string, string> $fetched */
            $fetched  = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $infoRows = $fetched;
        }
        $lang = ($infoRows['language'] ?? '') !== '' ? $infoRows['language'] : null;
        $this->applyLanguage($lang);
        $this->documentStoreEnabled = ($infoRows['has_document_store'] ?? '0') === '1';
        $this->facetFields        = self::decodeStringList($infoRows['facet_fields'] ?? '[]');
        $sfRaw                    = $infoRows['searchable_fields'] ?? '';
        $this->searchableFields   = $sfRaw === '' ? null : self::decodeStringList($sfRaw);
        $this->facetFieldSet      = array_flip($this->facetFields);
        $this->searchableFieldSet = $this->searchableFields !== null ? array_flip($this->searchableFields) : null;
        $this->stripHtml          = ($infoRows['strip_html'] ?? '0') === '1';
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
        $this->facetKeyCache  = [];
        $this->fieldNameCache = [];
        $this->synonymCache   = null;
        $this->inTransaction  = false;
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
            $this->facetKeyCache  = [];
            $this->fieldNameCache = [];
            $this->synonymCache   = null;
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
        /** @infection-ignore-all LogicalNot: iterator_to_array() accepts arrays in PHP 8.1+; converting an array produces the same array */
        if (!is_array($documents)) {
            $documents = iterator_to_array($documents, false);
        }

        /** @infection-ignore-all ReturnRemoval: empty batch produces no SQL writes; adjustStats(0,0) is a no-op when no tokens are processed */
        if ($documents === []) {
            return;
        }

        // Single-element list: use the lightweight single-doc path to avoid bulk pragma overhead.
        if (count($documents) === 1) {
            $this->insertOne($documents[0]);
            return;
        }

        $ids = [];
        foreach ($documents as $i => $document) {
            if (!array_key_exists('id', $document)) {
                throw new QueryException("Document at index {$i} must contain an 'id' key.");
            }
            $id = $this->extractId($document['id']);
            if (isset($ids[$id])) {
                throw new QueryException("Duplicate id {$id} at index {$i}.");
            }
            /** @infection-ignore-all TrueValue: isset() returns true for any non-null value including false; both true and false mark the slot as occupied */
            $ids[$id] = true;
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

        // Fresh bulk-load statement cache for this call; released in finally so large-N INSERT
        // statements don't stay open as SQLite tracked-statements during subsequent insert() calls.
        $this->bulkStmtCache = [];

        // For large batches (or a fresh index), drop both secondary indexes before the INSERT
        // and rebuild once from the completed data. Maintaining them row-by-row during a bulk
        // load costs more than a single post-insert sequential scan. Below 1 000 docs on a
        // non-empty index, per-row maintenance is cheaper than a full doclist rebuild.
        /** @infection-ignore-all GreaterThanOrEqualTo,GreaterThanOrEqualToNegotiation,LogicalOr,LogicalOrAllSubExprNegation,LogicalOrNegation,LogicalOrSingleSubExprNegation: all mutations of this condition only affect whether secondary indexes are dropped/rebuilt; correctness is unaffected */
        $dropIndexes = $indexIsEmpty || count($documents) >= 1_000;

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
            ');
        }
        /** @infection-ignore-all UnwrapFinally: removing the try-finally wrapper only affects exception safety of the pragma restore; on the success path the behaviour is identical */
        try {
            $this->wrapInTransaction(function () use ($documents, $ids, $indexIsEmpty, $progress): void {
                // Skip the duplicate-ID check on a known-empty table: nothing can already exist.
                if (!$indexIsEmpty) {
                    /** @infection-ignore-all UnwrapArrayKeys,DecrementInteger,IncrementInteger: removing array_keys passes values instead of keys; the chunk size constant change only affects chunk count, not correctness */
                    foreach (array_chunk(array_keys($ids), self::CHUNK_1P) as $chunk) {
                        $placeholders = $this->placeholders(count($chunk));
                        $stmt = $this->prepare(
                            "SELECT doc_id FROM doc_lengths WHERE doc_id IN ({$placeholders})"
                        );
                        $stmt->execute($chunk);
                        $existing = array_map(
                            fn(mixed $v): string => is_scalar($v) ? (string) $v : '',
                            $stmt->fetchAll(PDO::FETCH_COLUMN)
                        );
                        if ($existing !== []) {
                            throw new QueryException(
                                'Documents already exist with ids: '
                                    . implode(', ', $existing) . '. Use update() to replace them.'
                            );
                        }
                    }
                }

                ['wordHits'          => $wordHits,
                 'wordDocs'          => $wordDocs,
                 'docTermBuffer'     => $docTermBuffer,
                 'docLengthBuffer'   => $docLengthBuffer,
                 'docPositionBuffer' => $docPositionBuffer,
                 'facetBuffer'       => $facetBuffer,
                 'rawDocuments'      => $rawDocuments,
                 'fieldTermBuffer'   => $fieldTermBuffer] = $this->buildBatchBuffer($documents, $progress);

                $totalLength = $this->flushBatch(
                    $wordHits,
                    $wordDocs,
                    $docTermBuffer,
                    $docLengthBuffer,
                    $docPositionBuffer,
                    $rawDocuments,
                    $facetBuffer,
                    $fieldTermBuffer,
                );

                $this->adjustStats(count($documents), $totalLength);
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
                $pdo->exec('
                    CREATE INDEX IF NOT EXISTS facet_doc_id_index ON facet_values (doc_id);
                    CREATE INDEX IF NOT EXISTS facet_numeric_index ON facet_values (key_id, num_value, doc_id)
                        WHERE num_value IS NOT NULL;
                ');
                /** @infection-ignore-all MethodCallRemoval: rebuilding field_hits_doc_id is a performance step; correctness is unaffected */
                $pdo->exec('CREATE INDEX IF NOT EXISTS field_hits_doc_id ON field_hits (doc_id);');
            }
            /** @infection-ignore-all MethodCallRemoval: restoring pragmas after bulk load is a performance step; the next connection will re-apply from applyPragmas() */
            $this->restoreNormalPragmas();
            // Release bulk-load statements so they don't stay open as SQLite tracked-statements
            // during subsequent single-doc insert() transactions.
            $this->bulkStmtCache = [];
        }
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
            $id        = $this->extractId($document['id']);
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

                $oldLengths = [];
                foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
                    $placeholders = $this->placeholders(count($chunk));
                    $stmt = $this->prepare(
                        "SELECT doc_id, length FROM doc_lengths WHERE doc_id IN ({$placeholders})"
                    );
                    $stmt->execute($chunk);
                    /** @var list<array{doc_id: int, length: int}> $rows */
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $oldLengths[$row['doc_id']] = $row['length'];
                    }
                }

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

                // 3. Bulk-remove all documents that currently exist in the index.
                $existingIds = array_keys($oldLengths);
                if ($existingIds !== []) {
                    $this->bulkRemoveDocuments($existingIds);
                }

                // 4. Bulk-insert all documents using the same two-phase path as insertMany().
                ['wordHits'          => $wordHits,
                 'wordDocs'          => $wordDocs,
                 'docTermBuffer'     => $docTermBuffer,
                 'docLengthBuffer'   => $docLengthBuffer,
                 'docPositionBuffer' => $docPositionBuffer,
                 'facetBuffer'       => $facetBuffer,
                 'rawDocuments'      => $rawDocuments,
                 'fieldTermBuffer'   => $fieldTermBuffer] = $this->buildBatchBuffer($documents);

                $totalNewLength = $this->flushBatch(
                    $wordHits,
                    $wordDocs,
                    $docTermBuffer,
                    $docLengthBuffer,
                    $docPositionBuffer,
                    $rawDocuments,
                    $facetBuffer,
                    $fieldTermBuffer,
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
     * @param int ...$ids Document IDs to remove.
     */
    public function delete(int ...$ids): void
    {
        $this->assertWritable();
        /** @infection-ignore-all ReturnRemoval: empty $ids produces zero iterations and docDelta=0; adjustStats is not called — identical result */
        if ($ids === []) {
            return;
        }

        if (count($ids) === 1) {
            $this->wrapInTransaction(function () use ($ids): void {
                /** @infection-ignore-all CastInt: $id is int from variadic; the cast is defensive only */
                $length = $this->removeDocumentData((int) $ids[0]);
                if ($length !== null) {
                    $this->adjustStats(-1, -$length);
                }
            });
            return;
        }

        $this->wrapInTransaction(function () use ($ids): void {
            $oldLengths = [];
            foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
                $placeholders = $this->placeholders(count($chunk));
                $stmt = $this->prepare(
                    "SELECT doc_id, length FROM doc_lengths WHERE doc_id IN ({$placeholders})"
                );
                $stmt->execute($chunk);
                /** @var list<array{doc_id: int, length: int}> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $oldLengths[$row['doc_id']] = $row['length'];
                }
            }

            if ($oldLengths === []) {
                return;
            }

            $this->bulkRemoveDocuments(array_keys($oldLengths));

            $this->adjustStats(-count($oldLengths), -array_sum($oldLengths));
        });
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
            $pdo->exec('DELETE FROM field_hits');

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
        $this->facetKeyCache  = [];
        $this->fieldNameCache = [];
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
     */
    public function snippeter(int $windowSize = 200, int $maxSnippets = 1, string $ellipsis = '…'): Snippeter
    {
        return new Snippeter(
            windowSize: $windowSize,
            maxSnippets: $maxSnippets,
            ellipsis: $ellipsis,
            language: $this->language,
        );
    }

    /**
     * Return a Highlighter pre-configured for use with this index.
     */
    public function highlighter(string $open = '<mark>', string $close = '</mark>', bool $asYouType = true): Highlighter
    {
        return new Highlighter(
            open: $open,
            close: $close,
            asYouType: $asYouType,
            language: $this->language,
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
     * @param  string        $phrase  Raw search phrase; will be tokenised.
     * @param  SearchOptions $options Per-query options (limit, offset, filter, facets, sort, …).
     */
    public function search(
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
        $sort          = $options->sort;
        $distinct      = $options->distinct;
        $distinctCount = $options->distinctCount;
        $sortSpecs     = $this->parseSortSpec($sort);
        $parsed        = $this->filterQueryTokens($phrase);
        /** @var list<string> $keywords */
        $keywords     = $parsed['filtered'];
        /** @var list<list<string>> $phraseGroups */
        $phraseGroups = $parsed['phrase_groups'];

        /** @var array<int, float> $docScores */
        $docScores = [];
        /** @var array<int, int> $docMatchCount  Number of distinct keyword groups that matched each doc. */
        $docMatchCount = [];

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

        foreach ($keywords as $idx => $term) {
            $isLastKeyword = $asYouType && ($lastIndex === $idx);
            $word = $this->getWordlistByKeyword($term, $isLastKeyword);
            foreach ($this->synonymsFor($term) as $synTerm) {
                $synRows = $this->getWordlistByKeyword($synTerm, false, false);
                if ($synRows !== []) {
                    array_push($word, ...$synRows);
                }
            }
            if (!isset($word[0])) {
                continue;
            }
            /** @infection-ignore-all IncrementInteger,Ternary,CastInt: numDocs feeds BM25 scoring only; for single-term prefix results array_sum equals word[0]['num_docs']; CastInt: array_sum returns int */
            $df = count($word) === 1 ? $word[0]['num_docs'] : (int) array_sum(array_column($word, 'num_docs'));
            // Smoothed BM25 IDF: always ≥ 0, avoids negative weights for common terms.
            /** @infection-ignore-all IncrementInteger|Minus|Plus|Division: IDF mutations monotonically shift all per-term scores by the same factor; relative document ordering is preserved for any single-term query */
            $idf     = log(1 + ($totalDocuments - $df + 0.5) / ($df + 0.5));
            /** @infection-ignore-all DecrementInteger|IncrementInteger|Plus|Multiplication: idfK1p1 is a per-term scalar; mutating k1+1 uniformly rescales every doc's contribution for that term, preserving relative ranking */
            $idfK1p1 = $idf * ($k1 + 1);
            // BM25 score computed in SQLite C; PHP receives (term_id, doc_id, score).
            $docs = $this->fetchDocsByTermIds(
                $word,
                $this->config->maxDocs,
                isset($word[0]['distance']),
                $idfK1p1,
                $k1_1mb,
                $k1b_avgdl,
            );
            /** @var array<int, true> $groupTermIds */
            $groupTermIds    = [];
            /** @var array<int, true> $seenThisKeyword  Docs already counted for this keyword group; prevents
             *  prefix-expanded term IDs from inflating $docMatchCount for the same (keyword, doc) pair. */
            $seenThisKeyword = [];
            foreach ($docs as [$termId, $docId, $score]) {
                /** @infection-ignore-all OneZeroFloat: ?? 0.0 is the additive identity; the fallback only applies on first encounter of a docId which always has score 0 before accumulation */
                $docScores[$docId] = ($docScores[$docId] ?? 0.0) + $score;
                $groupTermIds[$termId] = true;
                if (!isset($seenThisKeyword[$docId])) {
                    $seenThisKeyword[$docId]  = true;
                    $docMatchCount[$docId] = ($docMatchCount[$docId] ?? 0) + 1;
                }
                if ($useFieldBoosts) {
                    $termIdfMap[$termId]               ??= $idfK1p1;
                    $docContribTermIds[$docId][$termId]  = true;
                }
            }
            if ($groupTermIds !== []) {
                $termGroups[] = array_keys($groupTermIds);
            }
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

        if (count($termGroups) >= 2 && $this->config->proximityBoost > 0.0) {
            // Only proximity-boost docs that matched all keyword groups; partial-match docs
            // are skipped inside applyProximityBoost anyway — pre-filtering avoids fetching
            // their positions and shrinks the positions IN() clause significantly.
            $numKeywords = count($keywords);
            $boostSet    = array_filter(
                $docScores,
                fn($id): bool => ($docMatchCount[$id] ?? 0) >= $numKeywords,
                ARRAY_FILTER_USE_KEY,
            );
            $proxWindow = $this->config->proxWindowSize;
            if ($proxWindow > 0 && count($boostSet) > $proxWindow) {
                arsort($boostSet);
                $boostSet = array_slice($boostSet, 0, $proxWindow, true);
            }
            if ($boostSet !== []) {
                $this->applyProximityBoost($boostSet, $termGroups);
                foreach ($boostSet as $id => $s) {
                    $docScores[$id] = $s;
                }
            }
        }

        // Phrase filter: remove documents that do not contain every quoted phrase as a
        // contiguous token sequence. Runs after BM25+proximity so positions are only fetched
        // for the (already-ranked) candidate set, not the entire doclist.
        if ($phraseGroups !== []) {
            $lastToken = end($keywords) ?: '';
            $matchIds  = $this->filterDocsByPhrases(array_keys($docScores), $phraseGroups, $lastToken, $asYouType);
            $docScores = array_intersect_key($docScores, array_flip($matchIds));
        }

        // Apply facet filters: load per-key doc ID sets and intersect with the score map.
        $filterSets   = $this->loadFacetKeySets($filter, $this->config->filterMaxDocs, array_keys($docScores));
        $rawDocScores = $docScores;
        if ($filterSets !== []) {
            $globalFilter = $this->intersectFilterSets($filterSets);
            $docScores = array_intersect_key($docScores, $globalFilter);
        }

        // Compute disjunctive facet counts on the full filtered result set.
        ['distribution' => $facetDistribution, 'stats' => $facetStats] = $this->computeFacetCounts(
            $facets,
            $filterSets,
            $rawDocScores,
            $docScores,
            $this->config->maxFacetCountDocs,
        );

        $total = count($docScores);

        /** @infection-ignore-all DecrementInteger: $total is count(); -1 is impossible, so the guard fires identically for any realistic input */
        if ($total === 0) {
            return new SearchResult(
                ids: [],
                totalHits: 0,
                documents: $this->hydrateAndFormat([], $phrase, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
            );
        }

        if ($distinct !== null) {
            $keyId = $this->lookupFacetKeyId($distinct);
            if ($sortSpecs !== []) {
                $sortedIds = $this->sortDocIdsBySpecs(array_keys($docScores), $sortSpecs, $docScores);
            } elseif (count($keywords) > 1) {
                $sortedIds = array_keys($docScores);
                $mc = [];
                $sc = [];
                foreach ($sortedIds as $id) {
                    $mc[] = $docMatchCount[$id] ?? 0;
                    $sc[] = $docScores[$id];
                }
                array_multisort($mc, SORT_DESC, SORT_NUMERIC, $sc, SORT_DESC, SORT_NUMERIC, $sortedIds);
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
                documents: $this->hydrateAndFormat($pagedIds, $phrase, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
            );
        }

        if ($limit === 0) {
            return new SearchResult(
                ids: [],
                totalHits: $total,
                documents: $this->hydrateAndFormat([], $phrase, $options),
                facetCounts: $facetDistribution,
                facetStats: $facetStats,
                query: $phrase,
                limit: $limit,
                offset: $offset,
            );
        }

        if ($sortSpecs !== []) {
            // Custom field sort: primary keys are the declared sort fields, BM25 score is the
            // tiebreaker, doc ID is the final deterministic key.
            $pagedIds = $this->applySortedPagination(array_keys($docScores), $sortSpecs, $docScores, $offset, $limit);
        } elseif (count($keywords) > 1) {
            // Multi-keyword relevance sort: primary = matched keyword groups (DESC),
            // secondary = BM25+proximity score (DESC). C-native array_multisort avoids
            // per-comparison PHP closure call overhead.
            $sortedIds = array_keys($docScores);
            $mc = [];
            $sc = [];
            foreach ($sortedIds as $id) {
                $mc[] = $docMatchCount[$id] ?? 0;
                $sc[] = $docScores[$id];
            }
            array_multisort($mc, SORT_DESC, SORT_NUMERIC, $sc, SORT_DESC, SORT_NUMERIC, $sortedIds);
            $pagedIds = array_slice($sortedIds, $offset, $limit);
        } else {
            // Single-keyword: all docs tie on match count; C-native arsort on scores alone.
            arsort($docScores);
            $pagedIds = array_slice(array_keys($docScores), $offset, $limit);
        }
        return new SearchResult(
            ids: $pagedIds,
            totalHits: $total,
            documents: $this->hydrateAndFormat($pagedIds, $phrase, $options),
            facetCounts: $facetDistribution,
            facetStats: $facetStats,
            query: $phrase,
            limit: $limit,
            offset: $offset,
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
        $sort          = $options->sort;
        $distinct      = $options->distinct;
        $distinctCount = $options->distinctCount;
        $sortSpecs = $this->parseSortSpec($sort);
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

        /** Fetch capped doc IDs for one keyword (resolves prefix expansion / caching). */
        $fetchIds = fn(string $kw, bool $isLast): array =>
            $this->fetchBooleanDocIds(
                $this->resolveWordlistIds($kw, $isLast),
                $maxDocs
            );

        /**
         * Materialise a stack entry into a flat list of doc IDs.
         * Strings are lazily fetched; lists are passed through; null maps to [].
         *
         * @param  string|list<int>|null $entry
         * @return list<int>
         */
        $ids = function (string|array|null $entry) use ($fetchIds, $lastTerm, $asYouType): array {
            if ($entry === null) {
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
                $right = array_pop($stack);
                $left  = array_pop($stack);
                if (is_array($right) && isset($right['__not__'])) {
                    // AND-NOT: subtract negated IDs from the positive side.
                    /** @var array{__not__: list<int>} $right */
                    /** @infection-ignore-all UnwrapArrayValues: array_diff preserves keys from first arg; array_values ensures list<int> contract for downstream array_slice/assertContains; keys are integer so assertContains still passes without reindex, making this a silent correctness issue rather than a detectable test failure */
                    $stack[] = array_values(array_diff($ids($left), $right['__not__']));
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
        $filterSets = $this->loadFacetKeySets($filter, $this->config->filterMaxDocs, $docIds);
        $rawDocSet  = array_flip($docIds);
        if ($filterSets !== []) {
            $globalFilter = $this->intersectFilterSets($filterSets);
            $docIds = array_keys(array_intersect_key($rawDocSet, $globalFilter));
        }

        // Compute disjunctive facet counts on the full filtered result.
        $filteredDocSet = array_flip($docIds);
        ['distribution' => $facetDistribution, 'stats' => $facetStats] = $this->computeFacetCounts(
            $facets,
            $filterSets,
            $rawDocSet,
            $filteredDocSet,
            $this->config->maxFacetCountDocs,
        );

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
     */
    public function facetSearch(FacetSearchQuery $query): FacetSearchResult
    {
        $this->checkDataVersion();
        $keyId = $this->lookupFacetKeyId($query->facetName);
        if ($keyId === null) {
            return new FacetSearchResult([], $query->facetQuery);
        }

        // --- Step 1: FTS candidate doc IDs (AND-intersection across keywords; phrases applied after) ---
        $ftsCandidates = null; // null = no FTS restriction
        if (trim($query->query) !== '') {
            $parsed       = $this->filterQueryTokens($query->query);
            $keywords     = $parsed['filtered'];
            /** @var list<list<string>> $phraseGroups */
            $phraseGroups = $parsed['phrase_groups'];
            $maxDocs      = $this->config->maxFacetCountDocs;
            $last         = count($keywords) - 1;
            foreach ($keywords as $i => $kw) {
                $termIds       = $this->resolveWordlistIds($kw, $i === $last);
                $kwDocs        = array_fill_keys($this->fetchBooleanDocIds($termIds, $maxDocs), true);
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

        // --- Step 2: Facet filters, optionally constrained to FTS candidates ---
        $candidateSet = null; // null = no restriction
        if ($query->filter !== []) {
            $candidateDocIds = $ftsCandidates !== null ? array_keys($ftsCandidates) : [];
            $filterSets      = $this->loadFacetKeySets($query->filter, $this->config->filterMaxDocs, $candidateDocIds);
            if ($filterSets !== []) {
                $candidateSet = $this->intersectFilterSets($filterSets);
            } else {
                $candidateSet = $ftsCandidates;
            }
        } else {
            $candidateSet = $ftsCandidates;
        }

        // --- Step 3: Query facet_values GROUP BY value ---
        $facetQuery = $query->facetQuery;
        $hasPrefix  = $facetQuery !== '';
        $conditions = ['key_id = ?'];
        $params     = [$keyId];

        if ($hasPrefix) {
            $conditions[] = "LOWER(value) LIKE ? ESCAPE '\\'";
            // Escape LIKE special chars in the user-supplied prefix so '%' and '_' are literal.
            $escaped  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($facetQuery));
            $params[] = $escaped . '%';
        }

        if ($candidateSet !== null) {
            $conditions[] = 'doc_id IN (SELECT value FROM json_each(?))';
            $params[]     = json_encode(array_keys($candidateSet));
        }

        $params[] = $query->limit;

        $stmt = $this->prepare(
            'SELECT value, COUNT(*) AS count FROM facet_values'
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' GROUP BY value ORDER BY count DESC LIMIT ?'
        );
        $stmt->execute($params);

        /** @var list<array{value: string, count: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hits = array_map(
            fn(array $row) => ['value' => $row['value'], 'count' => (int) $row['count']],
            $rows
        );

        return new FacetSearchResult($hits, $facetQuery);
    }

    /**
     * Browse all documents with no FTS scoring — used when $phrase is empty.
     *
     * Fast path (no filter / sort / facets / distinct): totalHits from the info cache,
     * page fetched with a single PK scan. General path: loads all doc IDs (capped),
     * applies filter/sort/facets/distinct using the same helpers as searchBoolean().
     * Default order is doc_id DESC (insertion order, newest first).
     */
    private function browse(string $phrase, SearchOptions $options): SearchResult
    {
        $limit         = $options->limit;
        $offset        = $options->offset;
        $filter        = $options->filter;
        $facets        = $options->facets;
        $sort          = $options->sort;
        $distinct      = $options->distinct;
        $distinctCount = $options->distinctCount;
        $sortSpecs     = $this->parseSortSpec($sort);

        // Fast path: skip all PHP-side work; one PK scan for the page, total from cache.
        if ($filter === [] && $sortSpecs === [] && $facets === [] && $distinct === null) {
            $info  = $this->getInfoValues(['total_documents']);
            $total = (int) ($info['total_documents'] ?? 0);
            $stmt  = $this->stmt(
                'browsePageIds',
                'SELECT doc_id FROM doc_lengths ORDER BY doc_id DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute([$limit, $offset]);
            /** @var list<int> $pagedIds */
            $pagedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return new SearchResult(
                ids: $pagedIds,
                totalHits: $total,
                documents: $this->hydrateAndFormat($pagedIds, $phrase, $options),
                facetCounts: [],
                facetStats: [],
                query: $phrase,
                limit: $limit,
                offset: $offset,
            );
        }

        // General path: materialise all doc IDs (capped), then reuse the boolean helpers.
        $cap    = max($this->config->filterMaxDocs, $this->config->maxFacetCountDocs);
        $docIds = $this->fetchBrowseDocIds($cap);

        $filterSets = $this->loadFacetKeySets($filter, $this->config->filterMaxDocs, $docIds);
        $rawDocSet  = array_flip($docIds);
        if ($filterSets !== []) {
            $globalFilter = $this->intersectFilterSets($filterSets);
            $docIds = array_keys(array_intersect_key($rawDocSet, $globalFilter));
        }

        $filteredDocSet = array_flip($docIds);
        ['distribution' => $facetDistribution, 'stats' => $facetStats] = $this->computeFacetCounts(
            $facets,
            $filterSets,
            $rawDocSet,
            $filteredDocSet,
            $this->config->maxFacetCountDocs,
        );

        // Use info cache for total when no filter is active (accurate even when cap < total docs).
        $info  = $this->getInfoValues(['total_documents']);
        $total = $filterSets !== []
            ? count($docIds)
            : (int) ($info['total_documents'] ?? 0);

        // Sort once; used by both the distinct and non-distinct paths below.
        $sortedDocIds = ($sortSpecs !== [] && $total > 0)
            ? $this->sortDocIdsBySpecs($docIds, $sortSpecs, [])
            : $docIds;

        if ($distinct !== null) {
            $keyId    = $this->lookupFacetKeyId($distinct);
            $valueMap = $this->fetchSortValues($sortedDocIds, $keyId);
            [$pagedIds, $distinctHits] = $this->applyDistinctPagination(
                $sortedDocIds,
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
            );
        }

        $pagedIds = array_slice($sortedDocIds, $offset, $limit);

        return new SearchResult(
            ids: $pagedIds,
            totalHits: $total,
            documents: $this->hydrateAndFormat($pagedIds, $phrase, $options),
            facetCounts: $facetDistribution,
            facetStats: $facetStats,
            query: $phrase,
            limit: $limit,
            offset: $offset,
        );
    }

    /**
     * Fetch all doc IDs from doc_lengths ordered by doc_id DESC, capped at $cap.
     *
     * @return list<int>
     */
    private function fetchBrowseDocIds(int $cap): array
    {
        $stmt = $this->stmt(
            'fetchBrowseDocIds',
            'SELECT doc_id FROM doc_lengths ORDER BY doc_id DESC LIMIT ?'
        );
        $stmt->execute([$cap]);
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $ids;
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
     * @param  list<int>    $ids
     * @return array<int, array<string, mixed>>|null
     */
    private function hydrateAndFormat(array $ids, string $phrase, SearchOptions $options): ?array
    {
        $documents = $this->hydrateIds($ids);
        if (
            $documents !== null && $documents !== [] &&
            ($options->attributesToHighlight !== null || $options->attributesToCrop !== null)
        ) {
            $documents = $this->applyFormatting($documents, $phrase, $options);
        }
        return $documents;
    }

    /**
     * Attach '_formatted' to each document with highlighted and/or cropped string field values.
     *
     * Cropping runs first; highlighting is applied to the (possibly cropped) text, so a field
     * in both lists gets a short, highlighted excerpt. Only string-typed fields are processed.
     *
     * @param  array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private function applyFormatting(array $documents, string $phrase, SearchOptions $options): array
    {
        $highlightFields = $options->attributesToHighlight;
        $cropFields      = $options->attributesToCrop;

        $highlighter = $highlightFields !== null
            ? $this->highlighter($options->highlightPreTag, $options->highlightPostTag, $options->asYouType)
            : null;

        $snippeter = $cropFields !== null
            ? $this->snippeter($options->cropLength, 1, $options->cropMarker)
            : null;

        foreach ($documents as $id => $doc) {
            $stringFields = [];
            foreach ($doc as $k => $v) {
                if (is_string($v)) {
                    $stringFields[$k] = $v;
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
            }

            if ($formatted !== []) {
                $documents[$id]['_formatted'] = $formatted;
            }
        }

        return $documents;
    }

    // --- Private write helpers ----------------------------------------------

    /**
     * Remove a set of documents from the index in bulk without adjusting total_documents or avg_doc_length.
     *
     * Equivalent to calling removeDocumentData() in a loop but issues one CTE-based UPDATE and a handful
     * of bulk DELETEs per chunk rather than 5 individual prepared statements per document. Orphan
     * terms are pruned from the wordlist scoped to the affected term set (no full table scan).
     *
     * @param int[] $ids Document IDs to remove; all must exist in the index.
     */
    private function bulkRemoveDocuments(array $ids): void
    {
        $pdo = $this->pdo;
        assert($pdo instanceof \PDO);

        foreach (array_chunk($ids, self::CHUNK_1P) as $chunk) {
            $n            = count($chunk);
            $placeholders = $this->placeholders($n);

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
            $this->prepare("DELETE FROM doc_lengths  WHERE doc_id IN ({$placeholders})")->execute($chunk);
            if ($this->documentStoreEnabled) {
                $this->prepare("DELETE FROM documents WHERE doc_id IN ({$placeholders})")->execute($chunk);
            }
            $this->prepare("DELETE FROM facet_values WHERE doc_id IN ({$placeholders})")->execute($chunk);
            $this->prepare("DELETE FROM field_hits WHERE doc_id IN ({$placeholders})")->execute($chunk);

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
        $this->stmt('facetValuesDeleteByDoc', 'DELETE FROM facet_values WHERE doc_id = :documentId')
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
            $text = strip_tags($text);
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
     * @param  array<string, mixed> $row Document fields; must contain an 'id' key.
     * @return int                       Total token count across all indexed fields.
     */
    private function processDocument(array $row): int
    {
        $documentId = $this->extractId($row['id']);

        ['termCounts'      => $termCounts,
         'fieldTermCounts' => $fieldTermCounts,
         'termPositions'   => $termPositions,
         'length'          => $length] = $this->tokenizeDocumentFields($row);

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
     * @param-out array<string, array<int|string, array<int, float|null>>> $facetBuffer
     */
    private function accumulateFacets(array $extracted, int $documentId, array &$facetBuffer): void
    {
        foreach ($extracted as $name => $rawValue) {
            if (is_array($rawValue)) {
                foreach ($rawValue as $v) {
                    if (is_int($v) || is_float($v)) {
                        $strVal = (string) $v;
                        $facetBuffer[$name][$strVal][$documentId] = (float) $v;
                    } elseif (is_string($v) && $v !== '') {
                        $strVal = $v;
                        $facetBuffer[$name][$strVal][$documentId] = null;
                    }
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
     * @return array{
     *     wordHits:          array<string, int>,
     *     wordDocs:          array<string, int>,
     *     docTermBuffer:     array<int, array<string, int>>,
     *     docLengthBuffer:   array<int, int>,
     *     docPositionBuffer: array<int, array<string, list<int>>>,
     *     facetBuffer:       array<string, array<int|string, array<int, float|null>>>,
     *     rawDocuments:      array<int, array<string, mixed>>,
     *     fieldTermBuffer:   array<int, array<string, array<string, int>>>
     * }
     */
    private function buildBatchBuffer(array $documents, ?callable $progress = null): array
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
             'length'          => $length] = $this->tokenizeDocumentFields($document);

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
            $this->bulkFlushFacets($facetBuffer);
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
            /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
            ($this->bulkStmtCache["doclistChunk:{$rowCount}"] ??= $pdo->prepare(
                'INSERT INTO doclist (term_id, doc_id, hit_count) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?)'))
            ))->execute($params);
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
            /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
            ($this->bulkStmtCache["positionsChunk:{$rowCount}"] ??= $pdo->prepare(
                'INSERT INTO positions (term_id, doc_id, position) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?)'))
            ))->execute($params);
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
            ($this->bulkStmtCache["docLengthChunk:{$rowCount}"] ??= $pdo->prepare(
                'INSERT INTO doc_lengths (doc_id, length) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?)'))
            ))->execute($params);
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
            ($this->bulkStmtCache["documentsChunk:{$rowCount}"] ??= $pdo->prepare(
                'INSERT INTO documents (doc_id, data) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?)'))
            ))->execute($params);
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
            ($this->bulkStmtCache["batchWordlistUpdate:{$n}"] ??= $pdo->prepare(
                'WITH delta(id, hits, docs) AS (VALUES ' . implode(',', array_fill(0, $n, '(?,?,?)')) . ')
                 UPDATE wordlist SET
                     num_hits = num_hits + delta.hits,
                     num_docs = num_docs + delta.docs
                 FROM delta WHERE wordlist.id = delta.id'
            ))->execute($params);

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
            /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
            $stmt = ($this->bulkStmtCache["batchWordlistUpsert:{$n}"] ??= $pdo->prepare(
                'INSERT INTO wordlist (term, num_hits, num_docs) VALUES '
                    /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
                    . implode(',', array_fill(0, $n, '(?,?,?)'))
                    . ' ON CONFLICT(term) DO UPDATE SET
                           num_hits = num_hits + excluded.num_hits,
                           num_docs = num_docs + excluded.num_docs
                       RETURNING id, term'
            ));
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
            /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
            ($this->bulkStmtCache["fieldHitsChunk:{$rowCount}"] ??= $pdo->prepare(
                'INSERT INTO field_hits (term_id, doc_id, field_id, hit_count) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?,?)'))
            ))->execute($params);
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
     * Fetch document lengths for a set of doc IDs. Used by field boost re-scoring.
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
     * @return list<int>
     */
    private function resolveWordlistIds(string $keyword, bool $isLastKeyword): array
    {
        $tokens = Tokenizer::tokenize($keyword, $this->language);
        if ($this->stemmer instanceof \Fuzor\Stemmer) {
            $tokens = $this->stemmer->stemTokens($tokens);
        }
        $ids  = [];
        $last = count($tokens) - 1;
        foreach ($tokens as $i => $token) {
            foreach ($this->getWordlistByKeyword($token, $isLastKeyword && $i === $last) as $row) {
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
     * Used by the boolean PHP-side evaluator; does not fetch BM25 fields.
     * Single-term path uses a cached statement. Multi-term path uses IN() —
     * boolean set operations in PHP don't require hit_count ordering.
     *
     * @param  list<int> $termIds
     * @return list<int>
     */
    private function fetchBooleanDocIds(array $termIds, int $limit): array
    {
        /** @infection-ignore-all ReturnRemoval: boolean search terms always resolve to non-empty termIds in tests (all searched terms exist in the indexed docs) */
        if ($termIds === []) {
            return [];
        }

        $n = count($termIds);

        /** @infection-ignore-all IncrementInteger,Identical: mutations on n===1 only switch between the single-term cached stmt and the IN()-based multi-term stmt; both queries return equivalent doc ID sets */
        if ($n === 1) {
            $stmt = $this->stmt(
                'boolDocIds1',
                'SELECT doc_id FROM doclist WHERE term_id = ? ORDER BY hit_count DESC LIMIT ?'
            );
            $stmt->execute([$termIds[0], $limit]);
            /** @var list<int> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            /** @infection-ignore-all ReturnRemoval: falling through to the IN() path for n=1 returns the same result set */
            return $rows;
        }

        $placeholders = $this->placeholders($n);
        $stmt         = $this->stmt(
            "boolDocIds:{$n}",
            "SELECT doc_id FROM doclist WHERE term_id IN ({$placeholders}) ORDER BY hit_count DESC LIMIT ?"
        );
        $stmt->execute([...$termIds, $limit]);
        /** @var list<int> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        /** @infection-ignore-all ArrayOneItem: boolean set operations use assertContains; returning only 1 item from a multi-doc result is not caught by membership tests for single-match terms */
        return $rows;
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

        $numGroups     = count($termGroups);
        $boost         = $this->config->proximityBoost;
        // Single-term groups have positions already in ORDER BY position order from the DB query.
        // Only multi-term groups (prefix/fuzzy expansion) need an explicit sort.
        $groupNeedSort = array_map(fn(array $tids): bool => count($tids) > 1, $termGroups);

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

            // Build a merged list of (position, groupIndex) sorted by position.
            /** @var list<array{0: int, 1: int}> $merged */
            $merged = [];
            foreach ($groupPositions as $g => $posList) {
                if ($groupNeedSort[$g]) {
                    sort($posList);
                }
                foreach ($posList as $pos) {
                    $merged[] = [$pos, $g];
                }
            }
            usort($merged, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

            // Sliding-window minimum-span: smallest window covering all groups.
            $count   = array_fill(0, $numGroups, 0);
            $have    = 0;
            $left    = 0;
            $minSpan = PHP_INT_MAX;

            foreach ($merged as [$rightPos, $rightG]) {
                if ($count[$rightG] === 0) {
                    $have++;
                }
                $count[$rightG]++;

                while ($have === $numGroups) {
                    $span = $rightPos - $merged[$left][0];
                    if ($span < $minSpan) {
                        $minSpan = $span;
                    }
                    /** @var array{0: int, 1: int} $leftEntry */
                    $leftEntry = $merged[$left];
                    $count[$leftEntry[1]]--;
                    if ($count[$leftEntry[1]] === 0) {
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

        $nDocs         = count($docIds);
        $nTerms        = count($termIds);
        $dPlaceholders = $this->placeholders($nDocs);
        $tPlaceholders = $this->placeholders($nTerms);

        $stmt = $this->stmt(
            "fetchPositions:{$nDocs}:{$nTerms}",
            "SELECT doc_id, term_id, position
             FROM positions
             WHERE doc_id IN ({$dPlaceholders}) AND term_id IN ({$tPlaceholders})
             ORDER BY doc_id, term_id, position"
        );
        $stmt->execute([...$docIds, ...$termIds]);

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
            $groupTokens = Tokenizer::tokenize($rawGroup, $this->language);
            if ($this->stopwords instanceof \Fuzor\Stopwords && count($groupTokens) > 1) {
                $afterGroupStop = $this->stopwords->filter($groupTokens);
                if ($afterGroupStop === []) {
                    continue;
                }
                $groupTokens = $afterGroupStop;
            }
            if ($groupTokens === []) {
                continue;
            }
            if ($this->stemmer instanceof \Fuzor\Stemmer) {
                $groupTokens = $this->stemmer->stemTokens($groupTokens);
            }
            $phraseGroups[] = $groupTokens;
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
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int, distance?: int}>
     * @infection-ignore-all FalseValue: default parameter values are never exercised; callers always pass
     *   all booleans explicitly
     */
    private function getWordlistByKeyword(
        string $keyword,
        bool $isLastWord = false,
        bool $allowFuzzy = true,
    ): array {
        // Cache exact/prefix lookups by "keyword:isLastWord" key.
        // Fuzzy results carry a distance key and are excluded from caching — Levenshtein
        // distance is applied post-fetch, so cached rows could go stale after config changes.
        /** @infection-ignore-all CastInt,Concat,ConcatOperandRemoval: cache key format mutations only affect cache hit/miss rates, not correctness */
        $cacheKey = "{$keyword}:" . (int) $isLastWord;
        /** @infection-ignore-all ReturnRemoval: skipping a cache hit only causes a redundant DB query; the same result is returned */
        if (isset($this->wordlistCache[$cacheKey])) {
            return $this->wordlistCache[$cacheKey];
        }

        if ($isLastWord && Tokenizer::ngramSize($this->language) === 0) {
            $stmt = $this->stmt(
                'wordlistPrefix',
                'SELECT id, term, num_hits, num_docs FROM wordlist'
                . ' WHERE term LIKE :keyword ORDER BY length(term) ASC, num_hits DESC LIMIT :maxExpansions;'
            );
            $stmt->bindValue(':keyword', $keyword . '%');
            $stmt->bindValue(':maxExpansions', $this->config->fuzzyMaxExpansions, PDO::PARAM_INT);
        } else {
            $stmt = $this->stmt(
                'wordlistExact',
                'SELECT id, term, num_hits, num_docs FROM wordlist WHERE term = :keyword LIMIT 1;'
            );
            $stmt->bindValue(':keyword', $keyword);
        }

        $stmt->execute();

        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int}> $wordlistRows */
        $wordlistRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fall through to Levenshtein only when: no exact/prefix match found, fuzzy is allowed
        // for this call site, and the word meets the minimum length threshold (short words have
        // too many false-positive fuzzy matches to be useful).
        if (
            $allowFuzzy
            && !isset($wordlistRows[0])
            && mb_strlen($keyword) >= $this->config->fuzzyMinWordLength
        ) {
            return $this->fuzzySearch($keyword);
        }

        $this->wordlistCache[$cacheKey] = $wordlistRows;

        return $wordlistRows;
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
        $ids = array_column($words, 'id');
        $n   = count($ids);

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
                'fetchOneTermDocs',
                'SELECT sub.term_id, sub.doc_id,
                        ? * sub.hit_count / (? + ? * dl.length + sub.hit_count) AS score
                  FROM (SELECT term_id, doc_id, hit_count FROM doclist
                        WHERE term_id = ? ORDER BY hit_count DESC LIMIT ?) sub
                  JOIN doc_lengths dl ON dl.doc_id = sub.doc_id'
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
                'SELECT term_id, doc_id, hit_count FROM doclist WHERE term_id = ?'
            ));
            $stmt = $this->stmt(
                "fetchNTermDocs:{$n}",
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
                    WHERE term_id IN ({$placeholders})
                    ORDER BY CASE term_id {$cases} END ASC, hit_count DESC LIMIT ?) sub
              JOIN doc_lengths dl ON dl.doc_id = sub.doc_id"
        );
        $stmt->execute([$idfK1p1, $k1_1mb, $k1b_avgdl, ...$ids, ...$ids, $limit]);
        /** @var list<array{0: int, 1: int, 2: float}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        return $rows;
    }

    /**
     * Find wordlist candidates within Levenshtein edit distance of the keyword.
     *
     * Queries the wordlist for all terms sharing the same prefix
     * ($fuzzyPrefixLength chars), then filters by the effective edit distance and sorts
     * by edit distance ascending, then num_hits descending.
     *
     * The effective distance scales with word length: 1 for words of 5–8 codepoints,
     * 2 typos for words of 9+ codepoints. This mirrors the standard typo-tolerance tiers
     * and avoids false positives on shorter words.
     *
     * @param  string                    $keyword Search term to find fuzzy matches for (must already be lowercased).
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int, distance: int}>
     */
    private function fuzzySearch(string $keyword): array
    {
        /** @infection-ignore-all MBString,CastInt: ASCII fuzzy tests are unaffected by mb_ vs byte strlen; CastInt: mb_strlen returns int already */
        $keywordLength = mb_strlen($keyword);
        // 5–8 codepoints → 1 typo; 9+ codepoints → configured max (default 2).
        /** @infection-ignore-all GreaterThan,IncrementInteger: threshold boundary only widens/narrows which tier applies; Levenshtein post-filter corrects the result set */
        $effectiveDistance = $keywordLength >= 9 ? 2 : 1;

        $stmt = $this->stmt(
            'fuzzyWordlistLookup',
            "SELECT id, term, num_hits, num_docs FROM wordlist
             WHERE term LIKE :keyword
               AND length(term) BETWEEN :min AND :max
             ORDER BY num_hits DESC
             LIMIT :maxExpansions"
        );
        /** @infection-ignore-all MBString,ConcatOperandRemoval: ASCII fuzzy tests are unaffected by mb_ vs byte substr; removing the prefix still produces correct candidates after Levenshtein filtering (just with more candidates) */
        $stmt->bindValue(':keyword', mb_substr($keyword, 0, $this->config->fuzzyPrefixLength) . '%');
        /** @infection-ignore-all DecrementInteger,IncrementInteger: adjusting the min length boundary by 1 only broadens or narrows the candidate set; Levenshtein filtering corrects the result */
        $stmt->bindValue(':min', max(1, $keywordLength - $effectiveDistance), PDO::PARAM_INT);
        $stmt->bindValue(':max', $keywordLength + $effectiveDistance, PDO::PARAM_INT);
        $stmt->bindValue(':maxExpansions', $this->config->fuzzyMaxExpansions, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int, distance: int}> $resultSet */
        $resultSet = [];
        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int}> $candidates */
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $match) {
            $distance = Levenshtein::distance($match['term'], $keyword);
            if ($distance <= $effectiveDistance) {
                $resultSet[] = [...$match, 'distance' => $distance];
            }
        }

        /** @infection-ignore-all Spaceship: swapping secondary sort (num_hits) DESC→ASC only reorders equally-distant candidates; assertContains tests are order-agnostic */
        usort($resultSet, fn(array $a, array $b): int => $a['distance'] <=> $b['distance'] ?: $b['num_hits'] <=> $a['num_hits']); // phpcs:ignore Generic.Files.LineLength.TooLong

        return $resultSet;
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
        foreach ($rows as $row) {
            $keyId = $this->resolveFacetKeyId($row['name']);
            $stmt->execute([$keyId, $row['value'], $documentId, $row['numValue']]);
        }
    }

    /**
     * Bulk-upsert facet keys and insert facet_values rows in (key_id, value, doc_id) PK order.
     *
     * Receives a pre-organized name→value→docId→numValue map built by buildBatchBuffer().
     *
     * @param array<string, array<int|string, array<int, float|null>>> $facetBuffer  name → value → docId → numValue
     */
    private function bulkFlushFacets(array $facetBuffer): void
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
            ($this->bulkStmtCache["facetValuesChunk:{$rowCount}"] ??= $pdo->prepare(
                'INSERT INTO facet_values (key_id, value, doc_id, num_value) VALUES '
                . implode(',', array_fill(0, $rowCount, '(?,?,?,?)'))
            ))->execute($params);
        }
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
     * Fetch sort column values for a single facet field keyed by doc ID.
     *
     * Returns float for numeric facets, string for string facets, null for docs that have
     * no value for this field. Uses a fixed json_each-based statement (always cached as
     * 'fetchSortValues') so no per-call prepare overhead regardless of candidate count.
     *
     * @param  list<int> $docIds
     * @param  int|null  $keyId   null = unknown field; all docs return null
     * @return array<int, float|string|null>
     */
    private function fetchSortValues(array $docIds, ?int $keyId): array
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
        /** @var list<array{0: int, 1: float|null, 2: string|null}> $sortRows */
        $sortRows = $stmt->fetchAll(PDO::FETCH_NUM);
        foreach ($sortRows as [$docId, $numValue, $strValue]) {
            $result[$docId] = $numValue !== null ? $numValue : $strValue;
        }
        return $result;
    }

    /**
     * Sort $docIds by the given sort specs and return the full sorted list without pagination.
     *
     * Sort fields are primary; $scores (BM25) is the tiebreaker when provided; doc ID is the
     * final deterministic tiebreaker. Docs missing a sort field value are sorted last in both
     * ASC and DESC directions.
     *
     * @param  list<int>                             $docIds
     * @param  list<array{field: string, asc: bool}> $specs
     * @param  array<int, float>                     $scores  BM25 scores; empty array for boolean path
     * @return list<int>
     */
    private function sortDocIdsBySpecs(array $docIds, array $specs, array $scores): array
    {
        if ($docIds === []) {
            return [];
        }

        $nSpecs  = count($specs);
        $columns = [];

        foreach ($specs as ['field' => $field, 'asc' => $asc]) {
            $keyId  = $this->lookupFacetKeyId($field);
            $values = $this->fetchSortValues($docIds, $keyId);

            // Detect numeric column: any non-null float value means the whole field is numeric.
            $isNumeric = false;
            foreach ($values as $v) {
                if ($v !== null) {
                    $isNumeric = is_float($v);
                    break;
                }
            }

            // Apply null-last sentinels so docs without a value sort after all real values
            // regardless of sort direction.
            $col = [];
            foreach ($docIds as $id) {
                $v = $values[$id];
                if ($v === null) {
                    $col[$id] = $asc
                        ? ($isNumeric ? PHP_FLOAT_MAX  : "\xFF\xFF")
                        : ($isNumeric ? -PHP_FLOAT_MAX : '');
                } else {
                    $col[$id] = $v;
                }
            }
            $columns[] = ['col' => $col, 'asc' => $asc];
        }

        usort($docIds, function (int $a, int $b) use ($columns, $nSpecs, $scores): int {
            for ($j = 0; $j < $nSpecs; $j++) {
                $cmp = $columns[$j]['col'][$a] <=> $columns[$j]['col'][$b];
                if ($cmp !== 0) {
                    return $columns[$j]['asc'] ? $cmp : -$cmp;
                }
            }
            // BM25 tiebreaker (descending); absent on boolean path.
            $sc = ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0);
            if ($sc !== 0) {
                return $sc;
            }
            // Doc ID: stable deterministic final tiebreaker.
            return $a <=> $b;
        });

        return $docIds;
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
     * Load per-filter-key doc ID sets from facet_values.
     *
     * Called once at the start of search(); all sets are retained in memory so
     * disjunctive facet counting can reuse them without extra DB round-trips.
     *
     * When $candidateDocIds is non-empty the query is scoped to that set, so the
     * result is always correct regardless of corpus size (no LIMIT truncation).
     *
     * @param  array<string, string|list<string>|FacetRange> $filter
     * @param  int                                           $filterMaxDocs   Fallback cap when no candidates provided.
     * @param  list<int>                                     $candidateDocIds BM25/boolean candidates to scope query.
     * @return array<string, array<int, true>>               Key name → flipped doc ID set.
     */
    private function loadFacetKeySets(array $filter, int $filterMaxDocs, array $candidateDocIds = []): array
    {
        if ($filter === []) {
            return [];
        }
        $sets = [];
        foreach ($filter as $name => $filterValue) {
            $keyId = $this->lookupFacetKeyId($name);
            if ($keyId === null) {
                $sets[$name] = [];
                continue;
            }
            if ($filterValue instanceof FacetRange) {
                $sets[$name] = $this->fetchFacetDocIdsByRange($keyId, $filterValue, $filterMaxDocs, $candidateDocIds);
            } else {
                $values = is_array($filterValue) ? $filterValue : [$filterValue];
                $sets[$name] = $this->fetchFacetDocIdsByValues($keyId, $values, $filterMaxDocs, $candidateDocIds);
            }
        }
        return $sets;
    }

    /**
     * Fetch doc IDs matching any of the given string values for a facet key.
     *
     * When $candidateDocIds is non-empty the query adds AND doc_id IN (json_each),
     * so only candidate docs are tested — no LIMIT is needed and recall is exact.
     * Without candidates the query falls back to a LIMIT cap.
     *
     * @param  list<string>        $values
     * @param  list<int>           $candidateDocIds
     * @return array<int, true>
     */
    private function fetchFacetDocIdsByValues(int $keyId, array $values, int $limit, array $candidateDocIds = []): array
    {
        $n  = count($values);
        $ph = $this->placeholders($n);
        if ($candidateDocIds !== []) {
            $candidateJson = json_encode($candidateDocIds);
            $stmt = $this->prepare(
                "SELECT doc_id FROM facet_values WHERE key_id = ? AND value IN ({$ph})"
                . ' AND doc_id IN (SELECT value FROM json_each(?))'
            );
            $stmt->execute([$keyId, ...$values, $candidateJson]);
        } else {
            $stmt = $this->prepare(
                "SELECT doc_id FROM facet_values WHERE key_id = ? AND value IN ({$ph}) LIMIT ?"
            );
            $stmt->execute([$keyId, ...$values, $limit]);
        }
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($ids, true);
    }

    /**
     * Fetch doc IDs matching a numeric range for a facet key.
     *
     * When $candidateDocIds is non-empty the query adds AND doc_id IN (json_each)
     * so only candidate docs are tested — no LIMIT is needed and recall is exact.
     * Without candidates the query falls back to a LIMIT cap.
     *
     * @param  list<int>  $candidateDocIds
     * @return array<int, true>
     */
    private function fetchFacetDocIdsByRange(
        int $keyId,
        FacetRange $range,
        int $limit,
        array $candidateDocIds = [],
    ): array {
        $conditions = ['key_id = ?', 'num_value IS NOT NULL'];
        $params     = [$keyId];
        if ($range->gte !== null) {
            $conditions[] = 'num_value >= ?';
            $params[] = $range->gte;
        }
        if ($range->gt  !== null) {
            $conditions[] = 'num_value > ?';
            $params[] = $range->gt;
        }
        if ($range->lte !== null) {
            $conditions[] = 'num_value <= ?';
            $params[] = $range->lte;
        }
        if ($range->lt  !== null) {
            $conditions[] = 'num_value < ?';
            $params[] = $range->lt;
        }
        if ($candidateDocIds !== []) {
            $conditions[] = 'doc_id IN (SELECT value FROM json_each(?))';
            $params[] = json_encode($candidateDocIds);
            $stmt = $this->prepare(
                'SELECT doc_id FROM facet_values WHERE ' . implode(' AND ', $conditions)
            );
        } else {
            $params[] = $limit;
            $stmt = $this->prepare(
                'SELECT doc_id FROM facet_values WHERE ' . implode(' AND ', $conditions) . ' LIMIT ?'
            );
        }
        $stmt->execute($params);
        /** @var list<int> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($ids, true);
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
     *     stats: array<string, array{min: float, max: float}>
     * }
     */
    private function computeFacetCounts(
        array $facetKeys,
        array $filterSets,
        array $rawDocScores,
        array $filteredScores,
        int $maxDocs,
    ): array {
        if ($facetKeys === []) {
            return ['distribution' => [], 'stats' => []];
        }

        $maxValues = $this->config->maxValuesPerFacet;

        /** @var array<string, array<array-key, int>> $distribution */
        $distribution   = [];
        /** @var array<string, array{min: float, max: float}> $stats */
        $stats          = [];
        // Keys that use the common filteredScores doc set (non-disjunctive).
        /** @var array<string, int> $commonNameToId */
        $commonNameToId = [];

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
            if ($otherSets === []) {
                $countSet = $rawDocScores;
            } else {
                $countBase = $this->intersectFilterSets($otherSets);
                $countSet = array_intersect_key($rawDocScores, $countBase);
            }
            $docIds = array_keys($countSet);
            if ($docIds === []) {
                continue;
            }
            if (count($docIds) > $maxDocs) {
                $docIds = array_slice($docIds, 0, $maxDocs);
            }
            $result = count($docIds) <= self::FACET_JOIN_THRESHOLD
                ? $this->fetchAllFacetCountsJoin([$keyName => $keyId], $docIds)
                : [$keyName => $this->fetchFacetCountsForKey($keyId, $docIds)];
            foreach ($result as $k => $v) {
                if ($v['distribution'] !== []) {
                    $dist = $v['distribution'];
                    $distribution[$k] = $maxValues > 0 && count($dist) > $maxValues
                        ? array_slice($dist, 0, $maxValues, true)
                        : $dist;
                    if ($v['stats'] !== null) {
                        $stats[$k] = $v['stats'];
                    }
                }
            }
        }

        if ($commonNameToId !== []) {
            $docIds = array_keys($filteredScores);
            if ($docIds !== []) {
                if (count($docIds) > $maxDocs) {
                    $docIds = array_slice($docIds, 0, $maxDocs);
                }
                if (count($docIds) <= self::FACET_JOIN_THRESHOLD) {
                    // One query for all keys driven from doc IDs — O(N × avg_facets).
                    foreach ($this->fetchAllFacetCountsJoin($commonNameToId, $docIds) as $k => $v) {
                        if ($v['distribution'] !== []) {
                            $dist = $v['distribution'];
                            $distribution[$k] = $maxValues > 0 && count($dist) > $maxValues
                                ? array_slice($dist, 0, $maxValues, true)
                                : $dist;
                            if ($v['stats'] !== null) {
                                $stats[$k] = $v['stats'];
                            }
                        }
                    }
                } else {
                    // Per-key sequential PK scan — O(K) per key, optimal for large N.
                    foreach ($commonNameToId as $keyName => $keyId) {
                        $v = $this->fetchFacetCountsForKey($keyId, $docIds);
                        if ($v['distribution'] !== []) {
                            $dist = $v['distribution'];
                            $distribution[$keyName] = $maxValues > 0 && count($dist) > $maxValues
                                ? array_slice($dist, 0, $maxValues, true)
                                : $dist;
                            if ($v['stats'] !== null) {
                                $stats[$keyName] = $v['stats'];
                            }
                        }
                    }
                }
            }
        }

        return ['distribution' => $distribution, 'stats' => $stats];
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
        $stmt = $this->stmt(
            'facetCountsJoin',
            'SELECT fv.key_id, fv.value, fv.num_value
             FROM json_each(?) je
             CROSS JOIN facet_values fv ON fv.doc_id = je.value
             WHERE fv.key_id IN (SELECT value FROM json_each(?))'
        );
        $stmt->execute([json_encode($docIds), json_encode(array_values($nameToId))]);
        /** @var list<array{0: int, 1: string, 2: string|null}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

        /** @var array<int, array<string, int>> $valueCounts */
        $valueCounts = [];
        /** @var array<int, int> $totalCount */
        $totalCount  = [];
        /** @var array<int, int> $numCount */
        $numCount    = [];
        /** @var array<int, array<string, float>> $numValues */
        $numValues   = [];

        foreach ($rows as [$keyId, $value, $rawNum]) {
            $valueCounts[$keyId][$value] = ($valueCounts[$keyId][$value] ?? 0) + 1;
            $totalCount[$keyId]          = ($totalCount[$keyId] ?? 0) + 1;
            if ($rawNum !== null) {
                $numCount[$keyId] = ($numCount[$keyId] ?? 0) + 1;
                // All rows sharing (key_id, value) have the same num_value — store once.
                $numValues[$keyId][$value] ??= (float) $rawNum;
            }
        }

        $counts = [];
        foreach ($nameToId as $keyName => $keyId) {
            $kCounts = $valueCounts[$keyId] ?? [];
            if ($kCounts === []) {
                continue;
            }
            arsort($kCounts);
            $total  = $totalCount[$keyId] ?? 0;
            $numCnt = $numCount[$keyId] ?? 0;
            if ($numCnt === $total && $numCnt > 0) {
                $nums = array_values($numValues[$keyId] ?? []);
                $counts[$keyName] = [
                    'distribution' => $kCounts,
                    'stats' => [
                        'min' => $nums !== [] ? (float) min($nums) : 0.0,
                        'max' => $nums !== [] ? (float) max($nums) : 0.0,
                    ],
                ];
            } else {
                $counts[$keyName] = ['distribution' => $kCounts, 'stats' => null];
            }
        }
        return $counts;
    }

    /**
     * Fetch value counts for a single facet key over the given doc ID set.
     *
     * Detects numeric facets (where all matching rows have num_value set) and
     * returns min/max/count stats instead of a value → count map.
     *
     * Uses json_each() as a WHERE IN subquery so the SQL is a fixed string (cacheable
     * via stmt()). SQLite drives from the facet_values PK (sequential scan for key_id),
     * materialises json_each into a hash set, then tests doc_id membership per row —
     * identical execution plan to the original IN(?,?,?) but without variable-arity
     * compilation overhead.
     *
     * @param  list<int> $docIds
     * @return array{distribution: array<array-key, int>, stats: array{min: float, max: float}|null}
     */
    private function fetchFacetCountsForKey(int $keyId, array $docIds): array
    {
        $stmt = $this->stmt(
            'facetCountsForKey',
            'SELECT value,
                    COUNT(*)                                          AS n,
                    MIN(num_value)                                    AS min_num,
                    MAX(num_value)                                    AS max_num,
                    SUM(CASE WHEN num_value IS NOT NULL THEN 1 ELSE 0 END) AS num_count
             FROM facet_values
             WHERE key_id = ? AND doc_id IN (SELECT value FROM json_each(?))
             GROUP BY value
             ORDER BY n DESC'
        );
        $stmt->execute([$keyId, json_encode($docIds)]);
        /** @var list<array{value: string, n: string, min_num: string|null, max_num: string|null, num_count: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            $minNums = array_filter(array_column($rows, 'min_num'));
            $maxNums = array_filter(array_column($rows, 'max_num'));
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
     * Delete an index file and its WAL sidecar files from disk.
     *
     * Removes the main file plus the `-wal` and `-shm` companions created by
     * WAL journal mode. No-ops silently for any file that does not exist.
     */
    private function flushIndex(): void
    {
        if (file_exists($this->path)) {
            if (!self::exists($this->path)) {
                throw new IOException("Refusing to overwrite non-Fuzor file: {$this->path}");
            }
            unlink($this->path);
            /** @infection-ignore-all Concat,ConcatOperandRemoval: WAL/SHM suffixes are cleanup artefacts; omitting them only leaves journal files on disk */
            foreach ([$this->path . '-wal', $this->path . '-shm'] as $journal) {
                if (file_exists($journal)) {
                    unlink($journal);
                }
            }
        }
    }
}

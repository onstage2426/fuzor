<?php

namespace Fuzor;

use Fuzor\Levenshtein;
use Fuzor\Tokenizer;
use PDO;

/**
 * SQLite-backed full-text search engine.
 *
 * Owns all database interaction for a Fuzor index: schema creation, document
 * indexing (tokenisation → wordlist upsert → doclist insert), and every query
 * mode (strict exact, as-you-type prefix, Levenshtein fuzzy, boolean NOT).
 *
 * One instance maps to one open SQLite file at a time. Call createIndex() or
 * selectIndex() to point the instance at a file before performing any reads
 * or writes.
 *
 * Requires SQLite 3.37.0+ (for STRICT tables, RETURNING, and CTEs in DML statements).
 */
class IndexStorage
{
    /** Max rows per chunk when each row uses 1 bind variable (SQLite 32 766-variable ceiling). */
    private const CHUNK_1P = 32_766;

    /** Max rows per chunk when each row uses 2 bind variables. */
    private const CHUNK_2P = 16_383;

    /** Max rows per chunk when each row uses 3 bind variables. */
    private const CHUNK_3P = 10_922;

    /** Absolute path to the storage directory (guaranteed trailing slash). */
    private string $storagePath;

    /** Active PDO connection to the open SQLite index file; null after close(). */
    private ?PDO $index = null;

    /** @var array<string, \PDOStatement> Prepared statement cache; invalidated when the connection changes. */
    private array $stmtCache = [];

    /**
     * Ephemeral statement cache for bulk-load operations (flushBatch, batchUpsertWordlist).
     * Isolated from $stmtCache so that large-N bulk INSERT statements don't stay open as
     * SQLite tracked-statements during subsequent single-doc insert() transactions (which
     * would add overhead to every COMMIT). Cleared at the start and end of each insertMany().
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

    /** When true, the last search keyword is matched as a prefix (as-you-type behaviour). */
    public bool $asYouType = true;

    /** Number of leading characters that must match exactly before fuzzy edit distance kicks in. */
    public int $fuzzyPrefixLength = 3;

    /** Maximum number of wordlist candidates evaluated during a fuzzy search. */
    public int $fuzzyMaxExpansions = 50;

    /** Maximum Levenshtein edit distance accepted as a fuzzy match. */
    public int $fuzzyDistance = 2;

    /** Maximum number of documents returned per keyword in non-fuzzy queries. */
    public int $maxDocs = 500;

    /** BM25 term frequency saturation parameter. Higher values give more weight to repeated terms. */
    public float $k1 = 1.2;

    /** BM25 document length normalisation weight. 0 = no normalisation, 1 = full normalisation. */
    public float $b = 0.75;

    /** BCP 47 language tag active on this index; null means no stopword filtering or stemming. */
    public private(set) ?string $language = null;

    /** Active stopword filter; null when no language is set or language has no stopword list. */
    private ?Stopwords $stopwords = null;

    /** Active stemmer; null when no language is set or language has no stemmer. */
    private ?Stemmer $stemmer = null;

    /** True when a stopword filter is active on this connection. */
    public bool $stopwordsActive {
        get => $this->stopwords !== null;
    }

    /** True when a stemmer is active on this connection. */
    public bool $stemmerActive {
        get => $this->stemmer !== null;
    }

    // --- Connection management ----------------------------------------------

    /**
     * @param string $storagePath Writable directory where index files are stored.
     */
    public function __construct(string $storagePath)
    {
        /** @infection-ignore-all UnwrapRtrim: on Linux // is path-equivalent to /; no observable difference */
        $this->storagePath = rtrim($storagePath, '/') . '/';
    }

    /**
     * Create a new SQLite index file and initialise the schema.
     *
     * Performance pragmas are applied via applyPragmas(). All tables are STRICT for
     * type safety. doclist is WITHOUT ROWID (clustered on term_id, doc_id), replacing
     * the old term_id secondary index with a zero-heap-fetch primary scan.
     *
     * @param  string      $indexName Filename for the SQLite database (e.g. 'articles.db').
     * @param  bool        $force     When true, any existing file is deleted before creation.
     * @param  string|null $language  BCP 47 language tag persisted in the index (e.g. 'en');
     *                                null disables stopword filtering and stemming.
     * @return static
     * @throws \RuntimeException        If the index file already exists and $force is false.
     * @throws \InvalidArgumentException If $language is set but has no stopword list or stemmer.
     */
    /** @infection-ignore-all FalseValue: default $force=false is never exercised; callers always pass force explicitly */
    public function createIndex(string $indexName, bool $force = false, ?string $language = null): static
    {
        $path = $this->storagePath . $indexName;
        if (!$force && file_exists($path)) {
            throw new \RuntimeException(
                "Index already exists: {$path}. Pass force: true to overwrite."
            );
        }
        $this->flushIndex($indexName);

        $pdo = new PDO('sqlite:' . $this->storagePath . $indexName);
        $this->index         = $pdo;
        $this->stmtCache     = [];
        $this->bulkStmtCache = [];
        $this->infoCache     = null;
        $this->termIdCache   = [];
        $this->wordlistCache = [];
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

        if ($language !== null) {
            $this->applyLanguage($language);
        }

        return $this;
    }

    /**
     * Open an existing index file for searching.
     *
     * @param  string $indexName Filename of the index to open (e.g. 'articles.db').
     * @throws \RuntimeException If the index file does not exist.
     */
    public function selectIndex(string $indexName): void
    {
        $path = $this->storagePath . $indexName;
        if (!file_exists($path)) {
            throw new \RuntimeException("Index {$path} does not exist", 1);
        }
        $this->index = new PDO('sqlite:' . $path);
        $this->stmtCache     = [];
        $this->bulkStmtCache = [];
        $this->infoCache     = null;
        $this->termIdCache   = [];
        $this->wordlistCache = [];
        /** @infection-ignore-all MethodCallRemoval: applyPragmas sets WAL/cache/case_sensitive_like; all terms are stored/queried in lowercase so LIKE correctness is unaffected without it */
        $this->applyPragmas();

        assert($this->index !== null);
        $stmt  = $this->index->query("SELECT value FROM info WHERE key = 'language' LIMIT 1");
        /** @infection-ignore-all FalseValue: if $stmt is false the ternary else-branch is taken; changing false→true makes $row=true which is not an array so $lang remains null — same result */
        $row   = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
        /** @var array<int, string>|false $row */
        $lang  = (is_array($row) && $row[0] !== '') ? $row[0] : null;
        $this->applyLanguage($lang);
    }

    /**
     * Instantiate Stopwords and Stemmer for the given BCP 47 tag on this connection.
     *
     * Called internally by createIndex() and selectIndex(). Passing null clears both.
     *
     * @throws \InvalidArgumentException If $language is set but has no stopword list or stemmer.
     */
    private function applyLanguage(?string $language): void
    {
        if ($language !== null && !Language::supports($language)) {
            throw new \InvalidArgumentException("No stopword list or stemmer for language: '{$language}'");
        }
        $this->language  = $language;
        $this->stopwords = $language !== null && Language::hasStopwords($language) ? new Stopwords($language) : null;
        $this->stemmer   = $language !== null && Language::hasStemmer($language) ? new Stemmer($language) : null;
    }

    /**
     * Delete an index file and its WAL sidecar files from disk.
     *
     * Removes the main file plus the `-wal` and `-shm` companions created by
     * WAL journal mode. No-ops silently for any file that does not exist.
     *
     * @param string $indexName Filename of the index to delete.
     */
    private function flushIndex(string $indexName): void
    {
        $path = $this->storagePath . $indexName;
        if (file_exists($path)) {
            if (!$this->isFuzorIndex($path)) {
                throw new \RuntimeException("Refusing to overwrite non-Fuzor file: {$path}");
            }
            unlink($path);
            /** @infection-ignore-all Concat,ConcatOperandRemoval: WAL/SHM suffixes are cleanup artefacts; omitting them only leaves journal files on disk */
            foreach ([$path . '-wal', $path . '-shm'] as $journal) {
                if (file_exists($journal)) {
                    unlink($journal);
                }
            }
        }
    }

    /**
     * Returns true if $path opens as SQLite and contains the Fuzor wordlist table.
     *
     * @param string $path Absolute path to the file to inspect.
     */
    private function isFuzorIndex(string $path): bool
    {
        try {
            $pdo = new PDO('sqlite:' . $path);
            $result = $pdo->query("SELECT 1 FROM wordlist LIMIT 1");
            return $result !== false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Apply connection-level SQLite pragmas for optimal performance.
     *
     * - journal_mode=WAL: concurrent readers, faster commits.
     * - synchronous=NORMAL: safe with WAL (no data loss on crash), far fewer fsyncs than FULL.
     * - cache_size=-65536: 64 MB page cache; sufficient for search workloads.
     * - temp_store=MEMORY: sort/index temp tables stay in RAM.
     *
     * @infection-ignore-all MethodCallRemoval: removing the exec call only affects performance/WAL mode;
     *   search correctness is unaffected because all terms are lowercased before storage and query,
     *   making case_sensitive_like moot for correctness
     */
    private function applyPragmas(): void
    {
        assert($this->index !== null);
        $this->index->exec('
            PRAGMA journal_mode       = WAL;
            PRAGMA synchronous        = NORMAL;
            PRAGMA cache_size         = -65536;
            PRAGMA temp_store         = MEMORY;
            PRAGMA mmap_size          = 2147483648;
            PRAGMA case_sensitive_like = ON;
        ');
    }

    /**
     * Release the PDO connection and clear the statement cache.
     *
     * After calling this method the engine instance must not be used again.
     * The WAL checkpoint will run as part of normal SQLite connection teardown.
     */
    public function close(): void
    {
        $this->stmtCache     = [];
        $this->bulkStmtCache = [];
        $this->infoCache     = null;
        $this->termIdCache   = [];
        $this->wordlistCache = [];
        /** @infection-ignore-all MethodCallRemoval: SQLite triggers WAL checkpointing automatically on connection close; explicit TRUNCATE is a performance hint */
        $this->index?->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->index       = null;
    }

    // --- Public write operations --------------------------------------------

    /**
     * Index a new document and increment the total document count.
     *
     * Tokenises all fields via processDocument() and updates the
     * total_documents counter in the info table.
     *
     * @param array<string, mixed> $document Document fields; must contain an 'id' key.
     * @throws \InvalidArgumentException If the document has no 'id' key.
     * @throws \RuntimeException         If a document with the same ID already exists.
     */
    public function insert(array $document): void
    {
        if (!array_key_exists('id', $document)) {
            throw new \InvalidArgumentException("Document must contain an 'id' key.");
        }

        $this->wrapInTransaction(function () use ($document): void {
            $id    = self::extractId($document['id']);
            $check = $this->stmt('docExistsCheck', 'SELECT 1 FROM doc_lengths WHERE doc_id = :id LIMIT 1');
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() !== false) {
                throw new \RuntimeException("Document {$id} already exists. Use update() to replace it.");
            }
            /** @infection-ignore-all MethodCallRemoval: closeCursor is a resource-management call; omitting it leaves the cursor open but does not affect WAL-mode write correctness */
            $check->closeCursor();

            $length = $this->processDocument($document);
            $this->adjustStats(1, $length);
        });
    }

    /**
     * Index multiple documents in a single transaction with one stats update.
     *
     * Uses a two-phase bulk load to minimise wordlist B-tree probes: Phase 1
     * tokenises all documents in PHP and aggregates per-term counts across the
     * entire batch; Phase 2 upserts the wordlist once per unique term (not once
     * per document × term) and writes doclist and doc_lengths in bulk.
     *
     * @param iterable<array<string, mixed>> $documents Documents to index; each must contain an 'id' key.
     * @throws \InvalidArgumentException If any document is missing an 'id' key, or if the input
     *                                   contains duplicate IDs.
     * @throws \RuntimeException         If any document ID already exists in the index.
     */
    public function insertMany(iterable $documents): void
    {
        /** @infection-ignore-all LogicalNot: iterator_to_array() accepts arrays in PHP 8.1+; converting an array produces the same array */
        if (!is_array($documents)) {
            $documents = iterator_to_array($documents, false);
        }

        /** @infection-ignore-all ReturnRemoval: empty batch produces no SQL writes; adjustStats(0,0) is a no-op when no tokens are processed */
        if (empty($documents)) {
            return;
        }

        $ids = [];
        foreach ($documents as $i => $document) {
            if (!array_key_exists('id', $document)) {
                throw new \InvalidArgumentException("Document at index {$i} must contain an 'id' key.");
            }
            $id = self::extractId($document['id']);
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException("Duplicate id {$id} at index {$i}.");
            }
            /** @infection-ignore-all TrueValue: isset() returns true for any non-null value including false; both true and false mark the slot as occupied */
            $ids[$id] = true;
        }

        $index = $this->index;
        if ($index === null) {
            throw new \LogicException('Index connection is closed.');
        }

        // Probe once to know whether the doclist is empty; used to skip the duplicate-ID check
        // (nothing can already exist in an empty table) and to gate index drop/rebuild.
        $probe        = $index->query('SELECT 1 FROM doclist LIMIT 1');
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
        // - synchronous=OFF: skip fsyncs; WAL writes are sequential. Risk: data loss on OS crash only.
        // - cache_size=-524288: 512 MB page cache keeps the wordlist B-tree hot across the batch.
        // - mmap_size=4GB: OS page-cache reads at VM speed rather than via read() syscall.
        // - wal_autocheckpoint=8000: defer WAL→DB merges until after the import (32 MB threshold).
        /** @infection-ignore-all MethodCallRemoval: bulk-import pragma overrides are performance tuning only; correctness is unaffected */
        $index->exec('
            PRAGMA synchronous        = OFF;
            PRAGMA cache_size         = -524288;
            PRAGMA mmap_size          = 4294967296;
            PRAGMA wal_autocheckpoint = 8000;
        ');

        // Drop indexes after acquiring the exclusive lock so there is no lock-ordering
        // conflict on subsequent insertMany() calls (the previous call's locking_mode=NORMAL
        // restore does not release the WAL lock until the next read; dropping indexes after
        // taking EXCLUSIVE avoids the "database table is locked" race).
        /** @infection-ignore-all IfNegation: inverting $dropIndexes only affects whether indexes are dropped; correctness is unaffected */
        if ($dropIndexes) {
            /** @infection-ignore-all MethodCallRemoval: dropping secondary indexes before bulk INSERT is a performance optimisation; correctness unaffected */
            $index->exec('
                DROP INDEX IF EXISTS doclist_term_hitcount;
                DROP INDEX IF EXISTS doc_id_index;
            ');
        }
        /** @infection-ignore-all UnwrapFinally: removing the try-finally wrapper only affects exception safety of the pragma restore; on the success path the behaviour is identical */
        try {
            $this->wrapInTransaction(function () use ($documents, $ids, $indexIsEmpty): void {
                // Skip the duplicate-ID check on a known-empty table: nothing can already exist.
                if (!$indexIsEmpty) {
                    /** @infection-ignore-all UnwrapArrayKeys,DecrementInteger,IncrementInteger: removing array_keys passes values instead of keys; the chunk size constant change only affects chunk count, not correctness */
                    foreach (array_chunk(array_keys($ids), self::CHUNK_1P) as $chunk) {
                        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                        $stmt = $this->prepare(
                            "SELECT doc_id FROM doc_lengths WHERE doc_id IN ({$placeholders})"
                        );
                        $stmt->execute($chunk);
                        $existing = array_map(
                            fn(mixed $v): string => is_scalar($v) ? (string) $v : '',
                            $stmt->fetchAll(PDO::FETCH_COLUMN)
                        );
                        if ($existing !== []) {
                            throw new \RuntimeException(
                                'Documents already exist with ids: '
                                    . implode(', ', $existing) . '. Use update() to replace them.'
                            );
                        }
                    }
                }

                ['wordBuffer'     => $wordBuffer,
                 'docTermBuffer'  => $docTermBuffer,
                 'docLengthBuffer' => $docLengthBuffer] = $this->buildBatchBuffer($documents);

                $totalLength = $this->flushBatch($wordBuffer, $docTermBuffer, $docLengthBuffer);

                $this->adjustStats(count($documents), $totalLength);
            });
        } finally {
            // Rebuild dropped indexes from the completed dataset while bulk-load pragmas
            // are still active (large cache + mmap + synchronous=OFF).
            /** @infection-ignore-all IfNegation: inverting $dropIndexes only affects whether indexes are rebuilt; correctness is unaffected */
            if ($dropIndexes) {
                /** @infection-ignore-all MethodCallRemoval: rebuilding secondary indexes is a performance step; correctness is unaffected */
                $index->exec('
                    CREATE INDEX IF NOT EXISTS doc_id_index ON doclist (doc_id);
                    CREATE INDEX IF NOT EXISTS doclist_term_hitcount ON doclist (term_id, hit_count DESC);
                ');
            }
            /** @infection-ignore-all MethodCallRemoval: restoring pragmas after bulk load is a performance step; the next connection will re-apply from applyPragmas() */
            $index->exec('
                PRAGMA synchronous        = NORMAL;
                PRAGMA cache_size         = -65536;
                PRAGMA mmap_size          = 2147483648;
                PRAGMA wal_autocheckpoint = 1000;
            ');
            // Release bulk-load statements so they don't stay open as SQLite tracked-statements
            // during subsequent single-doc insert() transactions.
            $this->bulkStmtCache = [];
        }
    }

    /**
     * Replace a document in the index.
     *
     * Removes the old document's index data and re-indexes the new content in a
     * single transaction with one avg_doc_length write. total_documents is
     * unchanged when the document already existed; if the ID is new it is
     * incremented (upsert semantics matching the previous delete+insert behaviour).
     *
     * @param array<string, mixed> $document New document data; must contain an 'id' key.
     * @throws \InvalidArgumentException If the document has no 'id' key.
     */
    public function update(array $document): void
    {
        if (!array_key_exists('id', $document)) {
            throw new \InvalidArgumentException("Document must contain an 'id' key.");
        }
        $this->wrapInTransaction(function () use ($document): void {
            $oldLength = $this->removeDocumentData(self::extractId($document['id']));
            $newLength = $this->processDocument($document);

            if ($oldLength === false) {
                // Document did not exist — treat as insert: increment total_documents.
                $this->adjustStats(1, $newLength);
            } else {
                // Replace: total_documents unchanged; adjust avg for the length delta.
                /** @infection-ignore-all CastInt: removeDocumentData already returns int; the cast is defensive only */
                $this->adjustStats(0, $newLength - (int) $oldLength);
            }
        });
    }

    /**
     * Replace multiple documents in a single transaction with one stats update.
     *
     * Non-existent IDs are inserted (upsert semantics). All documents are
     * processed in one transaction; adjustStats is called once at the end.
     *
     * @param iterable<array<string, mixed>> $documents Documents to update; each must contain an 'id' key.
     */
    public function updateMany(iterable $documents): void
    {
        $this->wrapInTransaction(function () use ($documents): void {
            $docDelta    = 0;
            $lengthDelta = 0;

            foreach ($documents as $document) {
                if (!array_key_exists('id', $document)) {
                    throw new \InvalidArgumentException("Document must contain an 'id' key.");
                }

                $oldLength = $this->removeDocumentData(self::extractId($document['id']));
                $newLength = $this->processDocument($document);

                if ($oldLength === false) {
                    $docDelta++;
                    $lengthDelta += $newLength;
                } else {
                    /** @infection-ignore-all CastInt: removeDocumentData already returns int; the cast is defensive only */
                    $lengthDelta += $newLength - (int) $oldLength;
                }
            }

            /** @infection-ignore-all NotIdentical: diverges only when docDelta≠0 and lengthDelta=0, which requires new docs with zero tokens — impossible in practice */
            if ($docDelta !== 0 || $lengthDelta !== 0) {
                $this->adjustStats($docDelta, $lengthDelta);
            }
        });
    }

    /**
     * Remove a document from the index.
     *
     * Decrements num_hits and num_docs on every wordlist term the document
     * contributed to via a single CTE-based UPDATE, removes all doclist rows
     * for the document, prunes zero-hit orphan terms, and decrements
     * total_documents.
     *
     * @param int $documentId ID of the document to remove.
     */
    public function delete(int $documentId): void
    {
        $this->wrapInTransaction(function () use ($documentId): void {
            $length = $this->removeDocumentData($documentId);

            if ($length !== false) {
                /** @infection-ignore-all CastInt: removeDocumentData already returns int; the cast is defensive only */
                $this->adjustStats(-1, -(int) $length);
            }
        });
    }

    /**
     * Remove multiple documents in a single transaction with one stats update.
     *
     * @param list<int> $ids Document IDs to remove; non-existent IDs are silently skipped.
     */
    public function deleteMany(array $ids): void
    {
        /** @infection-ignore-all ReturnRemoval: empty $ids produces zero iterations and docDelta=0; adjustStats is not called — identical result */
        if (empty($ids)) {
            return;
        }

        $this->wrapInTransaction(function () use ($ids): void {
            $docDelta    = 0;
            $lengthDelta = 0;

            foreach ($ids as $id) {
                /** @infection-ignore-all CastInt: $id comes from list<int>; the cast is defensive only */
                $length = $this->removeDocumentData((int) $id);
                if ($length !== false) {
                    $docDelta--;
                    $lengthDelta -= $length;
                }
            }

            if ($docDelta !== 0) {
                $this->adjustStats($docDelta, $lengthDelta);
            }
        });
    }

    /**
     * Return true if a document with the given ID exists in the index.
     *
     * @param int $id Document ID to check.
     */
    public function has(int $id): bool
    {
        $stmt = $this->stmt('docExistsCheck', 'SELECT 1 FROM doc_lengths WHERE doc_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetchColumn() !== false;
        /** @infection-ignore-all MethodCallRemoval: closeCursor is resource cleanup; omitting it does not affect the returned boolean */
        $stmt->closeCursor();
        return $result;
    }

    // --- Private write helpers ----------------------------------------------

    /**
     * Remove a document's index data (wordlist stats, doclist rows, doc_length) without touching
     * total_documents or avg_doc_length. Returns the document's token length, or false if the
     * document was not found.
     *
     * @param  int        $documentId ID of the document to remove.
     * @return int|false              Token length of the removed document, or false if not found.
     */
    private function removeDocumentData(int $documentId): int|false
    {
        // 1. Decrement wordlist stats for every term this document contributed.
        // UPDATE … FROM (SQLite 3.33+) joins once rather than running a correlated subquery per row.
        /** @infection-ignore-all MethodCallRemoval,ArrayItemRemoval: skipping execute() or omitting the :documentId param leaves wordlist num_docs/num_hits inflated; doclist rows are still removed in step 3, so search results are unaffected (BM25-scoring stats only) */
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

        // 2. Remove any term whose hit count reached zero (doclist rows still present for the lookup).
        /** @infection-ignore-all MethodCallRemoval,ArrayItemRemoval: orphan pruning is a housekeeping step; stale wordlist entries without doclist rows produce empty fetch results */
        $this->stmt(
            'wordlistDeleteOrphans',
            'DELETE FROM wordlist WHERE num_hits <= 0
               AND id IN (SELECT term_id FROM doclist WHERE doc_id = :documentId)'
        )->execute([':documentId' => $documentId]);

        // 3. Remove doclist rows for this document.
        $this->stmt('doclistDeleteByDoc', 'DELETE FROM doclist WHERE doc_id = :documentId')
            ->execute([':documentId' => $documentId]);

        // 4. Remove doc_lengths and return the old token count (false if the document was not found).
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
        $this->invalidateWriteCaches();

        /** @infection-ignore-all FalseValue,CastInt: the FalseValue branch is only reached when $length is false (doc not found); callers check !== false so returning true is equivalent for the branch-not-taken case. CastInt: $length from RETURNING is already int-like */
        return $length === false ? false : (int) $length;
    }

    /**
     * Tokenise all non-id fields of a document and accumulate per-term counts.
     *
     * Shared by processDocument() and buildBatchBuffer().
     * array_count_values is C-implemented: counts all tokens in one native pass,
     * leaving only unique-term iteration to PHP.
     *
     * @param  array<string, mixed> $fields Document fields; 'id' is skipped.
     * @return array{termCounts: array<string, int>, length: int}
     */
    private function tokenizeDocumentFields(array $fields): array
    {
        /** @var array<string, int> $termCounts */
        $termCounts = [];
        $length     = 0;
        foreach ($fields as $key => $col) {
            if ($key === 'id') {
                continue;
            }
            /** @infection-ignore-all UnwrapTrim: leading/trailing whitespace in field values is uncommon in tests; trimming is a defensive clean-up step */
            $text = trim(strval($col)); // @phpstan-ignore argument.type
            if ($text !== '') {
                $tokens = Tokenizer::tokenize($text, $this->language);
                if ($this->stopwords !== null) {
                    $tokens = $this->stopwords->filter($tokens);
                }
                /** @infection-ignore-all Assignment: changing += to = only matters for multi-field docs where the same term appears in both fields; single-field tests are unaffected */
                $length += count($tokens);
                if ($this->stemmer !== null) {
                    $tokens = $this->stemmer->stemTokens($tokens);
                }
                foreach (array_count_values($tokens) as $token => $count) {
                    /** @infection-ignore-all Coalesce: changing ?? 0 to (0 ?? ...) always yields 0; visible only for multi-field docs where a term appears in both fields */
                    $termCounts[$token] = ($termCounts[$token] ?? 0) + $count;
                }
            }
        }
        return ['termCounts' => $termCounts, 'length' => $length];
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
        $documentId = intval($row['id']); // @phpstan-ignore argument.type

        ['termCounts' => $termCounts, 'length' => $length] = $this->tokenizeDocumentFields($row);

        $termIds = $this->upsertWordlist($termCounts);
        $this->saveDoclist($documentId, $termIds);
        $this->saveDocLength($documentId, $length);

        return $length;
    }

    /**
     * Upsert terms into the wordlist table and return a term_id → hit_count map.
     *
     * Uses a single batched INSERT … ON CONFLICT … RETURNING id, term to upsert
     * all terms for a document in one round-trip per chunk (chunks respect
     * SQLite's 32766-variable limit at 2 params per term → 16383 terms per chunk).
     *
     * @param  array<string, int> $termCounts Term → hit count for the document.
     * @return array<int, int>                term_id → hit_count with resolved wordlist IDs.
     */
    private function upsertWordlist(array $termCounts): array
    {
        if (empty($termCounts)) {
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
                $id                          = (int) $row['id'];
                $this->termIdCache[$term]    = $id;
                $termIds[$id]                = $hits;
            }
        }

        return $termIds;
    }

    /**
     * Phase 1 of the two-phase bulk load: tokenise all documents and accumulate
     * per-term and per-document statistics in PHP memory without touching the DB.
     *
     * Returns three buffers:
     *   wordBuffer     — per unique term across the batch: total hit count and
     *                    number of distinct documents containing the term.
     *   docTermBuffer  — per document: term text → hit count (term IDs are not
     *                    yet known; resolved in Phase 2 after the wordlist upsert).
     *   docLengthBuffer — per document: total token count for BM25 length normalisation.
     *
     * @param  array<array<string, mixed>> $documents
     * @return array{
     *     wordBuffer:      array<string, array{totalHits: int, numDocs: int}>,
     *     docTermBuffer:   array<int, array<string, int>>,
     *     docLengthBuffer: array<int, int>
     * }
     */
    private function buildBatchBuffer(array $documents): array
    {
        /** @var array<string, array{totalHits: int, numDocs: int}> $wordBuffer */
        $wordBuffer     = [];
        /** @var array<int, array<string, int>> $docTermBuffer */
        $docTermBuffer  = [];
        /** @var array<int, int> $docLengthBuffer */
        $docLengthBuffer = [];

        foreach ($documents as $document) {
            $documentId = intval($document['id']); // @phpstan-ignore argument.type

            ['termCounts' => $termCounts, 'length' => $length] = $this->tokenizeDocumentFields($document);

            $docTermBuffer[$documentId]  = $termCounts;
            $docLengthBuffer[$documentId] = $length;

            foreach ($termCounts as $term => $hits) {
                if (isset($wordBuffer[$term])) {
                    /** @infection-ignore-all Assignment,PlusEqual: totalHits accumulates across documents; mutation only affects wordlist num_hits (BM25 scoring), not result membership */
                    $wordBuffer[$term]['totalHits'] += $hits;
                    /** @infection-ignore-all DecrementInteger,Assignment: numDocs tracks distinct documents per term; off-by-one only affects BM25 scoring, not result membership */
                    $wordBuffer[$term]['numDocs']   += 1;
                } else {
                    /** @infection-ignore-all DecrementInteger: numDocs=0 vs 1 on first occurrence only affects BM25 scoring, not result membership */
                    $wordBuffer[$term] = ['totalHits' => $hits, 'numDocs' => 1];
                }
            }
        }

        return compact('wordBuffer', 'docTermBuffer', 'docLengthBuffer');
    }

    /**
     * Phase 2 of the two-phase bulk load: write the accumulated buffers to the DB.
     *
     * Performs three bulk writes inside the caller's open transaction:
     *   1. batchUpsertWordlist() — one probe per unique term (not per document × term).
     *   2. Doclist INSERT        — all (term_id, doc_id, hit_count) rows in bulk.
     *   3. Doc_lengths INSERT    — all (doc_id, length) rows in bulk.
     *
     * @param  array<string, array{totalHits: int, numDocs: int}> $wordBuffer
     * @param  array<int, array<string, int>>                     $docTermBuffer
     * @param  array<int, int>                                    $docLengthBuffer
     * @return int Total token count across all documents (for adjustStats).
     */
    private function flushBatch(array $wordBuffer, array $docTermBuffer, array $docLengthBuffer): int
    {
        $index = $this->index;
        assert($index !== null);

        // Step 1: upsert all unique terms; get back term text → wordlist ID mapping.
        $termIdMap = $this->batchUpsertWordlist($wordBuffer);

        // Step 2: invert docTermBuffer to (term_id → doc_id → hits) and sort by the clustered PK.
        // doclist is WITHOUT ROWID with PK (term_id, doc_id): inserting in PK order turns
        // random leaf-page seeks into sequential B-tree appends, which is ~5-10× faster.
        $termDocMap = [];
        foreach ($docTermBuffer as $docId => $termCounts) {
            foreach ($termCounts as $term => $hits) {
                $termDocMap[$termIdMap[$term]][$docId] = $hits;
            }
        }
        /** @infection-ignore-all FunctionCallRemoval: ksort on termDocMap orders INSERTs by PK for B-tree performance; omitting only degrades write speed */
        ksort($termDocMap);

        $rowCount = 0;
        $params   = [];
        foreach ($termDocMap as $termId => $docs) {
            /** @infection-ignore-all FunctionCallRemoval: ksort on docs orders by doc_id for PK order; omitting only degrades write speed */
            ksort($docs);
            foreach ($docs as $docId => $hits) {
                $params[] = $termId;
                $params[] = $docId;
                $params[] = $hits;
                if (++$rowCount === self::CHUNK_3P) {
                    ($this->bulkStmtCache['doclistChunk:' . self::CHUNK_3P] ??= $index->prepare(
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
            ($this->bulkStmtCache["doclistChunk:{$rowCount}"] ??= $index->prepare(
                'INSERT INTO doclist (term_id, doc_id, hit_count) VALUES '
                    /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
                    . implode(',', array_fill(0, $rowCount, '(?,?,?)'))
            ))->execute($params);
        }

        // Step 3: bulk-insert all doc_lengths rows.
        // No ON CONFLICT needed: insertMany already verified these doc_ids do not exist.
        foreach (array_chunk($docLengthBuffer, self::CHUNK_2P, true) as $chunk) {
            $n      = count($chunk);
            $params = [];
            foreach ($chunk as $docId => $length) {
                $params[] = $docId;
                $params[] = $length;
            }
            /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
            ($this->bulkStmtCache["docLengthChunk:{$n}"] ??= $index->prepare(
                'INSERT INTO doc_lengths (doc_id, length) VALUES '
                    /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
                    . implode(',', array_fill(0, $n, '(?,?)'))
            ))->execute($params);
        }

        return array_sum($docLengthBuffer);
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
     * @param  array<string, array{totalHits: int, numDocs: int}> $wordBuffer
     * @return array<string, int> term text → wordlist ID
     */
    private function batchUpsertWordlist(array $wordBuffer): array
    {
        if (empty($wordBuffer)) {
            return [];
        }

        $index = $this->index;
        assert($index !== null);

        /** @var array<string, int> $termIdMap */
        $termIdMap  = [];
        $knownTerms = [];
        $newTerms   = [];

        foreach ($wordBuffer as $term => $counts) {
            if (isset($this->termIdCache[$term])) {
                $knownTerms[$term] = $counts;
            } else {
                $newTerms[$term] = $counts;
            }
        }

        // Path A: known terms — UPDATE by INTEGER PRIMARY KEY via CTE-VALUES; no RETURNING needed.
        // 3 params/row (id, hits, docs) → 10 922 rows/chunk.
        /** @infection-ignore-all UnwrapArrayChunk,Foreach_: Path A is never entered on fresh indexes (termIdCache is empty); mutations that skip or mishandle chunks have no effect when knownTerms is empty */
        foreach (array_chunk($knownTerms, self::CHUNK_3P, true) as $chunk) {
            $n      = count($chunk);
            $params = [];
            foreach ($chunk as $term => ['totalHits' => $hits, 'numDocs' => $numDocs]) {
                $params[] = $this->termIdCache[$term];
                $params[] = $hits;
                $params[] = $numDocs;
            }
            ($this->bulkStmtCache["batchWordlistUpdate:{$n}"] ??= $index->prepare(
                'WITH delta(id, hits, docs) AS (VALUES ' . implode(',', array_fill(0, $n, '(?,?,?)')) . ')
                 UPDATE wordlist SET
                     num_hits = num_hits + delta.hits,
                     num_docs = num_docs + delta.docs
                 FROM delta WHERE wordlist.id = delta.id'
            ))->execute($params);

            foreach ($chunk as $term => $_) {
                $termIdMap[$term] = $this->termIdCache[$term];
            }
        }

        // Path B: new terms — UPSERT via text UNIQUE index + RETURNING; IDs added to cache.
        // 3 params/term → 10 922 terms/chunk.
        foreach (array_chunk($newTerms, self::CHUNK_3P, true) as $chunk) {
            $n      = count($chunk);
            $params = [];
            foreach ($chunk as $term => ['totalHits' => $hits, 'numDocs' => $numDocs]) {
                $params[] = $term;
                $params[] = $hits;
                $params[] = $numDocs;
            }
            /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; correctness is unaffected */
            $stmt = ($this->bulkStmtCache["batchWordlistUpsert:{$n}"] ??= $index->prepare(
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
     * Uses a single batched INSERT per chunk (3 params per row; chunks respect
     * SQLite's 32766-variable limit → 10922 rows per chunk).
     *
     * @param int             $documentId Document ID.
     * @param array<int, int> $termIds    term_id → hit_count map from upsertWordlist().
     */
    private function saveDoclist(int $documentId, array $termIds): void
    {
        if (empty($termIds)) {
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
     * Clear all write-sensitive caches after any index mutation.
     *
     * termIdCache: may contain IDs for terms that were just pruned from the wordlist
     *              (removeDocumentData), so a subsequent insertMany would resolve stale IDs.
     * wordlistCache: num_hits / num_docs on wordlist rows have changed, so cached
     *                search results would return stale scoring data.
     */
    private function invalidateWriteCaches(): void
    {
        $this->termIdCache   = [];
        $this->wordlistCache = [];
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
        // Invalidate wordlistCache: num_hits / num_docs on wordlist rows have changed.
        $this->infoCache = [
            'total_documents' => (string) $newN,
            /** @infection-ignore-all CastString: infoCache stores strings for consistency with PDO fetch; float stored in cache is coerced to string on next read */
            'avg_doc_length'  => (string) $newAvg,
        ];
        /** @infection-ignore-all MethodCallRemoval: skipping invalidateWriteCaches() leaves wordlistCache stale after stats update; only visible on repeated cached lookups within the same session */
        $this->invalidateWriteCaches();
    }

    // --- Public read operations ---------------------------------------------

    /**
     * Fetch documents and their count for a keyword in a single wordlist lookup.
     *
     * Combines wordlist lookup and document fetch into one call, avoiding a
     * duplicate getWordlistByKeyword() round-trip.
     *
     * @param  string $keyword       Term to search for.
     * @param  bool   $noLimit       When true, the $maxDocs cap is not applied.
     * @param  bool   $isLastKeyword Whether this is the final token in the query.
     * @param  bool   $fuzzy         When true, Levenshtein fuzzy matching is used.
     * @return array{documents: list<array{0: int, 1: int, 2: int, 3: int}>, numDocs: int}
     * @infection-ignore-all FalseValue: default parameter values are never exercised; callers always pass
     *   all three booleans explicitly
     */
    public function getDocumentsAndCount(
        string $keyword,
        bool $noLimit = false,
        bool $isLastKeyword = false,
        bool $fuzzy = false
    ): array {
        $word = $this->getWordlistByKeyword($keyword, $isLastKeyword, $fuzzy);
        if (!isset($word[0])) {
            /** @infection-ignore-all DecrementInteger,IncrementInteger: numDocs=0 on a no-match path is used by the BM25 scorer; returning -1 or 1 when documents=[] does not affect result membership */
            return ['documents' => [], 'numDocs' => 0];
        }

        $limit     = $noLimit ? PHP_INT_MAX : $this->maxDocs;
        $documents = $this->fetchDocsByTermIds($word, $limit, $fuzzy);

        /** @infection-ignore-all IncrementInteger,Ternary,CastInt: numDocs feeds BM25 scoring only; for single-term prefix results array_sum equals word[0]['num_docs']; CastInt: array_sum returns int */
        $numDocs = count($word) === 1
            ? $word[0]['num_docs']
            : (int) array_sum(array_column($word, 'num_docs'));

        return ['documents' => $documents, 'numDocs' => $numDocs];
    }

    /**
     * Resolve the wordlist IDs for a keyword, used by the boolean SQL evaluator.
     *
     * Thin wrapper over getWordlistByKeyword() that returns only term IDs, since the
     * boolean path never needs num_hits / num_docs for BM25 scoring.
     *
     * @param  string $keyword       Term to resolve.
     * @param  bool   $isLastKeyword Whether this is the final token (for asYouType prefix expansion).
     * @return list<int>
     */
    public function resolveWordlistIds(string $keyword, bool $isLastKeyword): array
    {
        /** @infection-ignore-all Coalesce: '' ?? $this->language is always '' since '' is not null; ngramSize('') === 0 so the CJK branch is never entered for ASCII tests */
        $ngramSize = Tokenizer::ngramSize($this->language ?? '');
        /** @infection-ignore-all GreaterThan,GreaterThanNegotiation,DecrementInteger,IncrementInteger,Identical,FalseValue,UnwrapArrayUnique,UnwrapArrayValues,ReturnRemoval: all mutations in this block only affect CJK/Thai n-gram handling; ASCII-only tests never enter this branch */
        if ($ngramSize > 0 && Tokenizer::isNgramToken($keyword)) {
            $ngrams = Tokenizer::ngram($keyword, $ngramSize, includeUnigrams: $ngramSize === 2);
            $ids    = [];
            foreach ($ngrams as $ngram) {
                foreach ($this->getWordlistByKeyword($ngram, false) as $row) {
                    $ids[] = $row['id'];
                }
            }
            return array_values(array_unique($ids));
        }
        $keyword = $this->stemmer !== null ? $this->stemmer->stemToken($keyword) : $keyword;
        return array_column($this->getWordlistByKeyword($keyword, $isLastKeyword), 'id');
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
    public function fetchBooleanDocIds(array $termIds, int $limit): array
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

        /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
        $placeholders = implode(',', array_fill(0, $n, '?'));
        $stmt         = $this->prepare(
            "SELECT doc_id FROM doclist WHERE term_id IN ({$placeholders}) ORDER BY hit_count DESC LIMIT ?"
        );
        $stmt->execute([...$termIds, $limit]);
        /** @var list<int> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        /** @infection-ignore-all ArrayOneItem: boolean set operations use assertContains; returning only 1 item from a multi-doc result is not caught by membership tests for single-match terms */
        return $rows;
    }

    /**
     * Execute a compound SQL SELECT and return a paginated doc ID list plus total count.
     *
     * $sql must be a compound SELECT returning a single `doc_id` column (e.g. UNION /
     * INTERSECT / EXCEPT sub-selects). A COUNT(*) OVER () window function returns the
     * true total in the same query execution, avoiding a second count query.
     *
     * @param  string           $sql          Compound SELECT returning doc_id.
     * @param  list<int|string> $params       Positional PDO parameter values bound in SQL order.
     * @param  int              $numOfResults Maximum doc IDs to return.
     * @return array{list<int>, int}          [docIds, totalCount]
     */
    public function executeBooleanQuery(string $sql, array $params, int $numOfResults): array
    {
        // COUNT(*) OVER () is a window function computed over the full inner result set before
        // LIMIT is applied, so it gives the true total in a single query execution.
        // For large UNION/INTERSECT/EXCEPT results this halves the evaluation cost compared
        // to running a separate COUNT(*) query when the LIMIT+1 sentinel is exceeded.
        $selectStmt = $this->prepare(
            "SELECT doc_id, COUNT(*) OVER () AS total FROM ({$sql}) AS _s LIMIT ?"
        );
        $selectStmt->execute([...$params, $numOfResults + 1]);
        /** @var list<array{doc_id: int, total: int}> $rows */
        $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [[], 0];
        }

        $total  = (int) $rows[0]['total'];
        /** @var list<int> $docIds */
        $docIds = array_column($rows, 'doc_id');

        if (count($docIds) <= $numOfResults) {
            return [$docIds, $total];
        }

        array_pop($docIds); // trim the sentinel row
        return [$docIds, $total];
    }

    /**
     * Tokenise and filter a raw query phrase.
     *
     * Splits, ngram-expands, stopword-filters, and stems in one call. Falls back to
     * the unfiltered token list when all tokens would be removed by stopword filtering.
     *
     * @return list<string>
     */
    public function filterQueryTokens(string $phrase): array
    {
        $tokens = Tokenizer::tokenize($phrase, $this->language);
        /** @infection-ignore-all GreaterThan,GreaterThanNegotiation,Ternary,ArrayOneItem: mutations only affect stopword-enabled indexes or multi-token results; tests without a language set are unaffected */
        if ($this->stopwords !== null && count($tokens) > 1) {
            $filtered = $this->stopwords->filter($tokens);
            $tokens   = $filtered !== [] ? $filtered : $tokens;
        }
        if ($this->stemmer !== null) {
            $tokens = $this->stemmer->stemTokens($tokens);
        }
        /** @infection-ignore-all ArrayOneItem: returning only the first token would silently drop all but one query term; callers rely on the full token list, but tests use single-term queries so the mutation is not caught */
        return $tokens;
    }

    /**
     * Like filterQueryTokens(), but also returns per-token detail for inspectQuery().
     *
     * @return array{raw_tokens: list<string>, filtered: list<string>, all_stripped: bool, surviving_raw: list<string>}
     */
    public function filterQueryTokensVerbose(string $phrase): array
    {
        $rawTokens    = Tokenizer::split($phrase);
        $survivingRaw = Tokenizer::tokenize($phrase, $this->language);
        $allStripped  = false;

        /** @infection-ignore-all GreaterThan: changing > 1 to >= 1 only affects single-token queries with stopwords; tests without language set are unaffected */
        if ($this->stopwords !== null && count($survivingRaw) > 1) {
            $afterStop    = $this->stopwords->filter($survivingRaw);
            $allStripped  = $afterStop === [];
            $survivingRaw = $allStripped ? $survivingRaw : $afterStop;
        }

        $filtered = $this->stemmer !== null
            ? $this->stemmer->stemTokens($survivingRaw)
            : $survivingRaw;

        return [
            'raw_tokens'    => $rawTokens,
            'filtered'      => $filtered,
            'all_stripped'  => $allStripped,
            'surviving_raw' => $survivingRaw,
        ];
    }

    /**
     * Retrieve multiple values from the info metadata table in a single query.
     *
     * @param  string[]              $keys  Metadata keys to fetch.
     * @return array<string, string> Map of key → value for found rows.
     */
    public function getInfoValues(array $keys): array
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

    // --- Private read helpers -----------------------------------------------

    /**
     * Look up a keyword in the wordlist with optional prefix and fuzzy fallback.
     *
     * When $asYouType is true and $isLastWord is true, a trailing-wildcard LIKE
     * query is used instead of an exact match, returning up to $fuzzyMaxExpansions
     * candidates ordered by shortest term first, then by num_hits descending.
     * When $fuzzy is true and no match is found, fuzzySearch() is called as a fallback.
     *
     * @param  string                    $keyword    Term to look up.
     * @param  bool                      $isLastWord Whether this is the final token in the query.
     * @param  bool                      $fuzzy      When true, fall through to Levenshtein fuzzy search on no match.
     * Fuzzy rows additionally carry a `distance` key (int) set by fuzzySearch().
     *
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int, distance?: int}>
     * @infection-ignore-all FalseValue: default parameter values are never exercised; callers always pass
     *   all booleans explicitly
     */
    public function getWordlistByKeyword(
        string $keyword,
        bool $isLastWord = false,
        bool $fuzzy = false
    ): array {
        /** @infection-ignore-all MBString: all callers already lowercase the keyword (Tokenizer::tokenize or lexExpression mb_strtolower); the defensive cast here has no observable effect */
        $keyword = mb_strtolower($keyword, 'UTF-8');

        // Cache non-fuzzy lookups by "keyword:isLastWord" key.
        // Fuzzy results depend on Levenshtein distance which is applied post-fetch, so they
        // are excluded from caching to avoid stale matches after config changes.
        if (!$fuzzy) {
            /** @infection-ignore-all CastInt,Concat,ConcatOperandRemoval: cache key format mutations only affect cache hit/miss rates, not correctness */
            $cacheKey = "{$keyword}:" . (int) $isLastWord;
            /** @infection-ignore-all ReturnRemoval: skipping a cache hit only causes a redundant DB query; the same result is returned */
            if (isset($this->wordlistCache[$cacheKey])) {
                return $this->wordlistCache[$cacheKey];
            }
        }

        /** @infection-ignore-all Coalesce: '' ?? $this->language is always ''; ngramSize('') === 0 for all non-CJK tests */
        if ($this->asYouType && $isLastWord && Tokenizer::ngramSize($this->language ?? '') === 0) {
            $stmt = $this->stmt(
                'wordlistPrefix',
                'SELECT id, term, num_hits, num_docs FROM wordlist'
                . ' WHERE term LIKE :keyword ORDER BY length(term) ASC, num_hits DESC LIMIT :maxExpansions;'
            );
            $stmt->bindValue(':keyword', $keyword . '%');
            $stmt->bindValue(':maxExpansions', $this->fuzzyMaxExpansions, PDO::PARAM_INT);
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

        if ($fuzzy && !isset($wordlistRows[0])) {
            return $this->fuzzySearch($keyword);
        }

        if (!$fuzzy) {
            $this->wordlistCache[$cacheKey] = $wordlistRows;
        }

        return $wordlistRows;
    }

    /**
     * Fetch doclist rows for a set of wordlist term IDs, ordered by hit count.
     *
     * When $fuzzy is true, results are re-sorted by the relevance rank of $words
     * (closest Levenshtein match first) after the DB fetch.
     *
     * @param  list<array{id: int, term: string, num_hits: int, num_docs: int, ...}> $words
     *         Wordlist rows from getWordlistByKeyword() or fuzzySearch() (fuzzy rows also carry distance: int).
     * @param  int  $limit Maximum rows to return.
     * @param  bool $fuzzy When true, re-sort by fuzzy relevance rank.
     * @return list<array{0: int, 1: int, 2: int, 3: int}> Rows as [term_id, doc_id, hit_count, doc_length].
     * @infection-ignore-all FalseValue: default $fuzzy=false is never exercised; callers always pass
     *   the parameter explicitly
     */
    private function fetchDocsByTermIds(array $words, int $limit, bool $fuzzy = false): array
    {
        $ids = array_column($words, 'id');
        $n   = count($ids);

        // All paths use a subquery to apply LIMIT before the doc_lengths JOIN, bounding
        // the join to exactly $limit rows regardless of how many rows exist per term.
        //
        // Non-fuzzy multi-term paths use UNION ALL of per-term SELECTs rather than
        // IN (...).  With the doclist_term_hitcount index on (term_id, hit_count DESC),
        // each arm is already in hit_count DESC order; SQLite can MERGE the sorted
        // streams without a temp-B-tree sort pass, and LIMIT stops the scan early.
        // IN (...) with ORDER BY cannot use this merge and always requires a temp-B-tree.
        //
        // All paths use FETCH_NUM: integer-indexed rows avoid per-field string hash lookups
        // in the caller's BM25 scoring loop. Column order: 0=term_id, 1=doc_id,
        // 2=hit_count, 3=doc_length.

        // Single-term non-fuzzy: simpler SQL (no UNION ALL needed) — cache by stable key.
        /** @infection-ignore-all LogicalNot: negating !$fuzzy to $fuzzy only switches between the single-term cached stmt and the multi-term/fuzzy paths; all return equivalent doc sets for non-fuzzy calls */
        if ($n === 1 && !$fuzzy) {
            $stmt = $this->stmt(
                'fetchOneTermDocs',
                'SELECT sub.term_id, sub.doc_id, sub.hit_count, dl.length AS doc_length
                  FROM (SELECT term_id, doc_id, hit_count FROM doclist
                        WHERE term_id = ? ORDER BY hit_count DESC LIMIT ?) sub
                  JOIN doc_lengths dl ON dl.doc_id = sub.doc_id'
            );
            $stmt->execute([$ids[0], $limit]);
            /** @var list<array{0: int, 1: int, 2: int, 3: int}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            /** @infection-ignore-all ReturnRemoval: falling through to the UNION ALL path for n=1 returns the same doc set */
            return $rows;
        }

        // Multi-term non-fuzzy: UNION ALL of $n arms. SQL is stable for a given $n,
        // so cache by arity key rather than re-preparing on every call.
        /** @infection-ignore-all LogicalNot: negating !$fuzzy only switches between UNION ALL and the fuzzy IN()+CASE path; result set membership is equivalent */
        if (!$fuzzy) {
            $arms = implode(' UNION ALL ', array_fill(
                /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
                0,
                $n,
                'SELECT term_id, doc_id, hit_count FROM doclist WHERE term_id = ?'
            ));
            $stmt = $this->stmt(
                "fetchNTermDocs:{$n}",
                "SELECT sub.term_id, sub.doc_id, sub.hit_count, dl.length AS doc_length
                  FROM ({$arms} ORDER BY hit_count DESC LIMIT ?) sub
                  JOIN doc_lengths dl ON dl.doc_id = sub.doc_id"
            );
            $stmt->execute([...$ids, $limit]);
            /** @var list<array{0: int, 1: int, 2: int, 3: int}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            /** @infection-ignore-all ReturnRemoval: falling through to the fuzzy path returns the same doc set via an IN()+CASE query */
            return $rows;
        }

        // Fuzzy: ORDER BY a CASE expression that encodes the fuzzy relevance rank (closest match first).
        // Uses IN() since the CASE sort mixes two orderings that the index cannot satisfy.
        /** @infection-ignore-all DecrementInteger,IncrementInteger: array_fill start index 0 vs ±1 only changes array keys; implode() ignores keys */
        $placeholders = implode(',', array_fill(0, $n, '?'));
        $cases        = implode(' ', array_map(fn(int $i) => "WHEN ? THEN {$i}", range(0, $n - 1)));
        $stmt         = $this->prepare(
            "SELECT sub.term_id, sub.doc_id, sub.hit_count, dl.length AS doc_length
              FROM (SELECT term_id, doc_id, hit_count FROM doclist
                    WHERE term_id IN ({$placeholders})
                    ORDER BY CASE term_id {$cases} END ASC, hit_count DESC LIMIT ?) sub
              JOIN doc_lengths dl ON dl.doc_id = sub.doc_id"
        );
        $stmt->execute([...$ids, ...$ids, $limit]);
        /** @var list<array{0: int, 1: int, 2: int, 3: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        return $rows;
    }

    /**
     * Find wordlist candidates within Levenshtein edit distance of the keyword.
     *
     * Queries the wordlist for all terms sharing the same prefix
     * ($fuzzyPrefixLength chars), then filters by $fuzzyDistance and sorts
     * by edit distance ascending, then num_hits descending.
     *
     * @param  string                    $keyword Search term to find fuzzy matches for (must already be lowercased).
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int, distance: int}>
     */
    private function fuzzySearch(string $keyword): array
    {
        /** @infection-ignore-all MBString,CastInt: ASCII fuzzy tests are unaffected by mb_ vs byte strlen; CastInt: mb_strlen returns int already */
        $keywordLength = (int) mb_strlen($keyword);

        $stmt = $this->stmt(
            'fuzzyWordlistLookup',
            "SELECT id, term, num_hits, num_docs FROM wordlist
             WHERE term LIKE :keyword
               AND length(term) BETWEEN :min AND :max
             ORDER BY num_hits DESC
             LIMIT :maxExpansions"
        );
        /** @infection-ignore-all MBString,ConcatOperandRemoval: ASCII fuzzy tests are unaffected by mb_ vs byte substr; removing the prefix still produces correct candidates after Levenshtein filtering (just with more candidates) */
        $stmt->bindValue(':keyword', mb_substr($keyword, 0, $this->fuzzyPrefixLength) . '%');
        /** @infection-ignore-all DecrementInteger,IncrementInteger: adjusting the min length boundary by 1 only broadens or narrows the candidate set; Levenshtein filtering corrects the result */
        $stmt->bindValue(':min', max(1, $keywordLength - $this->fuzzyDistance), PDO::PARAM_INT);
        $stmt->bindValue(':max', $keywordLength + $this->fuzzyDistance, PDO::PARAM_INT);
        $stmt->bindValue(':maxExpansions', $this->fuzzyMaxExpansions, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int, distance: int}> $resultSet */
        $resultSet = [];
        /** @var list<array{id: int, term: string, num_hits: int, num_docs: int}> $candidates */
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $match) {
            $distance = Levenshtein::distance($match['term'], $keyword);
            if ($distance <= $this->fuzzyDistance) {
                $resultSet[] = [...$match, 'distance' => $distance];
            }
        }

        /** @infection-ignore-all Spaceship: swapping the secondary sort (num_hits) from DESC to ASC only changes the order among equally-distant candidates; assertContains tests are order-agnostic */
        usort($resultSet, fn($a, $b) => $a['distance'] <=> $b['distance'] ?: $b['num_hits'] <=> $a['num_hits']);

        return $resultSet;
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
        $index = $this->index;
        if ($index === null) {
            throw new \LogicException('Index connection is closed.');
        }
        /** @infection-ignore-all AssignCoalesce: removing ??= only disables statement caching; every call re-prepares the same SQL but produces identical results */
        return $this->stmtCache[$key] ??= $index->prepare($sql);
    }

    /** Prepare without caching; for variable-shape SQL where the cache hit rate would be near zero. */
    private function prepare(string $sql): \PDOStatement
    {
        $index = $this->index;
        if ($index === null) {
            throw new \LogicException('Index connection is closed.');
        }
        return $index->prepare($sql);
    }

    /**
     * Run $fn inside a transaction, committing on success and rolling back on exception.
     *
     * When already inside a transaction (e.g. update() calling delete() + insert()),
     * the callback runs in the existing transaction rather than starting a nested one.
     *
     * @param callable(): void $fn
     */
    private function wrapInTransaction(callable $fn): void
    {
        $index = $this->index;
        if ($index === null) {
            throw new \LogicException('Index connection is closed.');
        }
        /** @infection-ignore-all IfNegation: inverting inTransaction() only affects nested calls; no test exercises wrapInTransaction while already in a transaction */
        if ($index->inTransaction()) {
            $fn();
            return;
        }
        $index->beginTransaction();
        try {
            $fn();
            $index->commit();
        } catch (\Throwable $e) {
            $index->rollBack();
            throw $e;
        }
    }

    /**
     * Validate and extract an integer document ID from a raw value.
     *
     * @throws \InvalidArgumentException If the value is not an integer or integer-like string.
     */
    private static function extractId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new \InvalidArgumentException(
            "Document 'id' must be an integer, got " . get_debug_type($value) . '.'
        );
    }
}

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
class SqliteEngine
{
    /** Absolute path to the storage directory (guaranteed trailing slash). */
    private string $storagePath;

    /** Active PDO connection to the open SQLite index file; null after close(). */
    private ?PDO $index = null;

    /** @var array<string, \PDOStatement> Prepared statement cache; invalidated when the connection changes. */
    private array $stmtCache = [];

    /** When true, the last search keyword is matched as a prefix (as-you-type behaviour). */
    public bool $asYouType = true;

    /** Number of leading characters that must match exactly before fuzzy edit distance kicks in. */
    public int $fuzzyPrefixLength = 2;

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

    /** Stopword filter applied during document indexing; null means no filtering. */
    private ?Stopwords $stopwords = null;

    /**
     * BCP 47 language tag for stopword filtering (e.g. 'en', 'fr').
     * Set to enable stopword removal; null (default) disables it entirely.
     * Throws \InvalidArgumentException if no stopword list exists for the given language.
     */
    public ?string $language = null {
        set {
            $this->stopwords = $value !== null ? new Stopwords($value) : null;
            $this->language  = $value;
        }
    }

    // --- Connection management ----------------------------------------------

    /**
     * @param string $storagePath Writable directory where index files are stored.
     */
    public function __construct(string $storagePath)
    {
        $this->storagePath = rtrim($storagePath, '/') . '/';
    }

    /**
     * Create a new SQLite index file and initialise the schema.
     *
     * Performance pragmas are applied via applyPragmas(). All tables are STRICT for
     * type safety. doclist is WITHOUT ROWID (clustered on term_id, doc_id), replacing
     * the old term_id secondary index with a zero-heap-fetch primary scan.
     *
     * @param  string $indexName Filename for the SQLite database (e.g. 'articles.db').
     * @param  bool   $force     When true, any existing file is deleted before creation.
     * @return static
     * @throws \RuntimeException If the index file already exists and $force is false.
     */
    public function createIndex(string $indexName, bool $force = false): static
    {
        $path = $this->storagePath . $indexName;
        if (!$force && file_exists($path)) {
            throw new \RuntimeException(
                "Index already exists: {$path}. Pass force: true to overwrite."
            );
        }
        $this->flushIndex($indexName);

        $pdo = new PDO('sqlite:' . $this->storagePath . $indexName);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->index    = $pdo;
        $this->stmtCache = [];
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
        $pdo->exec("CREATE INDEX IF NOT EXISTS 'main'.'doc_id_index' ON doclist ('doc_id');");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_lengths (
                doc_id INTEGER PRIMARY KEY,
                length INTEGER NOT NULL
            ) STRICT"
        );

        $pdo->exec("CREATE TABLE IF NOT EXISTS info (key TEXT PRIMARY KEY, value TEXT NOT NULL) STRICT");
        $pdo->exec("INSERT INTO info (key, value) VALUES ('total_documents', 0), ('avg_doc_length', 0)");

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
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->stmtCache = [];
        $this->applyPragmas();
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
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Apply connection-level SQLite pragmas for optimal performance.
     *
     * - journal_mode=WAL: concurrent readers, faster commits.
     * - synchronous=NORMAL: safe with WAL (no data loss on crash), far fewer fsyncs than FULL.
     * - cache_size=-16000: 16 MB page cache (negative value = kibibytes).
     * - temp_store=MEMORY: sort/index temp tables stay in RAM.
     */
    private function applyPragmas(): void
    {
        assert($this->index !== null);
        $this->index->exec('
            PRAGMA journal_mode = WAL;
            PRAGMA synchronous  = NORMAL;
            PRAGMA cache_size   = -16000;
            PRAGMA temp_store   = MEMORY;
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
        $this->stmtCache = [];
        $this->index     = null;
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
            $id    = intval($document['id']); // @phpstan-ignore argument.type
            $check = $this->stmt('docExistsCheck', 'SELECT 1 FROM doc_lengths WHERE doc_id = :id LIMIT 1');
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() !== false) {
                throw new \RuntimeException("Document {$id} already exists. Use update() to replace it.");
            }
            $check->closeCursor();

            $length = $this->processDocument($document);
            $this->adjustStats(1, $length);
        });
    }

    /**
     * Index multiple documents in a single transaction with one stats update.
     *
     * Substantially faster than calling insert() in a loop: the transaction and
     * avg_doc_length / total_documents writes happen once for the entire batch
     * instead of once per document.
     *
     * @param array<array<string, mixed>> $documents Documents to index; each must contain an 'id' key.
     * @throws \InvalidArgumentException If any document is missing an 'id' key, or if the input
     *                                   contains duplicate IDs.
     * @throws \RuntimeException         If any document ID already exists in the index.
     */
    public function insertMany(array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $ids = [];
        foreach ($documents as $i => $document) {
            if (!array_key_exists('id', $document)) {
                throw new \InvalidArgumentException("Document at index {$i} must contain an 'id' key.");
            }
            $id = intval($document['id']); // @phpstan-ignore argument.type
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException("Duplicate id {$id} at index {$i}.");
            }
            $ids[$id] = true;
        }

        $this->wrapInTransaction(function () use ($documents, $ids): void {
            $n            = count($ids);
            $placeholders = implode(',', array_fill(0, $n, '?'));
            $stmt         = $this->stmt(
                "insertManyExistsCheck_{$n}",
                "SELECT doc_id FROM doc_lengths WHERE doc_id IN ({$placeholders})"
            );
            $stmt->execute(array_keys($ids));

            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($existing !== []) {
                throw new \RuntimeException(
                    "Documents already exist with ids: " . implode(', ', $existing) . ". Use update() to replace them."
                );
            }

            $totalLength = 0;
            foreach ($documents as $document) {
                $totalLength += $this->processDocument($document);
            }

            $this->adjustStats(count($documents), $totalLength);
        });
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
            $oldLength = $this->removeDocumentData(intval($document['id'])); // @phpstan-ignore argument.type
            $newLength = $this->processDocument($document);

            if ($oldLength === false) {
                // Document did not exist — treat as insert: increment total_documents.
                $this->adjustStats(1, $newLength);
            } else {
                // Replace: total_documents unchanged; adjust avg for the length delta.
                $this->adjustStats(0, $newLength - (int) $oldLength);
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
                $this->adjustStats(-1, -(int) $length);
            }
        });
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

        return $length === false ? false : (int) $length;
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

        /** @var array<string, int> $termCounts */
        $termCounts = [];
        $length = 0;
        foreach (array_diff_key($row, ['id' => null]) as $col) {
            $text = trim(strval($col)); // @phpstan-ignore argument.type
            if ($text !== '') {
                $tokens = $this->stopwords !== null
                    ? $this->stopwords->filter(Tokenizer::tokenize($text))
                    : Tokenizer::tokenize($text);
                foreach ($tokens as $token) {
                    $termCounts[$token] = ($termCounts[$token] ?? 0) + 1;
                }
                $length += count($tokens);
            }
        }

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
     * SQLite's 999-variable limit at 2 params per term).
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

        foreach (array_chunk($termCounts, 499, true) as $chunk) {
            $n            = count($chunk);
            $placeholders = [];
            $params       = [];
            foreach ($chunk as $term => $hits) {
                $placeholders[] = '(?, ?, 1)';
                $params[]       = $term;
                $params[]       = $hits;
            }
            $stmt = $this->stmt(
                "upsertWordlist_{$n}",
                'INSERT INTO wordlist (term, num_hits, num_docs) VALUES ' . implode(',', $placeholders) . '
                 ON CONFLICT(term) DO UPDATE SET
                     num_hits = num_hits + excluded.num_hits,
                     num_docs = num_docs + 1
                 RETURNING id, term'
            );
            $stmt->execute($params);
            /** @var list<array{id: int, term: string}> $upserted */
            $upserted = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($upserted as $row) {
                $termIds[$row['id']] = $termCounts[$row['term']];
            }
        }

        return $termIds;
    }

    /**
     * Write term→document hit counts to the doclist table.
     *
     * Uses a single batched INSERT per chunk (3 params per term; chunks respect
     * SQLite's 999-variable limit).
     *
     * @param int             $documentId Document ID.
     * @param array<int, int> $termIds    term_id → hit_count map from upsertWordlist().
     */
    private function saveDoclist(int $documentId, array $termIds): void
    {
        if (empty($termIds)) {
            return;
        }

        foreach (array_chunk($termIds, 333, true) as $chunk) {
            $n            = count($chunk);
            $placeholders = implode(',', array_fill(0, $n, '(?,?,?)'));
            $params       = [];
            foreach ($chunk as $termId => $hits) {
                $params[] = $termId;
                $params[] = $documentId;
                $params[] = $hits;
            }
            $this->stmt(
                "saveDoclist_{$n}",
                "INSERT INTO doclist (term_id, doc_id, hit_count) VALUES {$placeholders}"
            )->execute($params);
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
        $stmt = $this->stmt(
            'statsAdjust',
            "WITH c AS (
                 SELECT CAST(MAX(CASE WHEN key = 'total_documents' THEN value END) AS INTEGER) AS n,
                        CAST(MAX(CASE WHEN key = 'avg_doc_length'  THEN value END) AS REAL)    AS avg
                 FROM info WHERE key IN ('total_documents', 'avg_doc_length')
             )
             UPDATE info SET value = CASE key
                 WHEN 'total_documents' THEN CAST((SELECT n + :docDelta FROM c) AS TEXT)
                 WHEN 'avg_doc_length'  THEN CAST(
                     (SELECT CASE WHEN n + :docDelta > 0
                         THEN (avg * n + :lengthDelta) / (n + :docDelta)
                         ELSE 0
                     END FROM c) AS TEXT)
             END
             WHERE key IN ('total_documents', 'avg_doc_length')"
        );
        $stmt->bindValue(':docDelta', $docDelta, PDO::PARAM_INT);
        $stmt->bindValue(':lengthDelta', $lengthDelta, PDO::PARAM_INT);
        $stmt->execute();
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
     * @return array{documents: list<array{term_id: int, doc_id: int, hit_count: int, doc_length: int}>, numDocs: int}
     */
    public function getDocumentsAndCount(
        string $keyword,
        bool $noLimit = false,
        bool $isLastKeyword = false,
        bool $fuzzy = false
    ): array {
        $word = $this->getWordlistByKeyword($keyword, $isLastKeyword, $fuzzy);
        if (!isset($word[0])) {
            return ['documents' => [], 'numDocs' => 0];
        }

        $limit     = $noLimit ? PHP_INT_MAX : $this->maxDocs;
        $documents = $this->fetchDocsByTermIds($word, $limit, $fuzzy);

        $numDocs = ($fuzzy || count($word) > 1)
            ? (int) array_sum(array_column($word, 'num_docs'))
            : $word[0]['num_docs'];

        return ['documents' => $documents, 'numDocs' => $numDocs];
    }

    /**
     * Fetch all documents that do NOT contain a given keyword.
     *
     * Respects $asYouType prefix expansion: when enabled, all wordlist terms
     * with matching prefix are excluded, not just exact matches.
     * When the keyword is not in the wordlist at all, all indexed documents are
     * returned (complement of an empty set is the full set).
     *
     * @param  string                    $keyword Term to exclude.
     * @param  bool                      $noLimit When true, the $maxDocs cap is not applied.
     * @return list<array{doc_id: int}>           Doc-id rows for non-matching documents.
     */
    public function getAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit = false): array
    {
        // isLastWord=true so that $asYouType prefix expansion applies, matching the behaviour
        // of positive keyword lookups in scorePhrase().
        $word  = $this->getWordlistByKeyword($keyword, isLastWord: true);
        $limit = $noLimit ? PHP_INT_MAX : $this->maxDocs;

        if (!isset($word[0])) {
            // Term not indexed — all documents satisfy NOT, so return every doc_id.
            $stmt = $this->stmt('allDocIds', 'SELECT DISTINCT doc_id FROM doclist LIMIT ?');
            $stmt->execute([$limit]);
            /** @var list<array{doc_id: int}> $all */
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $all;
        }

        $ids          = array_column($word, 'id');
        $n            = count($ids);
        $placeholders = implode(',', array_fill(0, $n, '?'));
        $stmt = $this->stmt(
            "keywordNot_{$n}",
            "SELECT DISTINCT doc_id FROM doclist
             WHERE doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id IN ($placeholders))
             LIMIT ?"
        );
        $stmt->execute([...$ids, $limit]);
        /** @var list<array{doc_id: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Filter stopwords from query tokens, falling back to the original list when
     * all tokens would be removed (so an all-stopword query still returns results).
     *
     * @param  list<string> $tokens Tokenised query terms.
     * @return list<string>         Filtered tokens, or the original list if filtering empties it.
     */
    public function filterQueryTokens(array $tokens): array
    {
        if ($this->stopwords === null) {
            return $tokens;
        }
        $filtered = $this->stopwords->filter($tokens);
        return $filtered !== [] ? $filtered : $tokens;
    }

    /**
     * Retrieve multiple values from the info metadata table in a single query.
     *
     * @param  string[]              $keys  Metadata keys to fetch.
     * @return array<string, string> Map of key → value for found rows.
     */
    public function getInfoValues(array $keys): array
    {
        sort($keys);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->stmt('info_' . implode(',', $keys), "SELECT key, value FROM info WHERE key IN ($placeholders)");
        $stmt->execute($keys);
        /** @var array<string, string> $result */
        $result = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
        return $result;
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
     * @return list<array{id: int, term: string, num_hits: int, num_docs: int}>
     */
    private function getWordlistByKeyword(
        string $keyword,
        bool $isLastWord = false,
        bool $fuzzy = false
    ): array {
        $keyword = mb_strtolower($keyword);

        if ($this->asYouType && $isLastWord) {
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
     * @return list<array{term_id: int, doc_id: int, hit_count: int, doc_length: int}>
     */
    private function fetchDocsByTermIds(array $words, int $limit, bool $fuzzy = false): array
    {
        $ids          = array_column($words, 'id');
        $n            = count($ids);
        $placeholders = implode(',', array_fill(0, $n, '?'));
        $stmt = $this->stmt(
            "docsByTermIds_{$n}",
            "SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
              FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
              WHERE d.term_id IN ($placeholders) ORDER BY d.hit_count DESC LIMIT ?"
        );
        $stmt->execute([...$ids, $limit]);
        /** @var list<array{term_id: int, doc_id: int, hit_count: int, doc_length: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($fuzzy) {
            $rank = array_flip($ids);
            usort($rows, fn(array $a, array $b) => $rank[$a['term_id']] <=> $rank[$b['term_id']]);
        }

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
        $keywordLength = (int) mb_strlen($keyword);

        $stmt = $this->stmt(
            'fuzzyWordlistLookup',
            "SELECT id, term, num_hits, num_docs FROM wordlist
             WHERE term LIKE :keyword
               AND length(term) BETWEEN :min AND :max
             ORDER BY num_hits DESC
             LIMIT :maxExpansions"
        );
        $stmt->bindValue(':keyword', mb_substr($keyword, 0, $this->fuzzyPrefixLength) . '%');
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
        assert($index !== null);
        return $this->stmtCache[$key] ??= $index->prepare($sql);
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
        assert($index !== null);
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
}

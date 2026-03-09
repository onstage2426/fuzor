<?php

namespace Fuzor\Engines;

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
 * Requires SQLite 3.35.0+ (for RETURNING and CTEs in DML statements).
 */
class SqliteEngine
{
    /** Absolute path to the storage directory (guaranteed trailing slash). */
    private string $storagePath;

    /** Active PDO connection to the open SQLite index file. */
    private PDO $index;

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
     * Any existing file with the same name is deleted first. Performance pragmas
     * are applied via applyPragmas(), and indexes are created on both
     * wordlist(term) and doclist(term_id, doc_id).
     *
     * @param  string $indexName Filename for the SQLite database (e.g. 'articles.db').
     * @return static
     */
    public function createIndex(string $indexName): static
    {
        $this->flushIndex($indexName);

        $this->index = new PDO('sqlite:' . $this->storagePath . $indexName);
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->stmtCache = [];
        $this->applyPragmas();

        $this->index->exec(
            "CREATE TABLE IF NOT EXISTS wordlist (
                id INTEGER PRIMARY KEY,
                term TEXT UNIQUE,
                num_hits INTEGER,
                num_docs INTEGER)"
        );
        $this->index->exec(
            "CREATE TABLE IF NOT EXISTS doclist (
                term_id INTEGER,
                doc_id INTEGER,
                hit_count INTEGER)"
        );
        $this->index->exec("CREATE INDEX IF NOT EXISTS 'main'.'term_id_index' ON doclist ('term_id' COLLATE BINARY);");
        $this->index->exec("CREATE INDEX IF NOT EXISTS 'main'.'doc_id_index' ON doclist ('doc_id');");

        $this->index->exec(
            "CREATE TABLE IF NOT EXISTS doc_lengths (
                doc_id INTEGER PRIMARY KEY,
                length INTEGER)"
        );

        $this->index->exec("CREATE TABLE IF NOT EXISTS info (key TEXT PRIMARY KEY, value TEXT)");
        $this->index->exec("INSERT INTO info (key, value) VALUES ('total_documents', 0), ('avg_doc_length', 0)");

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
        $this->index->exec('
            PRAGMA journal_mode = WAL;
            PRAGMA synchronous  = NORMAL;
            PRAGMA cache_size   = -16000;
            PRAGMA temp_store   = MEMORY;
        ');
    }

    // --- Public write operations --------------------------------------------

    /**
     * Index a new document and increment the total document count.
     *
     * Tokenises all fields via processDocument() and updates the
     * total_documents counter in the info table.
     *
     * @param array<string, mixed> $document Document fields; must contain an 'id' key.
     */
    public function insert(array $document): void
    {
        $this->wrapInTransaction(function () use ($document): void {
            $length   = $this->processDocument($document);
            $info     = $this->getInfoValues(['avg_doc_length']);
            $oldAvg   = (float) ($info['avg_doc_length'] ?? 0);
            $newCount = $this->adjustTotalDocuments(1);
            $oldCount = $newCount - 1;
            $this->stmt(
                'updateAvgDocLength',
                "UPDATE info SET value = :value WHERE key = 'avg_doc_length'"
            )->execute([':value' => ($oldAvg * $oldCount + $length) / $newCount]);
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
     */
    public function insertMany(array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $this->wrapInTransaction(function () use ($documents): void {
            $info     = $this->getInfoValues(['avg_doc_length', 'total_documents']);
            $oldAvg   = (float) ($info['avg_doc_length'] ?? 0);
            $oldCount = (int) ($info['total_documents'] ?? 0);

            $totalLength = 0;
            foreach ($documents as $document) {
                $totalLength += $this->processDocument($document);
            }

            $newCount = $oldCount + count($documents);
            $newAvg   = ($oldAvg * $oldCount + $totalLength) / $newCount;

            $this->stmt('setTotalDocuments', "UPDATE info SET value = :value WHERE key = 'total_documents'")
                ->execute([':value' => $newCount]);
            $this->stmt('updateAvgDocLength', "UPDATE info SET value = :value WHERE key = 'avg_doc_length'")
                ->execute([':value' => $newAvg]);
        });
    }

    /**
     * Replace a document in the index.
     *
     * Equivalent to delete($id) followed by insert($document). The total
     * document count is unchanged: delete decrements it, insert increments it.
     *
     * @param array<string, mixed> $document New document data; must contain an 'id' key.
     */
    public function update(array $document): void
    {
        $this->wrapInTransaction(function () use ($document): void {
            $this->delete($document['id']);
            $this->insert($document);
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
            // Decrement wordlist stats and collect IDs of newly-orphaned terms (num_hits → 0).
            $updateStmt = $this->stmt(
                'wordlistDecrementByDoc',
                'WITH doc_terms AS (
                     SELECT term_id, hit_count FROM doclist WHERE doc_id = :documentId
                 )
                 UPDATE wordlist SET
                     num_docs = num_docs - 1,
                     num_hits = num_hits - (SELECT hit_count FROM doc_terms WHERE term_id = wordlist.id)
                 WHERE id IN (SELECT term_id FROM doc_terms)
                 RETURNING id, num_hits'
            );
            $updateStmt->execute([':documentId' => $documentId]);
            $orphanIds = array_column(
                array_filter($updateStmt->fetchAll(PDO::FETCH_ASSOC), fn($row) => (int) $row['num_hits'] === 0),
                'id'
            );

            $this->stmt('doclistDeleteByDoc', 'DELETE FROM doclist WHERE doc_id = :documentId')
                ->execute([':documentId' => $documentId]);

            // Targeted delete: only remove terms that became orphans, avoiding a full table scan.
            if ($orphanIds) {
                $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
                $this->index->prepare("DELETE FROM wordlist WHERE id IN ($placeholders)")->execute($orphanIds);
            }

            $delStmt = $this->stmt(
                'docLengthsDelete',
                'DELETE FROM doc_lengths WHERE doc_id = :documentId RETURNING length'
            );
            $delStmt->execute([':documentId' => $documentId]);
            $length = $delStmt->fetchColumn();
            $delStmt->closeCursor();

            if ($length !== false) {
                $length  = (int) $length;
                $info    = $this->getInfoValues(['avg_doc_length']);
                $oldAvg  = (float) ($info['avg_doc_length'] ?? 0);
                $newCount = $this->adjustTotalDocuments(-1);
                $oldCount = $newCount + 1;
                $newAvg   = $newCount > 0 ? ($oldAvg * $oldCount - $length) / $newCount : 0;
                $this->stmt(
                    'updateAvgDocLength',
                    "UPDATE info SET value = :value WHERE key = 'avg_doc_length'"
                )->execute([':value' => $newAvg]);
            }
        });
    }

    // --- Private write helpers ----------------------------------------------

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
        $documentId = $row['id'];

        $fieldTokens  = [];
        $length = 0;
        foreach (array_diff_key($row, ['id' => null]) as $col) {
            $text = trim((string) $col);
            if ($text !== '') {
                $tokens  = Tokenizer::tokenize($text);
                $fieldTokens[] = $tokens;
                $length += count($tokens);
            }
        }

        $this->saveDoclist($documentId, $this->saveWordlist($fieldTokens));
        $this->saveDocLength($documentId, $length);

        return $length;
    }

    /**
     * Upsert terms from tokenised document stems into the wordlist table.
     *
     * Uses a single batched INSERT … ON CONFLICT … RETURNING id, term to upsert
     * all terms for a document in one round-trip per chunk (chunks respect
     * SQLite's 999-variable limit at 2 params per term).
     *
     * @param  list<string[]> $fieldTokens Tokenised fields as a list of token arrays.
     * @return array<string, array{hits: int, id: int|string}> Terms with resolved wordlist IDs.
     */
    private function saveWordlist(array $fieldTokens): array
    {
        /** @var array<string, array{hits: int, id: int|string}> $terms */
        $terms = [];

        foreach ($fieldTokens as $tokens) {
            foreach ($tokens as $term) {
                if (array_key_exists($term, $terms)) {
                    $terms[$term]['hits']++;
                } else {
                    $terms[$term] = ['hits' => 1, 'id' => 0];
                }
            }
        }

        if (empty($terms)) {
            return $terms;
        }

        foreach (array_chunk(array_keys($terms), 499) as $chunk) {
            $placeholders = [];
            $params       = [];
            foreach ($chunk as $i => $key) {
                $placeholders[] = "(:k{$i}, :h{$i}, 1)";
                $params[":k{$i}"] = $key;
                $params[":h{$i}"] = $terms[$key]['hits'];
            }
            $stmt = $this->index->prepare(
                'INSERT INTO wordlist (term, num_hits, num_docs) VALUES ' . implode(',', $placeholders) . '
                 ON CONFLICT(term) DO UPDATE SET
                     num_hits = num_hits + excluded.num_hits,
                     num_docs = num_docs + 1
                 RETURNING id, term'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $terms[$row['term']]['id'] = $row['id'];
            }
        }

        return $terms;
    }

    /**
     * Write term→document hit counts to the doclist table.
     *
     * Uses a single batched INSERT per chunk (3 params per term; chunks respect
     * SQLite's 999-variable limit).
     *
     * @param int                                               $documentId Document ID.
     * @param array<string, array{hits: int, id: int|string}> $terms Terms with resolved wordlist IDs.
     */
    private function saveDoclist(int $documentId, array $terms): void
    {
        if (empty($terms)) {
            return;
        }

        foreach (array_chunk(array_values($terms), 333) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?)'));
            $params = [];
            foreach ($chunk as $term) {
                $params[] = $term['id'];
                $params[] = $documentId;
                $params[] = $term['hits'];
            }
            $this->index->prepare(
                "INSERT INTO doclist (term_id, doc_id, hit_count) VALUES {$placeholders}"
            )->execute($params);
        }
    }

    /**
     * Persist a document's total token count for BM25 length normalisation.
     *
     * @param int $documentId  Document ID.
     * @param int $length Total number of tokens across all indexed fields.
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
     * Atomically increment or decrement total_documents and return the new value.
     *
     * Uses UPDATE … RETURNING (requires SQLite 3.35+) to avoid a separate SELECT.
     *
     * @param  int $delta +1 for insert, -1 for delete.
     * @return int        New total_documents value after adjustment.
     */
    private function adjustTotalDocuments(int $delta): int
    {
        $stmt = $this->stmt(
            'adjustTotalDocuments',
            "UPDATE info SET value = CAST(value AS INTEGER) + :delta
             WHERE key = 'total_documents'
             RETURNING CAST(value AS INTEGER) AS value"
        );
        $stmt->bindValue(':delta', $delta, PDO::PARAM_INT);
        $stmt->execute();
        $value = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        return $value;
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
     * @return array{documents: list<array<string, mixed>>, numDocs: int}
     */
    public function getDocumentsAndCount(
        string $keyword,
        bool $noLimit = false,
        bool $isLastKeyword = false,
        bool $fuzzy = false
    ): array {
        $word = $this->getWordlistByKeyword($keyword, $isLastKeyword, $noLimit, $fuzzy);
        if (!isset($word[0])) {
            return ['documents' => [], 'numDocs' => 0];
        }

        $documents = $fuzzy
            ? $this->getAllDocumentsForFuzzyKeyword($word, $noLimit)
            : $this->getAllDocumentsForStrictKeyword($word, $noLimit);

        $numDocs = ($fuzzy || count($word) > 1)
            ? array_sum(array_column($word, 'num_docs'))
            : (int) $word[0]['num_docs'];

        return ['documents' => $documents, 'numDocs' => $numDocs];
    }

    /**
     * Fetch all documents that do NOT contain a given keyword.
     *
     * Returns an empty array if the keyword is not in the wordlist at all.
     *
     * @param  string                    $keyword Term to exclude.
     * @param  bool                      $noLimit When true, the $maxDocs cap is not applied.
     * @return list<array<string, mixed>>         Doclist rows for non-matching documents.
     */
    public function getAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit = false): array
    {
        $word = $this->getWordlistByKeyword($keyword);
        if (!isset($word[0])) {
            return [];
        }
        if ($noLimit) {
            $stmt = $this->stmt(
                'keywordNotUnlimited',
                'SELECT DISTINCT doc_id FROM doclist'
                . ' WHERE doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id = :id)'
            );
        } else {
            $stmt = $this->stmt(
                'keywordNotLimited',
                'SELECT DISTINCT doc_id FROM doclist'
                . ' WHERE doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id = :id) LIMIT :maxDocs'
            );
            $stmt->bindValue(':maxDocs', $this->maxDocs, PDO::PARAM_INT);
        }
        $stmt->bindValue(':id', $word[0]['id']);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve multiple values from the info metadata table in a single query.
     *
     * @param  string[]              $keys  Metadata keys to fetch.
     * @return array<string, string> Map of key → value for found rows.
     */
    public function getInfoValues(array $keys): array
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->index->prepare("SELECT key, value FROM info WHERE key IN ($placeholders)");
        $stmt->execute($keys);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
    }

    // --- Private read helpers -----------------------------------------------

    /**
     * Look up a keyword in the wordlist with optional prefix and fuzzy fallback.
     *
     * When $asYouType is true and $isLastWord is true, a trailing-wildcard LIKE
     * query is used instead of an exact match, returning up to $fuzzyMaxExpansions
     * candidates ordered by shortest term first, then by num_hits descending.
     * When $fuzzy is true and no exact match is found (or $noLimit forces
     * expansion), fuzzySearch() is called as a fallback.
     *
     * @param  string                    $keyword    Term to look up.
     * @param  bool                      $isLastWord Whether this is the final token in the query.
     * @param  bool                      $noLimit    Force fuzzy expansion even when an exact match exists.
     * @param  bool                      $fuzzy      When true, fall through to Levenshtein fuzzy search on no match.
     * @return list<array<string, mixed>>            Matching wordlist rows.
     */
    private function getWordlistByKeyword(
        string $keyword,
        bool $isLastWord = false,
        bool $noLimit = false,
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

        /** @var list<array<string, mixed>> $wordlistRows */
        $wordlistRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($fuzzy && (!isset($wordlistRows[0]) || $noLimit)) {
            return $this->fuzzySearch($keyword);
        }

        return $wordlistRows;
    }

    /**
     * Fetch all doclist rows for one or more exact/prefix wordlist matches, ordered by hit count.
     *
     * When $words contains a single entry, a named-parameter equality query is used.
     * When $words contains multiple entries (e.g. as-you-type prefix expansion), an
     * IN clause with positional parameters is used instead.
     *
     * @param  list<array<string, mixed>> $words   Wordlist rows from getWordlistByKeyword().
     * @param  bool                       $noLimit When true, the $maxDocs cap is not applied.
     * @return list<array<string, mixed>>          Doclist rows with term_id, doc_id, hit_count, doc_length.
     */
    private function getAllDocumentsForStrictKeyword(array $words, bool $noLimit): array
    {
        if (count($words) === 1) {
            if ($noLimit) {
                $stmt = $this->stmt(
                    'strictDocsUnlimited',
                    'SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
                      FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
                      WHERE d.term_id = :id ORDER BY d.hit_count DESC'
                );
                $stmt->bindValue(':id', $words[0]['id']);
            } else {
                $stmt = $this->stmt(
                    'strictDocsLimited',
                    'SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
                      FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
                      WHERE d.term_id = :id ORDER BY d.hit_count DESC LIMIT :maxDocs'
                );
                $stmt->bindValue(':id', $words[0]['id']);
                $stmt->bindValue(':maxDocs', $this->maxDocs, PDO::PARAM_INT);
            }
            $stmt->execute();
        } else {
            // Dynamic IN clause: variable placeholder count prevents statement caching.
            $placeholders = implode(',', array_fill(0, count($words), '?'));
            $query = "SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
                       FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
                       WHERE d.term_id IN ($placeholders) ORDER BY d.hit_count DESC"
                     . ($noLimit ? '' : " LIMIT {$this->maxDocs}");
            $stmt = $this->index->prepare($query);
            $stmt->execute(array_column($words, 'id'));
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch doclist rows for a set of fuzzy-matched wordlist entries.
     *
     * Results are ordered to match the relevance ranking of $words (closest
     * Levenshtein match first) using a SQL CASE expression.
     *
     * @param  list<array<string, mixed>> $words   Wordlist rows from fuzzySearch(), ordered by relevance.
     * @param  bool                       $noLimit When true, the $maxDocs cap is not applied.
     * @return list<array<string, mixed>>          Doclist rows in fuzzy-match order.
     */
    private function getAllDocumentsForFuzzyKeyword(array $words, bool $noLimit): array
    {
        $placeholders = implode(',', array_fill(0, count($words), '?'));

        $whenClauses  = '';
        foreach ($words as $i => $word) {
            $whenClauses .= " WHEN {$word['id']} THEN " . ($i + 1);
        }

        $query = "SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
                  FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
                  WHERE d.term_id IN ($placeholders) ORDER BY CASE d.term_id{$whenClauses} END"
               . ($noLimit ? '' : ' LIMIT ?');

        $ids    = array_column($words, 'id');
        $params = $noLimit ? $ids : [...$ids, $this->maxDocs];
        $stmt   = $this->index->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find wordlist candidates within Levenshtein edit distance of the keyword.
     *
     * Queries the wordlist for all terms sharing the same prefix
     * ($fuzzyPrefixLength chars), then filters by $fuzzyDistance and sorts
     * by edit distance ascending, then num_hits descending.
     *
     * @param  string                    $keyword Search term to find fuzzy matches for (must already be lowercased).
     * @return list<array<string, mixed>>         Matching wordlist rows with an added 'distance' key.
     */
    private function fuzzySearch(string $keyword): array
    {
        $keywordLength = mb_strlen($keyword);

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

        /** @var list<array<string, mixed>> $resultSet */
        $resultSet = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $match) {
            $distance = self::mbLevenshtein($match['term'], $keyword);
            if ($distance <= $this->fuzzyDistance) {
                $resultSet[] = [...$match, 'distance' => $distance];
            }
        }

        usort($resultSet, fn($a, $b) => $a['distance'] <=> $b['distance'] ?: $b['num_hits'] <=> $a['num_hits']);

        return $resultSet;
    }

    /**
     * Unicode-aware Levenshtein edit distance.
     *
     * PHP's built-in levenshtein() operates on bytes, giving wrong results for
     * multi-byte UTF-8 strings (e.g. "café" vs "cafe" would score 2 instead of 1).
     *
     * This implementation re-encodes both strings into a shared single-byte
     * representation (remapping non-ASCII code points to bytes 128–255) and
     * then delegates to the native C levenshtein(), keeping all heavy work in C.
     * Supports up to 128 distinct non-ASCII code points per string pair, which
     * is more than sufficient for short search terms.
     *
     * @param string $a First string (already lowercased).
     * @param string $b Second string (already lowercased).
     * @return int Edit distance in Unicode characters.
     */
    private static function mbLevenshtein(string $a, string $b): int
    {
        $map = [];
        self::mbToAscii($a, $map);
        self::mbToAscii($b, $map);
        return levenshtein($a, $b);
    }

    /**
     * Re-encode multi-byte characters in $str to single bytes using a shared map.
     *
     * Non-ASCII UTF-8 sequences are assigned bytes starting at 128 in order of
     * first appearance. The same map must be passed for both strings compared
     * with mbLevenshtein so that identical code points always map to the same byte.
     *
     * @param string               $str UTF-8 string to encode in-place.
     * @param array<string, string> $map Shared encoding map (updated in-place).
     */
    private static function mbToAscii(string &$str, array &$map): void
    {
        if (!preg_match_all('/[\xC0-\xF7][\x80-\xBF]+/', $str, $matches)) {
            return; // pure ASCII — nothing to remap
        }
        $count = count($map);
        foreach ($matches[0] as $mbc) {
            if (!isset($map[$mbc])) {
                $map[$mbc] = chr(128 + $count++);
            }
        }
        $str = strtr($str, $map);
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
        return $this->stmtCache[$key] ??= $this->index->prepare($sql);
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
        if ($this->index->inTransaction()) {
            $fn();
            return;
        }
        $this->index->beginTransaction();
        try {
            $fn();
            $this->index->commit();
        } catch (\Throwable $e) {
            $this->index->rollBack();
            throw $e;
        }
    }
}

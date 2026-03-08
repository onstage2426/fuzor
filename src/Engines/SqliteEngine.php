<?php

namespace Fuzor\Engines;

use Fuzor\Tokenizer;
use PDO;
use PDOStatement;

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
    /** Name of the currently active index file (e.g. 'articles.db'). */
    private string $indexName;

    /** Absolute path to the storage directory (guaranteed trailing slash). */
    private string $storagePath;

    /** Active PDO connection to the open SQLite index file. */
    private PDO $index;

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
        $this->indexName = $indexName;
        $this->flushIndex($indexName);

        $this->index = new PDO('sqlite:' . $this->storagePath . $indexName);
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->applyPragmas();

        $this->index->exec(
            "CREATE TABLE IF NOT EXISTS wordlist (
                id INTEGER PRIMARY KEY,
                term TEXT UNIQUE COLLATE nocase,
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
        $this->index->prepare("INSERT INTO info (key, value) VALUES (:key, :value)")
            ->execute([':key' => 'total_documents', ':value' => 0]);
        $this->index->prepare("INSERT INTO info (key, value) VALUES (:key, :value)")
            ->execute([':key' => 'avg_doc_length', ':value' => 0]);

        return $this;
    }

    /**
     * Persist a key/value pair to the info metadata table.
     *
     * @param string $key   Metadata key (e.g. 'total_documents').
     * @param mixed  $value Value to store.
     */
    public function updateInfoTable(string $key, mixed $value): void
    {
        $stmt = $this->index->prepare('UPDATE info SET value = :value WHERE key = :key');
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':value', $value);
        $stmt->execute();
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
    public function processDocument(array $row): int
    {
        $documentId = $row['id'];

        /** @var array<string, string[]> $stems */
        $stems = array_map(
            fn($col) => trim((string) $col) === '' ? [] : Tokenizer::tokenize((string) $col),
            array_diff_key($row, ['id' => null])
        );

        $length = array_sum(array_map(count(...), $stems));
        $this->saveToIndex($stems, $documentId);
        $this->saveDocLength($documentId, $length);

        return $length;
    }

    /**
     * Persist a document's total token count for BM25 length normalisation.
     *
     * @param int $docId  Document ID.
     * @param int $length Total number of tokens across all indexed fields.
     */
    public function saveDocLength(int $docId, int $length): void
    {
        $stmt = $this->index->prepare(
            'INSERT INTO doc_lengths (doc_id, length) VALUES (:id, :len)
             ON CONFLICT(doc_id) DO UPDATE SET length = excluded.length'
        );
        $stmt->execute([':id' => $docId, ':len' => $length]);
    }

    /**
     * Persist tokenised stems for a document to the wordlist and doclist tables.
     *
     * @param array<string, string[]> $stems Tokenised fields keyed by field name.
     * @param int                     $docId Document ID to associate terms with.
     */
    public function saveToIndex(array $stems, int $docId): void
    {
        $terms = $this->saveWordlist($stems);
        $this->saveDoclist($terms, $docId);
    }

    /**
     * Upsert terms from tokenised document stems into the wordlist table.
     *
     * Uses a single batched INSERT … ON CONFLICT … RETURNING id, term to upsert
     * all terms for a document in one round-trip per chunk (chunks respect
     * SQLite's 999-variable limit at 2 params per term).
     *
     * @param  array<string, string[]> $stems Tokenised fields (field name → token list).
     * @return array<string, array{hits: int, id: int|string}> Terms with resolved wordlist IDs.
     */
    public function saveWordlist(array $stems): array
    {
        /** @var array<string, array{hits: int, id: int|string}> $terms */
        $terms = [];

        foreach ($stems as $column) {
            foreach ($column as $term) {
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
     * @param array<string, array{hits: int, id: int|string}> $terms Terms with resolved wordlist IDs.
     * @param int                                               $docId Document ID.
     */
    public function saveDoclist(array $terms, int $docId): void
    {
        if (empty($terms)) {
            return;
        }

        foreach (array_chunk(array_values($terms), 333) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?)'));
            $params = [];
            foreach ($chunk as $term) {
                $params[] = $term['id'];
                $params[] = $docId;
                $params[] = $term['hits'];
            }
            $this->index->prepare(
                "INSERT INTO doclist (term_id, doc_id, hit_count) VALUES {$placeholders}"
            )->execute($params);
        }
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
            $this->prepareAndExecuteStatement(
                'WITH doc_terms AS (
                     SELECT term_id, hit_count FROM doclist WHERE doc_id = :documentId
                 )
                 UPDATE wordlist SET
                     num_docs = num_docs - 1,
                     num_hits = num_hits - (SELECT hit_count FROM doc_terms WHERE term_id = wordlist.id)
                 WHERE id IN (SELECT term_id FROM doc_terms)',
                [':documentId' => $documentId]
            );

            $this->prepareAndExecuteStatement(
                'DELETE FROM doclist WHERE doc_id = :documentId',
                [':documentId' => $documentId]
            );

            $this->prepareAndExecuteStatement('DELETE FROM wordlist WHERE num_hits = 0');

            $length = (int) $this->prepareAndExecuteStatement(
                'SELECT length FROM doc_lengths WHERE doc_id = :documentId',
                [':documentId' => $documentId]
            )->fetchColumn();

            $deleted = $this->prepareAndExecuteStatement(
                'DELETE FROM doc_lengths WHERE doc_id = :documentId',
                [':documentId' => $documentId]
            )->rowCount();

            if ($deleted) {
                $oldAvg   = (float) ($this->getInfoValues(['avg_doc_length'])['avg_doc_length'] ?? 0);
                $newCount = $this->adjustTotalDocuments(-1);
                $oldCount = $newCount + 1;
                $this->updateInfoTable('avg_doc_length', $newCount > 0 ? ($oldAvg * $oldCount - $length) / $newCount : 0);
            }
        });
    }

    /**
     * Prepare, bind, and execute a parameterised SQL statement.
     *
     * @param  string               $query  SQL query with named placeholders.
     * @param  array<string, mixed> $params Map of placeholder → value (e.g. [':id' => 1]).
     * @return PDOStatement                 Executed statement (ready for fetch calls).
     */
    public function prepareAndExecuteStatement(string $query, array $params = []): PDOStatement
    {
        $stmt = $this->index->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt;
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
        $stmt = $this->index->prepare(
            "UPDATE info SET value = CAST(value AS INTEGER) + :delta
             WHERE key = 'total_documents'
             RETURNING CAST(value AS INTEGER) AS value"
        );
        $stmt->bindValue(':delta', $delta, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
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
        $this->applyPragmas();
    }

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
    public function getWordlistByKeyword(string $keyword, bool $isLastWord = false, bool $noLimit = false, bool $fuzzy = false): array
    {
        if ($this->asYouType && $isLastWord) {
            $stmt = $this->index->prepare(
                'SELECT id, term, num_hits, num_docs FROM wordlist'
                . ' WHERE term LIKE :keyword ORDER BY length(term) ASC, num_hits DESC LIMIT :maxExpansions;'
            );
            $stmt->bindValue(':keyword', mb_strtolower($keyword) . '%');
            $stmt->bindValue(':maxExpansions', $this->fuzzyMaxExpansions, PDO::PARAM_INT);
        } else {
            $stmt = $this->index->prepare(
                'SELECT id, term, num_hits, num_docs FROM wordlist WHERE term = :keyword LIMIT 1;'
            );
            $stmt->bindValue(':keyword', mb_strtolower($keyword));
        }

        $stmt->execute();

        /** @var list<array<string, mixed>> $res */
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $fuzzy && (!isset($res[0]) || $noLimit)
            ? $this->fuzzySearch($keyword)
            : $res;
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
    public function getAllDocumentsForStrictKeyword(array $words, bool $noLimit): array
    {
        if (count($words) === 1) {
            $query = 'SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
                       FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
                       WHERE d.term_id = :id ORDER BY d.hit_count DESC'
                     . ($noLimit ? '' : ' LIMIT :maxDocs');
            $stmt = $this->index->prepare($query);
            $stmt->bindValue(':id', $words[0]['id']);
            if (!$noLimit) {
                $stmt->bindValue(':maxDocs', $this->maxDocs, PDO::PARAM_INT);
            }
            $stmt->execute();
        } else {
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
        $query = 'SELECT * FROM doclist'
                 . ' WHERE doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id = :id)'
                 . ' GROUP BY doc_id ORDER BY hit_count DESC'
                 . ($noLimit ? '' : ' LIMIT :maxDocs');
        $stmt = $this->index->prepare($query);
        $stmt->bindValue(':id', $word[0]['id']);
        if (!$noLimit) {
            $stmt->bindValue(':maxDocs', $this->maxDocs, PDO::PARAM_INT);
        }
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

    /**
     * Dispatch document retrieval to strict or fuzzy mode based on engine config.
     *
     * Returns an empty array if the keyword is not present in the wordlist.
     *
     * @param  string                    $keyword       Term to search for.
     * @param  bool                      $noLimit       When true, the $maxDocs cap is not applied.
     * @param  bool                      $isLastKeyword Whether this is the final token in the query.
     * @param  bool                      $fuzzy         When true, Levenshtein fuzzy matching is used.
     * @return list<array<string, mixed>>               Doclist rows ordered by relevance.
     */
    public function getAllDocumentsForKeyword(string $keyword, bool $noLimit = false, bool $isLastKeyword = false, bool $fuzzy = false): array
    {
        $word = $this->getWordlistByKeyword($keyword, $isLastKeyword, $noLimit, $fuzzy);
        if (!isset($word[0])) {
            return [];
        }
        return $fuzzy
            ? $this->getAllDocumentsForFuzzyKeyword($word, $noLimit)
            : $this->getAllDocumentsForStrictKeyword($word, $noLimit);
    }

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
    public function getDocumentsAndCount(string $keyword, bool $noLimit = false, bool $isLastKeyword = false, bool $fuzzy = false): array
    {
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
     * Find wordlist candidates within Levenshtein edit distance of the keyword.
     *
     * Queries the wordlist for all terms sharing the same prefix
     * ($fuzzyPrefixLength chars), then filters by $fuzzyDistance and sorts
     * by edit distance ascending, then num_hits descending.
     *
     * @param  string                    $keyword Search term to find fuzzy matches for.
     * @return list<array<string, mixed>>         Matching wordlist rows with an added 'distance' key.
     */
    public function fuzzySearch(string $keyword): array
    {
        $kwLen = mb_strlen($keyword);

        $stmt = $this->index->prepare(
            "SELECT id, term, num_hits, num_docs FROM wordlist
             WHERE term LIKE :keyword
               AND length(term) BETWEEN :min AND :max
             ORDER BY num_hits DESC
             LIMIT {$this->fuzzyMaxExpansions};"
        );
        $stmt->bindValue(':keyword', mb_strtolower(mb_substr($keyword, 0, $this->fuzzyPrefixLength)) . '%');
        $stmt->bindValue(':min', max(1, $kwLen - $this->fuzzyDistance), PDO::PARAM_INT);
        $stmt->bindValue(':max', $kwLen + $this->fuzzyDistance, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $resultSet */
        $resultSet = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $match) {
            $distance = levenshtein($match['term'], $keyword);
            if ($distance <= $this->fuzzyDistance) {
                $resultSet[] = [...$match, 'distance' => $distance];
            }
        }

        usort($resultSet, fn($a, $b) => $a['distance'] <=> $b['distance'] ?: $b['num_hits'] <=> $a['num_hits']);

        return $resultSet;
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
    public function getAllDocumentsForFuzzyKeyword(array $words, bool $noLimit): array
    {
        $placeholders = implode(',', array_fill(0, count($words), '?'));
        $whenClauses  = implode('', array_map(
            fn($w, $i) => " WHEN {$w['id']} THEN $i",
            $words,
            range(1, count($words))
        ));
        $query = "SELECT d.term_id, d.doc_id, d.hit_count, dl.length AS doc_length
                  FROM doclist d JOIN doc_lengths dl ON dl.doc_id = d.doc_id
                  WHERE d.term_id IN ($placeholders) ORDER BY CASE d.term_id{$whenClauses} END"
               . ($noLimit ? '' : ' LIMIT ?');

        $ids  = array_column($words, 'id');
        $stmt = $this->index->prepare($query);
        $stmt->execute($noLimit ? $ids : [...$ids, $this->maxDocs]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete an index file and its WAL sidecar files from disk.
     *
     * Removes the main file plus the `-wal` and `-shm` companions created by
     * WAL journal mode. No-ops silently for any file that does not exist.
     *
     * @param string $indexName Filename of the index to delete.
     */
    public function flushIndex(string $indexName): void
    {
        $path = $this->storagePath . $indexName;
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Replace a document in the index.
     *
     * Equivalent to delete($id) followed by insert($document). The total
     * document count is unchanged: delete decrements it, insert increments it.
     *
     * @param int                  $id       ID of the document to replace.
     * @param array<string, mixed> $document New document data; must contain an 'id' key.
     */
    public function update(int $id, array $document): void
    {
        $this->wrapInTransaction(function () use ($id, $document): void {
            $this->delete($id);
            $this->insert($document);
        });
    }

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
            $newCount = $this->adjustTotalDocuments(1);
            $oldCount = $newCount - 1;
            $oldAvg   = (float) ($this->getInfoValues(['avg_doc_length'])['avg_doc_length'] ?? 0);
            $this->updateInfoTable('avg_doc_length', ($oldAvg * $oldCount + $length) / $newCount);
        });
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

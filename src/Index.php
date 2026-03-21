<?php

namespace Fuzor;

use Fuzor\SqliteEngine;
use Fuzor\Tokenizer;

/**
 * Represents a single Fuzor index.
 *
 * Entry point for all indexing and searching operations against one SQLite
 * index file. Obtain an instance via the static factory methods:
 *
 *   $index = Index::open('/path/to/articles.db');
 *   $index = Index::create('/path/to/articles.db');
 *
 * All database interaction is delegated to SqliteEngine, which is kept as a
 * private implementation detail. Configuration properties proxy directly to
 * the engine via property hooks.
 */
class Index
{
    private SqliteEngine $engine;

    // --- Engine configuration (proxied via property hooks) ----------

    /** When true, the last search keyword is matched as a prefix (as-you-type behaviour). */
    public bool $asYouType {
        get => $this->engine->asYouType;
        set { $this->engine->asYouType = $value; }
    }

    /** Number of leading characters that must match exactly before fuzzy edit distance kicks in. */
    public int $fuzzyPrefixLength {
        get => $this->engine->fuzzyPrefixLength;
        set { $this->engine->fuzzyPrefixLength = $value; }
    }

    /** Maximum number of wordlist candidates evaluated during a fuzzy search. */
    public int $fuzzyMaxExpansions {
        get => $this->engine->fuzzyMaxExpansions;
        set { $this->engine->fuzzyMaxExpansions = $value; }
    }

    /** Maximum Levenshtein edit distance accepted as a fuzzy match. */
    public int $fuzzyDistance {
        get => $this->engine->fuzzyDistance;
        set { $this->engine->fuzzyDistance = $value; }
    }

    /** Maximum number of documents returned per keyword in non-fuzzy queries. */
    public int $maxDocs {
        get => $this->engine->maxDocs;
        set { $this->engine->maxDocs = $value; }
    }

    /** BM25 term frequency saturation parameter. Higher values give more weight to repeated terms. */
    public float $k1 {
        get => $this->engine->k1;
        set { $this->engine->k1 = $value; }
    }

    /** BM25 document length normalisation weight. 0 = no normalisation, 1 = full normalisation. */
    public float $b {
        get => $this->engine->b;
        set { $this->engine->b = $value; }
    }

    /**
     * BCP 47 language tag for stopword filtering (e.g. 'en', 'fr', 'de').
     * Null (default) disables stopword removal entirely.
     * Throws \InvalidArgumentException if no stopword list exists for the given language.
     */
    public ?string $language {
        get => $this->engine->language;
        set { $this->engine->language = $value; }
    }

    // ------------------------------------------------------------------------

    private function __construct(SqliteEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Resolve a path to a canonical string, even if the file does not yet exist.
     *
     * Uses realpath() on the directory (which must exist) and appends the filename.
     *
     * @param  string $path Absolute or relative path to resolve.
     * @return string       Canonical absolute path.
     * @throws \RuntimeException If the parent directory does not exist.
     */
    private static function resolvePath(string $path): string
    {
        $dir = realpath(dirname($path));
        if ($dir === false) {
            throw new \RuntimeException("Directory does not exist: " . dirname($path));
        }
        return $dir . DIRECTORY_SEPARATOR . basename($path);
    }

    /**
     * Open an existing index file.
     *
     * @param  string $path Absolute or relative path to the SQLite index file.
     * @throws \RuntimeException If the index file does not exist.
     */
    public static function open(string $path): self
    {
        $resolved = self::resolvePath($path);
        $engine = new SqliteEngine(dirname($resolved));
        $engine->selectIndex(basename($resolved));
        return new self($engine);
    }

    /**
     * Create a new index file.
     *
     * @param  string $path  Absolute or relative path for the new SQLite index file.
     * @param  bool   $force When true, any existing file at that path is overwritten.
     * @throws \RuntimeException If a file already exists at $path and $force is false.
     */
    public static function create(string $path, bool $force = false): self
    {
        $resolved = self::resolvePath($path);
        $engine = new SqliteEngine(dirname($resolved));
        $engine->createIndex(basename($resolved), $force);
        return new self($engine);
    }

    // --- Document operations ------------------------------------------------

    /**
     * Index a new document.
     *
     * @param array<string, mixed> $document Document fields; must contain an 'id' key.
     */
    public function insert(array $document): void
    {
        $this->engine->insert($document);
    }

    /**
     * Index multiple documents in a single transaction with one stats update.
     *
     * Substantially faster than calling insert() in a loop for bulk loads.
     *
     * @param array<array<string, mixed>> $documents Documents to index; each must contain an 'id' key.
     */
    public function insertMany(array $documents): void
    {
        $this->engine->insertMany($documents);
    }

    /**
     * Replace an existing document in the index.
     *
     * Re-indexes the document in a single transaction with one stats update.
     * If the ID does not exist it is inserted (upsert semantics).
     *
     * @param array<string, mixed> $document New document data; must contain an 'id' key.
     */
    public function update(array $document): void
    {
        $this->engine->update($document);
    }

    /**
     * Remove a document from the index.
     *
     * @param int $id ID of the document to remove.
     */
    public function delete(int $id): void
    {
        $this->engine->delete($id);
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
        $this->engine->close();
    }

    // --- Search operations --------------------------------------------------

    /**
     * Run a strict BM25 ranked full-text search (exact + optional as-you-type prefix).
     *
     * Fuzziness is explicitly disabled; use searchFuzzy() for Levenshtein matching.
     *
     * @param  string $phrase       Raw search phrase; will be tokenised.
     * @param  int    $numOfResults Maximum number of document IDs to return.
     * @return array{ids: list<int>, hits: int, docScores: array<int, float>}
     */
    public function search(string $phrase, int $numOfResults = 100): array
    {
        return $this->scorePhrase($phrase, $numOfResults, false);
    }

    /**
     * Run a fuzzy BM25 ranked full-text search using Levenshtein matching.
     *
     * Respects $fuzzyDistance, $fuzzyPrefixLength, and $fuzzyMaxExpansions.
     *
     * @param  string $phrase       Raw search phrase; will be tokenised.
     * @param  int    $numOfResults Maximum number of document IDs to return.
     * @return array{ids: list<int>, hits: int, docScores: array<int, float>}
     */
    public function searchFuzzy(string $phrase, int $numOfResults = 100): array
    {
        return $this->scorePhrase($phrase, $numOfResults, true);
    }

    /**
     * Run a boolean full-text search using Shunting-Yard postfix evaluation.
     *
     * Supported operators (after lexExpression normalisation):
     *   - space / &  — AND (intersection)
     *   - " or "     — OR  (union)
     *   - " -term"   — NOT (complement, returns all docs NOT containing term)
     *
     * @param  string $phrase       Boolean query string.
     * @param  int    $numOfResults Maximum number of document IDs to return.
     * @return array{ids: list<int>, hits: int, docScores: null}
     */
    public function searchBoolean(string $phrase, int $numOfResults = 100): array
    {
        // Prepend "|" so the Shunting-Yard algorithm always has a left-hand operand.
        // OR with an empty set is the identity, so it does not affect the result.
        /** @var list<string> $postfix */
        $postfix = $this->toPostfix('|' . $phrase);

        // Find the last term token so asYouType prefix expansion applies to it.
        $lastTerm = null;
        for ($i = count($postfix) - 1; $i >= 0; $i--) {
            if (!in_array($postfix[$i], ['|', '&', '~'], true)) {
                $lastTerm = $postfix[$i];
                break;
            }
        }

        // PHP-side set evaluation: fetch per-term doc IDs (capped at maxDocs) from SQLite,
        // then apply set operations in PHP via C-native array_intersect / array_diff /
        // array_unique. This avoids SQLite compound-query materialisation (UNION/INTERSECT/
        // EXCEPT over unbounded doclists) which was the main boolean performance bottleneck.
        // Each term lookup is a single indexed SELECT with LIMIT; the wordlistCache ensures
        // repeated keyword lookups within one query incur no extra DB round-trips.

        $limit = $this->engine->maxDocs;

        /** Fetch capped doc IDs for one keyword (resolves prefix expansion / caching). */
        $fetchIds = fn(string $kw, bool $isLast): array =>
            $this->engine->fetchBooleanDocIds(
                $this->engine->resolveWordlistIds($kw, $isLast),
                $limit
            );

        /**
         * Materialise a stack entry into a flat list of doc IDs.
         * Strings are lazily fetched; lists are passed through; null maps to [].
         *
         * @param  string|list<int>|null $entry
         * @return list<int>
         */
        $ids = function (string|array|null $entry) use ($fetchIds, $lastTerm): array {
            if ($entry === null) {
                return [];
            }
            if (is_string($entry)) {
                return $fetchIds($entry, $entry === $lastTerm);
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
                $termIds = is_string($term) ? $fetchIds($term, $term === $lastTerm) : $ids($term);
                $stack[] = ['__not__' => $termIds];
            } elseif ($token === '&') {
                $right = array_pop($stack);
                $left  = array_pop($stack);
                if (is_array($right) && isset($right['__not__'])) {
                    // AND-NOT: subtract negated IDs from the positive side.
                    /** @var array{__not__: list<int>} $right */
                    $stack[] = array_values(array_diff($ids($left), $right['__not__']));
                } else {
                    // AND: intersection of both sides (C-native, O(n log n)).
                    $stack[] = array_values(array_intersect($ids($left), $ids($right)));
                }
            } elseif ($token === '|') {
                // OR: merge both sides and deduplicate (preserves first-seen / popularity order).
                $right = array_pop($stack) ?? null;
                $left  = array_pop($stack) ?? null;
                $stack[] = array_values(array_unique(array_merge($ids($left), $ids($right))));
            } else {
                $stack[] = $token; // lazy string operand
            }
        }

        /** @var list<int> $docIds */
        $docIds = $ids(array_pop($stack) ?? null);
        $total  = count($docIds);
        if ($total > $numOfResults) {
            $docIds = array_slice($docIds, 0, $numOfResults);
        }

        return ['ids' => $docIds, 'hits' => $total, 'docScores' => null];
    }

    // --- Internal -----------------------------------------------------------

    /**
     * Core smoothed Okapi BM25 scoring loop shared by search() and searchFuzzy().
     *
     * @param  string $phrase       Raw search phrase; will be tokenised.
     * @param  int    $numOfResults Maximum number of document IDs to return.
     * @param  bool   $fuzzy        When true, Levenshtein fuzzy matching is used.
     * @return array{ids: list<int>, hits: int, docScores: array<int, float>}
     */
    private function scorePhrase(string $phrase, int $numOfResults, bool $fuzzy): array
    {
        /** @var list<string> $keywords */
        $keywords = $this->engine->filterQueryTokens(Tokenizer::tokenize($phrase));

        /** @var array<int, float> $docScores */
        $docScores = [];

        $info      = $this->engine->getInfoValues(['total_documents', 'avg_doc_length']);
        $totalDocuments = (int) ($info['total_documents'] ?? 0);
        $avgdl     = max(1.0, (float) ($info['avg_doc_length'] ?? 0));
        $k1        = $this->k1;
        $b         = $this->b;
        $lastIndex = count($keywords) - 1;

        foreach ($keywords as $index => $term) {
            $isLastKeyword = $lastIndex === $index;
            $result = $this->engine->getDocumentsAndCount($term, false, $isLastKeyword, $fuzzy);
            $df     = $result['numDocs'];
            // Smoothed BM25 IDF: always ≥ 0, avoids negative weights for common terms.
            $idf    = log(1 + ($totalDocuments - $df + 0.5) / ($df + 0.5));
            // Precompute per-keyword BM25 invariants outside the per-document inner loop.
            $idfK1p1   = $idf * ($k1 + 1);   // idf * (k1 + 1)  — numerator constant
            $k1_1mb    = $k1 * (1.0 - $b);   // k1 * (1 - b)    — denominator constant
            $k1b_avgdl = $k1 * $b / $avgdl;  // k1 * b / avgdl  — length-norm scale
            // Column order from FETCH_NUM: 0=term_id, 1=doc_id, 2=hit_count, 3=doc_length
            foreach ($result['documents'] as [, $docId, $tf, $dl]) {
                $docScores[$docId] = ($docScores[$docId] ?? 0.0)
                    + $idfK1p1 * $tf / ($k1_1mb + $k1b_avgdl * $dl + $tf);
            }
        }

        $total = count($docScores);

        if ($total === 0 || $numOfResults === 0) {
            return ['ids' => [], 'hits' => $total, 'docScores' => $docScores];
        }

        if ($total <= $numOfResults) {
            arsort($docScores);
            return ['ids' => array_keys($docScores), 'hits' => $total, 'docScores' => $docScores];
        }

        // Partial sort: min-heap capped at $numOfResults keeps only the top-k scoring docs.
        // SplMinHeap::top() is the weakest entry currently kept; anything that can't beat it
        // is discarded, so the heap never grows beyond $numOfResults elements.
        /** @var \SplMinHeap<array{float, int}> $heap */
        $heap     = new \SplMinHeap();
        $heapSize = 0;
        $heapMin  = -INF;
        foreach ($docScores as $docId => $score) {
            if ($heapSize < $numOfResults) {
                $heap->insert([$score, $docId]);
                $heapSize++;
                if ($heapSize === $numOfResults) {
                    $heapMin = $heap->top()[0];
                }
            } elseif ($score > $heapMin) {
                $heap->extract();
                $heap->insert([$score, $docId]);
                $heapMin = $heap->top()[0];
            }
        }

        // extract() yields ascending order; reverse so ids are sorted by score descending.
        /** @var list<int> $ids */
        $ids = [];
        while (!$heap->isEmpty()) {
            $ids[] = $heap->extract()[1];
        }

        return ['ids' => array_reverse($ids), 'hits' => $total, 'docScores' => $docScores];
    }

    /**
     * Convert an infix boolean expression to postfix (Reverse Polish) notation.
     *
     * Uses the Shunting-Yard algorithm. Operator precedence: ~ (3) > & (2) > | (1).
     *
     * @param  string      $expression Infix expression containing operators |, &, ~, (, ).
     * @return list<string>            Tokens in postfix order.
     */
    private function toPostfix(string $expression): array
    {
        /** @var list<string> $postfix */
        $postfix = [];

        /** @var list<string> $stack */
        $stack = [];

        foreach ($this->lexExpression($expression) as $token) {
            if (!in_array($token, ['|', '&', '~', '(', ')'], true)) {
                $postfix[] = $token;
            } elseif ($token === ')') {
                while (($top = array_pop($stack)) !== '(' && !empty($top)) {
                    $postfix[] = $top;
                }
            } else {
                $tokenPriority = $this->expressionPriority($token);
                while (
                    !empty($stack) && ($top = end($stack)) !== '('
                    && $this->expressionPriority($top) >= $tokenPriority
                ) {
                    $postfix[] = array_pop($stack);
                }
                $stack[] = $token;
            }
        }
        while (!empty($stack)) {
            $postfix[] = array_pop($stack);
        }

        return $postfix;
    }

    /**
     * Return the precedence level of a boolean operator.
     *
     * Higher value binds tighter: ~ (3) > & (2) > | (1).
     * Parentheses are handled structurally in toPostfix() and are not assigned a precedence.
     *
     * @param  string $operator Operator token.
     * @return int             Precedence level; 0 for unknown tokens.
     */
    private function expressionPriority(string $operator): int
    {
        return match ($operator) {
            '|'     => 1,
            '&'     => 2,
            '~'     => 3,
            default => 0,
        };
    }

    /**
     * Tokenise a raw boolean query string into operators and word operands.
     *
     * Normalises natural-language syntax before splitting:
     *   " or " → |,   " -" → &~,   " " → &
     *
     * @param  string      $expression Raw query string.
     * @return list<string>            Alternating word tokens and operator characters.
     */
    private function lexExpression(string $expression): array
    {
        $expression = $expression
            |> (fn(string $s): string => mb_strtolower($s, 'UTF-8'))
            |> (fn(string $s): string => preg_replace('/\s*([()])\s*/', '$1', $s) ?? $s)
            |> (fn(string $s): string => str_replace([' or ', ' -', ' '], ['|', '&~', '&'], $s));

        return preg_split('/([|~&()])/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

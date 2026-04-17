<?php

namespace Fuzor;

use Fuzor\IndexStorage;
use Fuzor\Tokenizer;
use Fuzor\Snippeter;
use Fuzor\Highlighter;

/**
 * Represents a single open Fuzor index.
 *
 * Obtain an instance via the static factory on Index:
 *
 *   $index = Index::open('/path/to/articles.db');
 *   $index = Index::create('/path/to/articles.db');
 *
 * All database interaction is delegated to IndexStorage. Configuration
 * properties proxy directly to it via property hooks.
 */
class IndexHandle
{
    private IndexStorage $index;

    // --- Engine configuration (proxied via property hooks) ----------

    /** When true, the last search keyword is matched as a prefix (as-you-type behaviour). */
    public bool $asYouType {
        get => $this->index->asYouType;
        set { $this->index->asYouType = $value; }
    }

    /** Number of leading characters that must match exactly before fuzzy edit distance kicks in. */
    public int $fuzzyPrefixLength {
        get => $this->index->fuzzyPrefixLength;
        set { $this->index->fuzzyPrefixLength = $value; }
    }

    /** Maximum number of wordlist candidates evaluated during a fuzzy search. */
    public int $fuzzyMaxExpansions {
        get => $this->index->fuzzyMaxExpansions;
        set { $this->index->fuzzyMaxExpansions = $value; }
    }

    /** Maximum Levenshtein edit distance accepted as a fuzzy match. */
    public int $fuzzyDistance {
        get => $this->index->fuzzyDistance;
        set { $this->index->fuzzyDistance = $value; }
    }

    /** Maximum number of documents returned per keyword in non-fuzzy queries. */
    public int $maxDocs {
        get => $this->index->maxDocs;
        set { $this->index->maxDocs = $value; }
    }

    /** BM25 term frequency saturation parameter. Higher values give more weight to repeated terms. */
    public float $k1 {
        get => $this->index->k1;
        set { $this->index->k1 = $value; }
    }

    /** BM25 document length normalisation weight. 0 = no normalisation, 1 = full normalisation. */
    public float $b {
        get => $this->index->b;
        set { $this->index->b = $value; }
    }

    /** BCP 47 language tag active on this index; null means no stopword filtering or stemming. */
    public ?string $language {
        get => $this->index->language;
    }

    // ------------------------------------------------------------------------

    public function __construct(IndexStorage $engine)
    {
        $this->index = $engine;
    }

    public function __destruct()
    {
        $this->index->close();
    }

    // --- Document operations ------------------------------------------------

    /**
     * Index a new document.
     *
     * @param array<string, mixed> $document Document fields; must contain an 'id' key.
     */
    public function insert(array $document): void
    {
        $this->index->insert($document);
    }

    /**
     * Index multiple documents in a single transaction with one stats update.
     *
     * Substantially faster than calling insert() in a loop for bulk loads.
     * Accepts any iterable, including generators, for memory-efficient streaming.
     *
     * @param iterable<array<string, mixed>> $documents Documents to index; each must contain an 'id' key.
     */
    public function insertMany(iterable $documents): void
    {
        $this->index->insertMany($documents);
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
        $this->index->update($document);
    }

    /**
     * Remove a document from the index.
     *
     * @param int $id ID of the document to remove.
     */
    public function delete(int $id): void
    {
        $this->index->delete($id);
    }

    /**
     * Remove multiple documents in a single transaction with one stats update.
     *
     * @param list<int> $ids Document IDs to remove; non-existent IDs are silently skipped.
     */
    public function deleteMany(array $ids): void
    {
        $this->index->deleteMany($ids);
    }

    /**
     * Return true if a document with the given ID exists in the index.
     *
     * @param int $id Document ID to check.
     */
    public function has(int $id): bool
    {
        return $this->index->has($id);
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
     * @param  bool   $fuzzy  When true, wordlist resolution uses Levenshtein matching
     *                        (same as search($phrase, fuzzy: true)); when false, exact/prefix only.
     * @return array{
     *     raw_tokens:       list<string>,
     *     filtered_tokens:  list<string>,
     *     stopwords_active: bool,
     *     stemmer_active:   bool,
     *     all_stripped:     bool,
     *     index_info:       array{total_documents: string, avg_doc_length: string},
     *     tokens: list<array{
     *         raw:           string,
     *         processed:     string,
     *         is_last:       bool,
     *         found:         bool,
     *         match_type:    string,
     *         wordlist_rows: list<array{term: string, num_hits: int, num_docs: int, distance: int|null}>,
     *         num_hits:      int,
     *         num_docs:      int,
     *     }>,
     *     boolean_postfix: list<string>,
     * }
     */
    public function inspectQuery(string $phrase, bool $fuzzy = false): array
    {
        $rawTokens = Tokenizer::tokenize($phrase);

        $verbose = $this->index->filterQueryTokensVerbose($rawTokens);
        /** @var list<string> $filteredTokens */
        $filteredTokens = $verbose['filtered'];
        /** @var list<string> $survivingRaw */
        $survivingRaw = $verbose['surviving_raw'];

        $lastIndex = count($filteredTokens) - 1;
        $tokens    = [];

        foreach ($filteredTokens as $i => $processed) {
            $isLast       = $i === $lastIndex;
            $rows         = $this->index->getWordlistByKeyword($processed, $isLast, $fuzzy);

            $matchType = match (true) {
                $rows === []                                                              => 'none',
                $fuzzy && isset($rows[0]['distance'])                                    => 'fuzzy',
                $this->asYouType && $isLast && $rows[0]['term'] !== $processed => 'prefix',
                default                                                                  => 'exact',
            };

            $wordlistRows = array_map(
                fn(array $r): array => [
                    'term'     => $r['term'],
                    'num_hits' => $r['num_hits'],
                    'num_docs' => $r['num_docs'],
                    'distance' => $r['distance'] ?? null,
                ],
                $rows
            );

            $tokens[] = [
                'raw'          => $survivingRaw[$i] ?? $processed,
                'processed'    => $processed,
                'is_last'      => $isLast,
                'found'        => $rows !== [],
                'match_type'   => $matchType,
                'wordlist_rows' => $wordlistRows,
                'num_hits'     => (int) array_sum(array_column($rows, 'num_hits')),
                'num_docs'     => (int) array_sum(array_column($rows, 'num_docs')),
            ];
        }

        /** @var array{total_documents: string, avg_doc_length: string} $indexInfo */
        $indexInfo = $this->index->getInfoValues(['total_documents', 'avg_doc_length']);

        return [
            'raw_tokens'       => $rawTokens,
            'filtered_tokens'  => $filteredTokens,
            'stopwords_active' => $this->index->stopwordsActive,
            'stemmer_active'   => $this->index->stemmerActive,
            'all_stripped'     => $verbose['all_stripped'],
            'index_info'       => $indexInfo,
            'tokens'           => $tokens,
            'boolean_postfix'  => $this->toPostfix('|' . $phrase),
        ];
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
        return new Highlighter(open: $open, close: $close, asYouType: $asYouType);
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
        $this->index->close();
    }

    // --- Search operations --------------------------------------------------

    /**
     * Run a BM25 ranked full-text search.
     *
     * When $fuzzy is true, Levenshtein matching is used against the wordlist
     * (respects $fuzzyDistance, $fuzzyPrefixLength, and $fuzzyMaxExpansions).
     * When false, exact + optional as-you-type prefix matching is used.
     *
     * @param  string $phrase       Raw search phrase; will be tokenised.
     * @param  bool   $fuzzy        When true, use Levenshtein matching.
     * @param  int    $numOfResults Maximum number of document IDs to return.
     * @return array{ids: list<int>, hits: int, docScores: array<int, float>}
     */
    public function search(string $phrase, bool $fuzzy = false, int $numOfResults = 100): array
    {
        /** @var list<string> $keywords */
        $keywords = $this->index->filterQueryTokens(Tokenizer::tokenize($phrase));

        /** @var array<int, float> $docScores */
        $docScores = [];

        $info           = $this->index->getInfoValues(['total_documents', 'avg_doc_length']);
        $totalDocuments = (int) ($info['total_documents'] ?? 0);
        $avgdl          = max(1.0, (float) ($info['avg_doc_length'] ?? 0));
        $k1             = $this->k1;
        $b              = $this->b;
        $lastIndex      = count($keywords) - 1;

        foreach ($keywords as $index => $term) {
            $isLastKeyword = $lastIndex === $index;
            $result = $this->index->getDocumentsAndCount($term, false, $isLastKeyword, $fuzzy);
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

        $limit = $this->index->maxDocs;

        /** Fetch capped doc IDs for one keyword (resolves prefix expansion / caching). */
        $fetchIds = fn(string $kw, bool $isLast): array =>
            $this->index->fetchBooleanDocIds(
                $this->index->resolveWordlistIds($kw, $isLast),
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

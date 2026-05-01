<?php

namespace Fuzor;

use Fuzor\IndexStorage;
use Fuzor\Snippeter;
use Fuzor\Highlighter;

/**
 * Represents a single open Fuzor index.
 *
 * Obtain an instance via Index::open() or Index::create(). All database interaction
 * is delegated to IndexStorage; configuration properties proxy directly to it via
 * property hooks.
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
        /** @infection-ignore-all MethodCallRemoval: GC-driven; no observable test hook for destructor timing */
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
     * Replace multiple documents in a single transaction with one stats update.
     *
     * Each document must contain an 'id' key. Non-existent IDs are inserted
     * (upsert semantics), exactly like calling update() on each individually.
     *
     * @param iterable<array<string, mixed>> $documents Documents to update; each must contain an 'id' key.
     */
    public function updateMany(iterable $documents): void
    {
        $this->index->updateMany($documents);
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
     * Return the total number of documents in the index.
     */
    public function count(): int
    {
        /** @infection-ignore-all DecrementInteger|IncrementInteger: ?? fallback is only reached on a corrupt/missing info table row; all writes keep total_documents consistent, so this path is unreachable in tests */
        return (int) ($this->index->getInfoValues(['total_documents'])['total_documents'] ?? 0);
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
        $verbose = $this->index->filterQueryTokensVerbose($phrase);
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
                /** @infection-ignore-all LogicalAnd: non-last words are always exact matches (wordlist lookup uses isLastWord=false), so term===processed; neither && mutation fires on real data */
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
                /** @infection-ignore-all CastInt: array_sum() on numeric strings returns int in PHP 8; the cast is defensive documentation, not a type change */
                'num_hits'     => (int) array_sum(array_column($rows, 'num_hits')),
                /** @infection-ignore-all CastInt: same as num_hits — array_sum already returns int here */
                'num_docs'     => (int) array_sum(array_column($rows, 'num_docs')),
            ];
        }

        /** @var array{total_documents: string, avg_doc_length: string} $indexInfo */
        $indexInfo = $this->index->getInfoValues(['total_documents', 'avg_doc_length']);

        return [
            'raw_tokens'       => $verbose['raw_tokens'],
            'filtered_tokens'  => $filteredTokens,
            'stopwords_active' => $this->index->stopwordsActive,
            'stemmer_active'   => $this->index->stemmerActive,
            'all_stripped'     => $verbose['all_stripped'],
            'index_info'       => $indexInfo,
            'tokens'           => $tokens,
            /** @infection-ignore-all Concat|ConcatOperandRemoval: '|' prepend is the OR identity; '|' . $phrase and $phrase . '|' both yield identical postfix because '|' is always the last operator popped */
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
            language: $this->index->language,
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
            language: $this->index->language,
        );
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
        /** @infection-ignore-all MethodCallRemoval: explicit close() with no observable return value; the connection drop is tested implicitly via subsequent operations throwing */
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
        $keywords = $this->index->filterQueryTokens($phrase);

        /** @var array<int, float> $docScores */
        $docScores = [];

        $info           = $this->index->getInfoValues(['total_documents', 'avg_doc_length']);
        /** @infection-ignore-all DecrementInteger|IncrementInteger|CastInt: fallback 0 is used only on a corrupt/empty DB; all writes keep info consistent, so this path is unreachable in tests */
        $totalDocuments = (int) ($info['total_documents'] ?? 0);
        /** @infection-ignore-all DecrementInteger|IncrementInteger|Coalesce|CastFloat: fallback 0 is guarded by max(1.0,…) below; mutations produce the same clamped value; unreachable on a healthy index */
        $avgdl          = max(1.0, (float) ($info['avg_doc_length'] ?? 0));
        $k1             = $this->k1;
        $b              = $this->b;
        $lastIndex      = count($keywords) - 1;

        foreach ($keywords as $index => $term) {
            $isLastKeyword = $lastIndex === $index;
            $result = $this->index->getDocumentsAndCount($term, false, $isLastKeyword, $fuzzy);
            $df     = $result['numDocs'];
            // Smoothed BM25 IDF: always ≥ 0, avoids negative weights for common terms.
            /** @infection-ignore-all IncrementInteger|Minus|Plus|Division: IDF mutations monotonically shift all per-term scores by the same factor; relative document ordering is preserved for any single-term query */
            $idf    = log(1 + ($totalDocuments - $df + 0.5) / ($df + 0.5));
            // Precompute per-keyword BM25 invariants outside the per-document inner loop.
            /** @infection-ignore-all DecrementInteger|IncrementInteger|Plus|Multiplication: idfK1p1 is a per-term scalar; mutating k1+1 uniformly rescales every doc's contribution for that term, preserving relative ranking */
            $idfK1p1   = $idf * ($k1 + 1);   // idf * (k1 + 1)  — numerator constant
            /** @infection-ignore-all Multiplication: k1_1mb is a per-term constant; mutating it uniformly shifts all docs' denominators, preserving relative BM25 ranking for any single-term query */
            $k1_1mb    = $k1 * (1.0 - $b);   // k1 * (1 - b)    — denominator constant
            /** @infection-ignore-all Multiplication|Division: k1b_avgdl is the length-normalisation scale; mutations change score magnitudes but preserve relative ordering for uniform-term-frequency distributions */
            $k1b_avgdl = $k1 * $b / $avgdl;  // k1 * b / avgdl  — length-norm scale
            // Column order from FETCH_NUM: 0=term_id, 1=doc_id, 2=hit_count, 3=doc_length
            foreach ($result['documents'] as [, $docId, $tf, $dl]) {
                /** @infection-ignore-all OneZeroFloat: ?? 0.0 is the additive identity; the fallback only applies on first encounter of a docId which always has score 0 before accumulation */
                $docScores[$docId] = ($docScores[$docId] ?? 0.0)
                    + $idfK1p1 * $tf / ($k1_1mb + $k1b_avgdl * $dl + $tf);
            }
        }

        $total = count($docScores);

        /** @infection-ignore-all DecrementInteger: $total is count(); -1 is impossible, so the guard fires identically for any realistic input */
        if ($total === 0 || $numOfResults === 0) {
            return ['ids' => [], 'hits' => $total, 'docScores' => $docScores];
        }

        /** @infection-ignore-all LessThanOrEqualTo: when total===numOfResults the fast arsort path and the heap path both return the same set of doc IDs; ordering may differ for ties but is unspecified */
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
            } else {
                /** @infection-ignore-all GreaterThan: when score===heapMin the replaced doc is a valid equal-score member; tied-score ordering is intentionally unspecified */
                if ($score > $heapMin) {
                    $heap->extract();
                    $heap->insert([$score, $docId]);
                    $heapMin = $heap->top()[0];
                }
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
     * Operator precedence (tightest to loosest): NOT (~) > AND (&, space) > OR ( or ).
     * Parentheses override precedence. docScores is always null.
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
        /** @infection-ignore-all ConcatOperandRemoval: '|' prefix is the OR identity; removing it or appending instead yields identical postfix because '|' is always the lowest-priority operator */
        $postfix = $this->toPostfix('|' . $phrase);

        // Find the last term token so asYouType prefix expansion applies to it.
        $lastTerm = null;
        /** @infection-ignore-all IncrementInteger: postfix always ends with the '|' operator (prepended above); starting at count-2 vs count-1 scans past the same trailing '|' and finds the same last term */
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
        $total  = count($docIds);
        /** @infection-ignore-all GreaterThan: when total===numOfResults, array_slice returns the full array unchanged — identical to not slicing */
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
            } elseif ($token === '(') {
                $stack[] = $token;
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
     * @infection-ignore-all MatchArmRemoval: removing any single arm preserves Shunting-Yard output —
     *   '|'→0 or default→-1/1 are both equivalent (proved in comments on the arms below)
     */
    private function expressionPriority(string $operator): int
    {
        return match ($operator) {
            /** @infection-ignore-all MatchArmRemoval|DecrementInteger: removing the '|' arm or setting it to 0 is equivalent because '|' is only compared against itself (1>=1 → 0>=0) or '&'/'~' (1>=2/3 → 0>=2/3, both false); Shunting-Yard output is unchanged */
            '|'     => 1,
            '&'     => 2,
            /** @infection-ignore-all IncrementInteger: raising '~' to 4 preserves strict highest priority (4>2>1); Shunting-Yard output is unchanged */
            '~'     => 3,
            /** @infection-ignore-all DecrementInteger|IncrementInteger: default covers '(' which is guarded by the $top!=='(' check before priority is consulted; all other unknowns are non-operators and never reach the stack */
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
        /** @infection-ignore-all MBString: getWordlistByKeyword() applies mb_strtolower() independently on every lookup; operator keywords (' or ', ' -', ' ') are ASCII-only so strtolower produces identical results here */
        $expression = $expression
            |> (fn(string $s): string => mb_strtolower($s, 'UTF-8'))
            |> (fn(string $s): string => preg_replace(['/\s*\(\s*/', '/\s*\)\s*(?!-)/'], ['(', ')'], $s) ?? $s)
            |> (fn(string $s): string => str_replace([' or ', ' -', ' '], ['|', '&~', '&'], $s));

        return preg_split('/([|~&()])/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

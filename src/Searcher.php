<?php

namespace Fuzor;

use Fuzor\Engines\SqliteEngine;

/**
 * Public API for Fuzor full-text search.
 *
 * Orchestrates indexing and searching via SqliteEngine. Holds the engine
 * instance. All database interaction goes through $engine — this class
 * handles TF-IDF scoring and boolean query parsing only.
 */
class Searcher
{
    /** Underlying SQLite engine; configure search behaviour directly on this instance. */
    public SqliteEngine $engine;

    /**
     * @param string $storagePath Writable directory where index files are stored.
     */
    public function __construct(string $storagePath)
    {
        $this->engine = new SqliteEngine($storagePath);
    }

    /**
     * Run a strict TF-IDF ranked full-text search (exact + optional as-you-type prefix).
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
     * Run a fuzzy TF-IDF ranked full-text search using Levenshtein matching.
     *
     * Respects engine fuzzyDistance, fuzzyPrefixLength, and fuzzyMaxExpansions.
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
     * Core Okapi BM25 scoring loop shared by search() and searchFuzzy().
     *
     * Tokenises $phrase, computes per-document scores using the full BM25
     * formula with document-length normalisation across all keywords, and
     * returns results sorted by relevance descending.
     *
     * @param  string $phrase       Raw search phrase; will be tokenised.
     * @param  int    $numOfResults Maximum number of document IDs to return.
     * @param  bool   $fuzzy        When true, Levenshtein fuzzy matching is used.
     * @return array{ids: list<int>, hits: int, docScores: array<int, float>}
     */
    private function scorePhrase(string $phrase, int $numOfResults, bool $fuzzy): array
    {
        $start = microtime(true);

        /** @var string[] $keywords */
        $keywords = $this->engine->breakIntoTokens($phrase);

        /** @var array<int, float> $docScores */
        $docScores = [];

        $count     = $this->engine->getValueFromInfoTable('total_documents');
        $avgdl     = (float) ($this->engine->getValueFromInfoTable('avg_doc_length') ?: 1);
        $k1        = $this->engine->k1;
        $b         = $this->engine->b;
        $lastIndex = count($keywords) - 1;

        foreach ($keywords as $index => $term) {
            $isLastKeyword = $lastIndex === $index;
            $result = $this->engine->getDocumentsAndCount($term, false, $isLastKeyword, $fuzzy);
            $idf    = log($count / max(1, $result['numDocs']));
            // Full Okapi BM25: IDF * ((k1 + 1) * tf) / (k1 * (1 - b + b * dl/avgdl) + tf)
            foreach ($result['documents'] as $document) {
                $docId = $document['doc_id'];
                $tf    = $document['hit_count'];
                $dl    = $document['doc_length'];
                $docScores[$docId] = ($docScores[$docId] ?? 0) + $idf * (($k1 + 1) * $tf) / ($k1 * (1 - $b + $b * $dl / $avgdl) + $tf);
            }
        }

        arsort($docScores);

        $elapsed = microtime(true) - $start;

        return [
            'ids'       => $docScores |> array_keys(...) |> (fn($k) => array_slice($k, 0, $numOfResults)),
            'hits'      => count($docScores),
            'docScores' => $docScores,
            'duration' => $elapsed
        ];
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
     * @return array{ids: list<int>, hits: int}
     */
    public function searchBoolean(string $phrase, int $numOfResults = 100): array
    {
        /** @var int $start */
        $start = microtime(true);

        /** @var array<int, string|list<int>> $stack */
        $stack = [];

        /** @var array<string, list<int>> $cache */
        $cache = [];

        // Resolve a stack operand to a doc-id list, caching DB lookups by term.
        $resolve = function (string|array|null $operand) use (&$cache): array {
            if ($operand === null) return [];
            if (is_array($operand)) return $operand;
            return $cache[$operand] ??= array_column(
                $this->engine->getAllDocumentsForKeyword($operand, true),
                'doc_id'
            );
        };

        /** @var string[] $postfix */
        $postfix = $this->toPostfix("|" . $phrase);

        foreach ($postfix as $token) {
            if ($token === '&') {
                $left    = $resolve(array_pop($stack));
                $right   = $resolve(array_pop($stack));
                $stack[] = array_values(array_intersect($left, $right));
            } elseif ($token === '|') {
                $left    = $resolve(array_pop($stack));
                $right   = $resolve(array_pop($stack));
                // array_flip + '+' achieves union in one O(n+m) pass without array_merge + array_unique
                $stack[] = array_keys(array_flip($left) + array_flip($right));
            } elseif ($token === '~') {
                // ~ is always preceded by a string operand; the lexer places a term token before every ~
                $term    = array_pop($stack);
                $stack[] = array_column(
                    $this->engine->getAllDocumentsForWhereKeywordNot((string) $term, true),
                    'doc_id'
                );
            } else {
                $stack[] = $token;
            }
        }

        /** @var list<int> $docs */
        $docs = array_slice(!empty($stack) ? $stack[0] : [], 0, $numOfResults);

        $elapsed = microtime(true) - $start;

        return [
            'ids'  => $docs,
            'hits' => count($docs),
            'duration' => $elapsed,
        ];
    }

    /**
     * Convert an infix boolean expression to postfix (Reverse Polish) notation.
     *
     * Uses the Shunting-Yard algorithm. Operator precedence: ~ (3) > & (2) > | (1).
     *
     * @param  string   $exp Infix expression containing operators |, &, ~, (, ).
     * @return string[]      Tokens in postfix order.
     */
    private function toPostfix(string $exp): array
    {
        /** @var string[] $postfix */
        $postfix = [];

        /** @var string[] $stack */
        $stack = [];

        foreach ($this->lexExpression($exp) as $token) {
            if ($this->isExpressionOperand($token)) {
                $postfix[] = $token;
            } elseif ($token === ')') {
                while (($top = array_pop($stack)) !== '(' && !empty($top)) {
                    $postfix[] = $top;
                }
            } else {
                $tokenPriority = $this->expressionPriority($token);
                while (!empty($stack) && ($top = end($stack)) !== '(' && $this->expressionPriority($top) >= $tokenPriority) {
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
     * Return true if the token is an operand (word), false if it is an operator.
     *
     * @param string $char Single character or token to classify.
     */
    private function isExpressionOperand(string $char): bool
    {
        return match ($char) {
            '|', '&', '~', '(', ')' => false,
            default => true,
        };
    }

    /**
     * Return the precedence level of a boolean operator.
     *
     * Higher value binds tighter: ~ (3) > & (2) > | (1).
     * Parentheses are assigned 4 but handled separately in toPostfix.
     *
     * @param string $char Operator character: |, &, ~, ( or ).
     */
    private function expressionPriority(string $char): int
    {
        return match ($char) {
            '|'      => 1,
            '&'      => 2,
            '~'      => 3,
            '(', ')' => 4,
            default  => 0,
        };
    }

    /**
     * Tokenise a raw boolean query string into operators and word operands.
     *
     * Normalises natural-language syntax before splitting:
     *   " or " → |,   " -" → ~,   " " → &
     *
     * @param  string   $string Raw query string.
     * @return string[]         Alternating word tokens and operator characters.
     */
    private function lexExpression(string $string): array
    {
        $string = $string
            |> (fn(string $s): string => str_replace([' or ', ' -', ' '], ['|', '~', '&'], $s))
            |> mb_strtolower(...);

        return preg_split('/([|~&()])/', $string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    }
}

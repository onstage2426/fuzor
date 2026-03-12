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
    private string $resolvedPath;

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

    /**
     * @param SqliteEngine $engine       Configured engine bound to the index file.
     * @param string       $resolvedPath Canonical absolute path to the index file.
     */
    private function __construct(SqliteEngine $engine, string $resolvedPath)
    {
        $this->engine       = $engine;
        $this->resolvedPath = $resolvedPath;
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
    public static function open(string $path): static
    {
        $resolved = self::resolvePath($path);
        $engine = new SqliteEngine(dirname($resolved));
        $engine->selectIndex(basename($resolved));
        return new static($engine, $resolved);
    }

    /**
     * Create a new index file, replacing any existing file at the same path.
     *
     * @param  string $path Absolute or relative path for the new SQLite index file.
     */
    public static function create(string $path): static
    {
        $resolved = self::resolvePath($path);
        $engine = new SqliteEngine(dirname($resolved));
        $engine->createIndex(basename($resolved));
        return new static($engine, $resolved);
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
     * @return array{ids: list<int>, hits: int}
     */
    public function searchBoolean(string $phrase, int $numOfResults = 100): array
    {
        /** @var array<int, string|list<int>> $stack */
        $stack = [];

        /** @var array<string, list<int>> $cache */
        $cache = [];

        // Resolve a stack operand to a doc-id list, caching DB lookups by term.
        $resolve = function (string|array|null $operand) use (&$cache): array {
            if ($operand === null) {
                return [];
            }
            if (is_array($operand)) {
                return $operand;
            }
            return $cache[$operand] ??= array_column(
                $this->engine->getDocumentsAndCount($operand, true)['documents'],
                'doc_id'
            );
        };

        // Prepend "|" so the Shunting-Yard algorithm always has a left-hand operand
        // on the stack. OR with an empty set is the identity, so it does not affect the result.
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

        /** @var list<int> $all */
        $all  = $resolve(array_pop($stack));
        $docs = array_slice($all, 0, $numOfResults);

        return [
            'ids'      => $docs,
            'hits'     => count($all),
        ];
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
        /** @var string[] $keywords */
        $keywords = Tokenizer::tokenize($phrase);

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
            // Okapi BM25: IDF * ((k1 + 1) * tf) / (k1 * (1 - b + b * dl/avgdl) + tf)
            foreach ($result['documents'] as $document) {
                $documentId = $document['doc_id'];
                $tf    = $document['hit_count'];
                $dl    = $document['doc_length'];
                $docScores[$documentId] = ($docScores[$documentId] ?? 0)
                    + $idf * (($k1 + 1) * $tf) / ($k1 * (1 - $b + $b * $dl / $avgdl) + $tf);
            }
        }

        arsort($docScores);

        return [
            'ids'       => array_slice(array_keys($docScores), 0, $numOfResults),
            'hits'      => count($docScores),
            'docScores' => $docScores,
        ];
    }

    /**
     * Convert an infix boolean expression to postfix (Reverse Polish) notation.
     *
     * Uses the Shunting-Yard algorithm. Operator precedence: ~ (3) > & (2) > | (1).
     *
     * @param  string   $expression Infix expression containing operators |, &, ~, (, ).
     * @return string[]      Tokens in postfix order.
     */
    private function toPostfix(string $expression): array
    {
        /** @var string[] $postfix */
        $postfix = [];

        /** @var string[] $stack */
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
     * @return int          Precedence level; 0 for non-operator tokens.
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
     * @param  string   $expression Raw query string.
     * @return string[]         Alternating word tokens and operator characters.
     */
    private function lexExpression(string $expression): array
    {
        $expression = $expression
            |> mb_strtolower(...)
            |> (fn(string $s): string => str_replace([' or ', ' -', ' '], ['|', '&~', '&'], $s));

        return preg_split('/([|~&()])/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    }
}

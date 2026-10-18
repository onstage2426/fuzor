<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Stop word filter for an index's SchemaConfig::$stopWords.
 *
 * @internal
 */
final class Stopwords
{
    /** @var array<string, true> */
    private readonly array $words;

    /** @param list<string> $words Lowercased single-token words (see Index::normalizeStopWords()). */
    public function __construct(array $words)
    {
        $this->words = array_fill_keys($words, true);
    }

    /**
     * Remove stop words from a token list.
     *
     * @param  list<string> $tokens Lowercased tokens from the tokeniser.
     * @return list<string>         Tokens with stop words removed, re-indexed.
     */
    public function filter(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (!isset($this->words[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }
}

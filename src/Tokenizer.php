<?php

namespace Fuzor;

/** Splits raw text into lowercase tokens. */
final class Tokenizer
{
    /**
     * Fast path for ASCII-only input avoids Unicode property classes and
     * mb_strtolower, both of which carry significant overhead.
     * Falls back to a full Unicode-aware scan for non-ASCII text.
     *
     * @param  string   $text Raw input text.
     * @return string[]       Lowercase token list.
     */
    public static function tokenize(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return preg_split('/[^\w@]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        }
        preg_match_all('/[\p{L}\p{N}\p{Pc}@]+/u', mb_strtolower($text, 'UTF-8'), $m);
        return $m[0];
    }
}

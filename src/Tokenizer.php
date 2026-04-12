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
     * @param  string      $text Raw input text.
     * @return list<string>        Lowercase token list.
     */
    public static function tokenize(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return preg_split('/[^\w@]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        preg_match_all('/[\p{L}\p{N}\p{Pc}@]+/u', mb_strtolower($text, 'UTF-8'), $m);
        return $m[0];
    }

    /**
     * Same tokenisation as tokenize(), but also returns the byte offset of each token
     * within the original $text. Offsets are suitable for use with substr().
     *
     * @param  string                    $text Raw input text.
     * @return list<array{0: string, 1: int<0, max>}> Each entry is [lowercaseToken, byteOffset].
     */
    public static function tokenizeWithOffsets(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            preg_match_all('/[\w@]+/', strtolower($text), $m, PREG_OFFSET_CAPTURE);
        } else {
            preg_match_all('/[\p{L}\p{N}\p{Pc}@]+/u', mb_strtolower($text, 'UTF-8'), $m, PREG_OFFSET_CAPTURE);
        }
        /** @var list<array{0: string, 1: int<0, max>}> */
        return $m[0];
    }
}

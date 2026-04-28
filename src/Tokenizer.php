<?php

namespace Fuzor;

/**
 * Splits raw text into lowercase tokens.
 *
 * All methods are static. Pass a BCP 47 language tag to tokenize() or
 * tokenizeWithOffsets() to get language-aware tokenisation — CJK and Thai text
 * is automatically split into n-grams so searches work without a word segmenter.
 * ASCII tokens inside mixed-language text pass through unchanged.
 *
 * split() and splitWithOffsets() perform base tokenisation only (whitespace /
 * punctuation splitting, no n-gram expansion) and are suitable for callers that
 * need the raw token list before any language processing.
 */
final class Tokenizer
{
    /** Languages that use contiguous scripts with no whitespace word delimiters. */
    private const NGRAM_SCRIPTS = [
        'zh' => 2, 'ja' => 2, 'ko' => 2, 'th' => 3,
    ];

    /**
     * Tokenise $text using the given BCP 47 language tag.
     *
     * For CJK/Thai languages each token is expanded into overlapping n-grams.
     * Bigram languages (zh/ja/ko) also emit individual character unigrams so
     * single-character searches are supported. ASCII tokens pass through unchanged.
     *
     * @return list<string>
     */
    public static function tokenize(
        string $text,
        ?string $language = null,
        bool $includeUnigrams = true,
    ): array {
        $tokens = self::split($text);
        $n      = $language !== null ? (self::NGRAM_SCRIPTS[$language] ?? 0) : 0;
        if ($n === 0) {
            return $tokens;
        }
        return self::expandNgrams($tokens, $n, $includeUnigrams && $n === 2);
    }

    /**
     * Tokenise $text and return each token's byte offset within the original string.
     *
     * $includeUnigrams defaults to false — body scanning for snippet windows benefits
     * from higher bigram/trigram density. Pass true when single-character lookups matter.
     *
     * @return list<array{0: string, 1: int<0, max>}>
     */
    public static function tokenizeWithOffsets(
        string $text,
        ?string $language = null,
        bool $includeUnigrams = false,
    ): array {
        $n = $language !== null ? (self::NGRAM_SCRIPTS[$language] ?? 0) : 0;
        if ($n === 0) {
            return self::splitWithOffsets($text);
        }
        return self::ngramWithOffsets($text, $n, $includeUnigrams);
    }

    // --- Base tokenisation (language-unaware) ---------------------------------

    /**
     * Split $text into lowercase tokens by whitespace and punctuation.
     *
     * Fast path for ASCII-only input avoids Unicode property classes and
     * mb_strtolower, both of which carry significant overhead.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            /** @infection-ignore-all both paths produce identical output for ASCII; fast-path exists for performance only */
            return preg_split('/[^\w@]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        preg_match_all('/[\p{L}\p{N}\p{Pc}@]+/u', mb_strtolower($text, 'UTF-8'), $m);
        return $m[0];
    }

    /**
     * Same as split(), but also returns the byte offset of each token within $text.
     *
     * @return list<array{0: string, 1: int<0, max>}>
     */
    public static function splitWithOffsets(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            preg_match_all('/[\w@]+/', strtolower($text), $m, PREG_OFFSET_CAPTURE);
        } else {
            preg_match_all('/[\p{L}\p{N}\p{Pc}@]+/u', mb_strtolower($text, 'UTF-8'), $m, PREG_OFFSET_CAPTURE);
        }
        /** @var list<array{0: string, 1: int<0, max>}> */
        return $m[0];
    }

    // --- N-gram utilities -----------------------------------------------------

    /**
     * Slide a window of $n codepoints across the lowercased $text.
     *
     * Tokens shorter than $n (tail of string) are omitted — they would be incomplete
     * n-grams and degrade precision. When $includeUnigrams is true, each individual
     * codepoint is also emitted so single-character searches work.
     *
     * @return list<string>
     */
    public static function ngram(string $text, int $n, bool $includeUnigrams = false): array
    {
        $text  = mb_strtolower($text, 'UTF-8');
        $chars = mb_str_split($text, 1, 'UTF-8');
        $count = count($chars);
        $out   = [];

        for ($i = 0; $i <= $count - $n; $i++) {
            $out[] = implode('', array_slice($chars, $i, $n));
        }

        if ($includeUnigrams) {
            foreach ($chars as $ch) {
                $out[] = $ch;
            }
        }

        return $out;
    }

    /**
     * N-gram tokeniser that also returns the byte offset of each token within $text.
     *
     * Byte offsets are accumulated by walking the codepoint array once to avoid O(n²)
     * recomputation. When $includeUnigrams is true, the output is sorted by byte offset
     * so callers receive a monotonically increasing offset stream.
     *
     * @return list<array{0: string, 1: int<0, max>}>
     */
    public static function ngramWithOffsets(string $text, int $n, bool $includeUnigrams = false): array
    {
        $text  = mb_strtolower($text, 'UTF-8');
        $chars = mb_str_split($text, 1, 'UTF-8');
        $count = count($chars);

        $byteOffsets = [];
        $bytePos     = 0;
        foreach ($chars as $i => $ch) {
            $byteOffsets[$i] = $bytePos;
            $bytePos        += strlen($ch);
        }

        $out = [];

        for ($i = 0; $i <= $count - $n; $i++) {
            $out[] = [implode('', array_slice($chars, $i, $n)), $byteOffsets[$i]];
        }

        if ($includeUnigrams) {
            foreach ($chars as $i => $ch) {
                $out[] = [$ch, $byteOffsets[$i]];
            }
            usort($out, fn(array $a, array $b): int => $a[1] <=> $b[1]);
        }

        /** @var list<array{0: string, 1: int<0, max>}> */
        return $out;
    }

    /**
     * Returns the n-gram window size for the given BCP 47 language tag,
     * or 0 if standard whitespace tokenisation should be used.
     */
    public static function ngramSize(string $language): int
    {
        return self::NGRAM_SCRIPTS[$language] ?? 0;
    }

    /**
     * Returns true when $token contains characters from a script that requires
     * n-gram tokenisation (CJK unified ideographs, Hiragana/Katakana, Hangul, Thai).
     */
    public static function isNgramToken(string $token): bool
    {
        /** @infection-ignore-all PHP coerces int return from preg_match to bool on declared bool return type */
        return (bool) preg_match(
            '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0E00}-\x{0E7F}]/u',
            $token,
        );
    }

    // --- Private helpers ------------------------------------------------------

    /**
     * Expand a list of base tokens: CJK/Thai tokens are replaced by their n-grams;
     * ASCII tokens pass through unchanged.
     *
     * @param  list<string> $tokens
     * @return list<string>
     */
    private static function expandNgrams(array $tokens, int $n, bool $includeUnigrams): array
    {
        $expanded = [];
        foreach ($tokens as $token) {
            if (self::isNgramToken($token)) {
                foreach (self::ngram($token, $n, $includeUnigrams) as $t) {
                    $expanded[] = $t;
                }
            } else {
                $expanded[] = $token;
            }
        }
        return $expanded !== [] ? $expanded : $tokens;
    }
}

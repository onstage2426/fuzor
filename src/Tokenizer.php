<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Splits raw text into lowercase tokens.
 *
 * All methods are static. tokenize() and tokenizeWithOffsets() split CJK and Thai words into
 * n-grams so they can be searched without a word segmenter: the script of each token decides
 * (CJK ideographs, kana and Hangul: bigrams plus single characters; Thai: trigrams), so text
 * mixing scripts is handled per word and nothing has to be configured. Other tokens pass through
 * unchanged.
 *
 * split() and splitWithOffsets() perform base tokenisation only (whitespace / punctuation
 * splitting, no n-gram expansion).
 */
final class Tokenizer
{
    /** Lowercase Latin letters with diacritics → base letters, for sortKey(). */
    private const array SORT_FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ǎ' => 'a', 'ǻ' => 'a',
        'æ' => 'ae', 'ǽ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ǐ' => 'i',
        'ĳ' => 'ij',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ǒ' => 'o', 'ǿ' => 'o',
        'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
        'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't',
        'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ų' => 'u', 'ǔ' => 'u', 'ǖ' => 'u', 'ǘ' => 'u', 'ǚ' => 'u', 'ǜ' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    private function __construct()
    {
    }

    /**
     * A word in text with non-ASCII characters: letters, digits, connectors and '@', and after
     * the first character also combining marks, which belong to the letter before them (an
     * accent written as its own code point, the vowel signs and tone marks of Indic scripts,
     * Arabic, Hebrew and Thai). Without them a word was cut in two at every mark.
     */
    private const string WORD_PATTERN = '/[\p{L}\p{N}\p{Pc}@][\p{L}\p{N}\p{Pc}\p{M}@]*/u';

    /** Scripts written without spaces between words: CJK ideographs, kana, Hangul, Thai. */
    private const string NGRAM_SCRIPT = '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0E00}-\x{0E7F}]/u';

    /** NGRAM_SCRIPT with the bigram characters as group 1, for ngramSize(). */
    private const string NGRAM_SIZE
        = '/([\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}])|[\x{0E00}-\x{0E7F}]/u';

    /** The bigram part of NGRAM_SCRIPT (CJK ideographs, kana, Hangul); a token with only Thai gets trigrams. */
    private const string BIGRAM_SCRIPT = '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u';

    /**
     * Tokenise $text: split() with every CJK or Thai token expanded into its n-grams.
     *
     * Bigram tokens (CJK, kana, Hangul) also emit their single characters when $includeUnigrams
     * is true, so single-character searches work. Other tokens pass through unchanged.
     *
     * @return list<string>
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function tokenize(string $text, bool $includeUnigrams = true): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            /** @infection-ignore-all both paths produce identical output for ASCII; fast-path exists for performance only */
            return preg_split('/[^\w@]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        preg_match_all(self::WORD_PATTERN, mb_strtolower($text, 'UTF-8'), $m);
        $tokens = $m[0];
        if (preg_match(self::NGRAM_SCRIPT, $text) !== 1) {
            return $tokens;
        }
        $expanded = [];
        foreach ($tokens as $token) {
            $n = self::ngramSize($token);
            if ($n === 0) {
                $expanded[] = $token;
                continue;
            }
            foreach (self::ngram($token, $n, $includeUnigrams && $n === 2) as $gram) {
                $expanded[] = $gram;
            }
        }
        return $expanded;
    }

    /**
     * Tokenise $text and return each token's byte offset within the lowercased text.
     *
     * Same tokens as tokenize(); $includeUnigrams defaults to false here, since body scanning
     * for snippet windows benefits from the denser bigrams and trigrams.
     *
     * @return list<array{0: string, 1: int<0, max>}>
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function tokenizeWithOffsets(string $text, bool $includeUnigrams = false): array
    {
        $tokens = self::splitWithOffsets($text);
        if (!self::hasNgramScript($text)) {
            return $tokens;
        }
        $expanded = [];
        foreach ($tokens as [$token, $offset]) {
            $n = self::ngramSize($token);
            if ($n === 0) {
                $expanded[] = [$token, $offset];
                continue;
            }
            foreach (self::ngramWithOffsets($token, $n, $includeUnigrams && $n === 2) as [$gram, $at]) {
                $expanded[] = [$gram, $offset + $at];
            }
        }
        return $expanded;
    }

    /**
     * Whether $text holds any CJK or Thai character, i.e. whether tokenize() can differ from split().
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function hasNgramScript(string $text): bool
    {
        // ASCII text cannot hold one; most text is caught here without a Unicode scan.
        return preg_match('/[^\x00-\x7F]/', $text) === 1 && preg_match(self::NGRAM_SCRIPT, $text) === 1;
    }

    // --- Base tokenisation (no n-grams) -----------------------------------------

    /**
     * Split $text into lowercase tokens by whitespace and punctuation.
     *
     * Fast path for ASCII-only input avoids Unicode property classes and
     * mb_strtolower, both of which carry significant overhead.
     *
     * @return list<string>
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function split(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            /** @infection-ignore-all both paths produce identical output for ASCII; fast-path exists for performance only */
            return preg_split('/[^\w@]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        preg_match_all(self::WORD_PATTERN, mb_strtolower($text, 'UTF-8'), $m);
        return $m[0];
    }

    /**
     * Same as split(), but also returns the byte offset of each token within $text.
     *
     * @return list<array{0: string, 1: int<0, max>}>
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function splitWithOffsets(string $text): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            preg_match_all('/[\w@]+/', strtolower($text), $m, PREG_OFFSET_CAPTURE);
        } else {
            preg_match_all(self::WORD_PATTERN, mb_strtolower($text, 'UTF-8'), $m, PREG_OFFSET_CAPTURE);
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
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
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
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
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

        return $out;
    }

    /**
     * The n-gram size tokenize() uses for $token: 2 when it holds CJK ideographs, kana or Hangul,
     * 3 when it holds Thai (and none of those), 0 for a token it leaves whole.
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function ngramSize(string $token): int
    {
        // One scan: a bigram character anywhere wins, else a Thai one gives trigrams.
        if (preg_match(self::NGRAM_SIZE, $token, $m) !== 1) {
            return 0;
        }
        return isset($m[1]) || preg_match(self::BIGRAM_SCRIPT, $token) === 1 ? 2 : 3;
    }

    /**
     * Returns true when $token contains characters from a script that requires
     * n-gram tokenisation (CJK unified ideographs, Hiragana/Katakana, Hangul, Thai).
     *
     * @internal Fuzor's own tokenisation; only sortKey() is public API.
     */
    public static function isNgramToken(string $token): bool
    {
        return preg_match(self::NGRAM_SCRIPT, $token) === 1;
    }

    /**
     * Normalise a value for use as a human-facing sort key.
     *
     * Facet sort compares strings byte-wise, so "Zebra" sorts before "apple" and "Éclair"
     * after every ASCII word. Store the result of this method in its own facet field (for
     * example 'title_sort') and sort on that for an A–Z order that ignores case and accents:
     * lowercase, Latin diacritics folded to their base letter (é → e, ß → ss, æ → ae, ø → o),
     * combining accents removed, whitespace collapsed. Other scripts are lowercased and
     * otherwise left as they are.
     *
     * The folding table is built in, not taken from ext-intl, so every server produces the
     * same key and one index never mixes keys built under different environments.
     */
    public static function sortKey(string $text): string
    {
        $key = strtr(mb_strtolower($text, 'UTF-8'), self::SORT_FOLD);
        // Decomposed input ("e" + U+0301) carries its accents as combining marks: drop them too.
        $key = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $key) ?? $key;
        return trim(preg_replace('/\s+/u', ' ', $key) ?? $key);
    }
}

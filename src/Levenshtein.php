<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Unicode-aware edit distance (optimal string alignment: adjacent swaps cost one), counted in
 * codepoints rather than bytes.
 *
 * All methods are static; no instance is needed.
 *
 * @internal
 */
final class Levenshtein
{
    private function __construct()
    {
    }

    /**
     * Unicode-aware edit distance with adjacent transpositions (optimal string alignment).
     *
     * Insertions, deletions, substitutions, and swaps of two adjacent codepoints each cost one,
     * so "casaul" is one edit from "casual" (plain Levenshtein counts two).
     *
     * Both strings are re-encoded into a shared single-byte form (non-ASCII codepoints remapped
     * to bytes 128–255) and measured with the native C levenshtein() first. A transposition only
     * ever lowers a distance of 2 or more, so a native result of 0 or 1 is final; otherwise the
     * OSA distance is computed in PHP on the encoded bytes, stopping as soon as every cell of a
     * row exceeds $max. Supports up to 128 distinct non-ASCII codepoints per pair, which is
     * plenty for search terms.
     *
     * @param  string   $a   First string (already lowercased).
     * @param  string   $b   Second string (already lowercased).
     * @param  int|null $max Callers that only need to know whether the distance is <= $max
     *                       may pass it: any larger distance is then reported as $max + 1.
     * @return int           Edit distance in Unicode characters.
     */
    public static function distance(string $a, string $b, ?int $max = null): int
    {
        $map = [];
        self::toAscii($a, $map);
        self::toAscii($b, $map);
        $levenshtein = levenshtein($a, $b);
        if ($levenshtein <= 1) {
            return $levenshtein;
        }
        return self::osa($a, $b, $max ?? $levenshtein);
    }

    /**
     * Optimal string alignment distance of two single-byte strings, capped at $max + 1.
     *
     * Classic dynamic programme over three rows (the transposition step looks two rows back).
     * The Levenshtein distance is an upper bound, so the caller passes it (or a smaller $max).
     */
    private static function osa(string $a, string $b, int $max): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        if (abs($lenA - $lenB) > $max) {
            return $max + 1;
        }
        $prev2 = [];
        $prev  = range(0, $lenB);
        for ($i = 1; $i <= $lenA; $i++) {
            $cur    = [$i];
            $rowMin = $i;
            $ca     = $a[$i - 1];
            for ($j = 1; $j <= $lenB; $j++) {
                $cb   = $b[$j - 1];
                $cost = $ca === $cb ? 0 : 1;
                $d    = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $ca === $b[$j - 2] && $a[$i - 2] === $cb) {
                    $d = min($d, $prev2[$j - 2] + 1);
                }
                $cur[$j] = $d;
                $rowMin  = min($rowMin, $d);
            }
            if ($rowMin > $max) {
                return $max + 1;
            }
            $prev2 = $prev;
            $prev  = $cur;
        }
        return min($prev[$lenB], $max + 1);
    }

    /**
     * Re-encode multi-byte characters in $str to single bytes using a shared map.
     *
     * Non-ASCII UTF-8 sequences are assigned bytes starting at 128 in order of
     * first appearance. The same map must be passed for both strings compared
     * with distance() so that identical code points always map to the same byte.
     *
     * @param string               $str UTF-8 string to encode in-place.
     * @param array<string, string> $map Shared encoding map (updated in-place).
     */
    private static function toAscii(string &$str, array &$map): void
    {
        if (!preg_match_all('/[\xC0-\xF7][\x80-\xBF]+/', $str, $matches)) {
            /** @infection-ignore-all ReturnRemoval: removing this early return lets the foreach iterate over empty $matches[0] — identical behaviour since no multibyte chars were found */
            return; // pure ASCII — nothing to remap
        }
        $count = count($map);
        foreach ($matches[0] as $mbc) {
            if (!isset($map[$mbc])) {
                $map[$mbc] = chr(128 + $count++); // @phpstan-ignore argument.type
            }
        }
        $str = strtr($str, $map);
    }
}

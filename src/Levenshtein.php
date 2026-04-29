<?php

namespace Fuzor;

/**
 * Unicode-aware Levenshtein edit distance.
 *
 * PHP's built-in levenshtein() operates on bytes. This class remaps multi-byte
 * UTF-8 code points to single bytes before delegating to the native C function,
 * so the edit distance is counted in characters rather than bytes.
 */
final class Levenshtein
{
    /**
     * Unicode-aware Levenshtein edit distance.
     *
     * PHP's built-in levenshtein() operates on bytes, giving wrong results for
     * multi-byte UTF-8 strings (e.g. "café" vs "cafe" would score 2 instead of 1).
     *
     * This implementation re-encodes both strings into a shared single-byte
     * representation (remapping non-ASCII code points to bytes 128–255) and
     * then delegates to the native C levenshtein(), keeping all heavy work in C.
     * Supports up to 128 distinct non-ASCII code points per string pair, which
     * is more than sufficient for short search terms.
     *
     * @param  string $a First string (already lowercased).
     * @param  string $b Second string (already lowercased).
     * @return int       Edit distance in Unicode characters.
     */
    public static function distance(string $a, string $b): int
    {
        $map = [];
        self::toAscii($a, $map);
        self::toAscii($b, $map);
        return levenshtein($a, $b);
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

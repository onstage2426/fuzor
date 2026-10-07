<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Typo tolerance settings, passed as Config::$typoTolerance.
 *
 * A query word without an exact or prefix match is matched against indexed terms within an edit
 * distance that depends on its length in codepoints: none below $minWordSizeForOneTypo, one
 * typo from there, two from $minWordSizeForTwoTypos. An insertion, deletion, substitution, or
 * swap of two adjacent characters each counts as one typo. Candidates must share the first
 * Config::$fuzzyPrefixLength characters with the query word.
 */
final readonly class TypoTolerance
{
    /**
     * @param bool         $enabled                false: words match exactly or by prefix only.
     * @param int          $minWordSizeForOneTypo  Shortest query word (codepoints) that may have one typo.
     * @param int          $minWordSizeForTwoTypos Shortest query word (codepoints) that may have two typos.
     * @param bool         $disableOnNumbers       true: words containing a digit (SKUs, model numbers,
     *                                             years) match exactly.
     * @param list<string> $disableOnWords         Query words that always match exactly (case-insensitive,
     *                                             stemmed like query words).
     * @throws \InvalidArgumentException Unless 0 ≤ $minWordSizeForOneTypo ≤ $minWordSizeForTwoTypos ≤ 255.
     */
    public function __construct(
        public bool $enabled = true,
        public int $minWordSizeForOneTypo = 5,
        public int $minWordSizeForTwoTypos = 9,
        public bool $disableOnNumbers = false,
        public array $disableOnWords = [],
    ) {
        if (
            $minWordSizeForOneTypo < 0
            || $minWordSizeForOneTypo > $minWordSizeForTwoTypos
            || $minWordSizeForTwoTypos > 255
        ) {
            throw new \InvalidArgumentException(
                "Typo word sizes must satisfy 0 <= minWordSizeForOneTypo <= minWordSizeForTwoTypos <= 255, "
                . "got {$minWordSizeForOneTypo} and {$minWordSizeForTwoTypos}."
            );
        }
    }
}

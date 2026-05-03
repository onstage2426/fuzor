<?php

declare(strict_types=1);

namespace Fuzor;

/** Per-instance search tuning — set once at construction, applies to every query on the index. */
final class Config
{
    public function __construct(
        /** @infection-ignore-all DecrementInteger,IncrementInteger: default value; exact number only affects result cap, not correctness */
        public readonly int $maxDocs = 500,
        /** BM25 term frequency saturation parameter. */
        public readonly float $k1 = 1.2,
        /** BM25 document length normalisation weight (0 = none, 1 = full). */
        public readonly float $b = 0.75,
        /** @infection-ignore-all DecrementInteger: default value; exact prefix length only affects candidate breadth, not correctness */
        public readonly int $fuzzyPrefixLength = 3,
        /** @infection-ignore-all DecrementInteger,IncrementInteger: default value; exact number only affects how many candidates are evaluated, not correctness */
        public readonly int $fuzzyMaxExpansions = 50,
        /** @infection-ignore-all IncrementInteger: default value; exact distance only affects match breadth, not correctness */
        public readonly int $fuzzyDistance = 2,
        /**
         * Proximity ranking weight applied when storePositions is enabled and the query has ≥2 terms.
         * Each document's BM25 score is multiplied by 1 / (1 + proximityBoost * minSpan), where
         * minSpan is the smallest token-position window containing one occurrence of every query term.
         * Set to 0.0 to disable proximity ranking entirely.
         *
         * @infection-ignore-all: default value; mutations only affect ranking magnitude, not correctness
         */
        public readonly float $proximityBoost = 1.0,
    ) {
    }
}

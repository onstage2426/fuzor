<?php

declare(strict_types=1);

namespace Fuzor;

/** Per-instance search tuning — set once at construction, applies to every query on the index. */
final readonly class Config
{
    public function __construct(
        /** @infection-ignore-all DecrementInteger,IncrementInteger: default value; exact number only affects result cap, not correctness */
        public int $maxDocs = 500,
        /** BM25 term frequency saturation parameter. */
        public float $k1 = 1.2,
        /** BM25 document length normalisation weight (0 = none, 1 = full). */
        public float $b = 0.75,
        /** @infection-ignore-all DecrementInteger: default value; exact prefix length only affects candidate breadth, not correctness */
        public int $fuzzyPrefixLength = 3,
        /** @infection-ignore-all DecrementInteger,IncrementInteger: default value; exact number only affects how many candidates are evaluated, not correctness */
        public int $fuzzyMaxExpansions = 50,
        /**
         * Minimum word length (in Unicode codepoints) before the Levenshtein fallback fires.
         * Words shorter than this threshold are matched by exact / prefix only.
         *
         * @infection-ignore-all: default value; mutations only affect the length gate, not correctness
         */
        public int $fuzzyMinWordLength = 5,
        /**
         * Proximity ranking weight applied to queries with ≥2 terms.
         * Each document's BM25 score is multiplied by 1 / (1 + proximityBoost * minSpan), where
         * minSpan is the smallest token-position window containing one occurrence of every query term.
         * Set to 0.0 to disable proximity ranking entirely.
         *
         * @infection-ignore-all: default value; mutations only affect ranking magnitude, not correctness
         */
        public float $proximityBoost = 1.0,
        /** Maximum docs fetched per FTS term when a facet filter is active. Higher values improve
         *  recall under selective filters at the cost of more BM25 scoring work. */
        public int $filterMaxDocs = 2_000,
        /** Maximum result-set doc IDs included in the facet count IN() clause.
         *  Counts are approximate when the result set exceeds this cap. */
        public int $maxFacetCountDocs = 10_000,
        /**
         * Maximum number of values returned per facet field in $facetDistribution.
         * Values are ordered by count descending; the tail is truncated.
         * 0 returns all values (use with care on high-cardinality fields).
         *
         * @infection-ignore-all: default value; mutations only affect result size, not correctness
         */
        public int $maxValuesPerFacet = 100,
        /**
         * Maximum number of candidates to apply proximity ranking to, chosen by highest BM25 first.
         * 0 (default) means apply proximity to all candidates.
         * A positive value restores the original windowed behaviour and caps the proximity
         * pass to that many docs — useful to bound CPU cost on very large result sets.
         *
         * @infection-ignore-all: default value; mutations only affect the window size, not correctness
         */
        public int $proxWindowSize = 0,
        /**
         * Per-field BM25 boost multipliers. Map field name → float multiplier.
         * Empty (default) uses the standard uniform BM25 path with zero overhead.
         * When set, term frequency is weighted as Σ boost(field) × field_hit_count before
         * the BM25 formula, so a title match outscores a body match when title has a higher boost.
         * Only effective on indexes built with field boost support (field_hits table present);
         * calling search() with boosts on an older index throws QueryException.
         *
         * @var array<string, float>
         * @infection-ignore-all: default value; mutations only affect field weight magnitude, not correctness
         */
        public array $fieldBoosts = [],
    ) {
    }
}

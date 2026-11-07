<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * How much of a large result set a search reads: Config::$limits.
 *
 * The defaults (fast()) keep a broad search on a large index fast: a word in many documents is
 * read through its best candidates, and facet counts over a very large match set are counted
 * over a part of it. Results then report what was cut (SearchResult::$exhaustive,
 * $approximateFacets, $warnings). exact() lifts both caps, so every result is computed over all
 * matching documents, at a cost that grows with the matches. $totalHits caps pagination in both.
 */
final readonly class Limits
{
    /** A cap no index reaches: what exact() sets. Leaves room for the multiples taken of it. */
    private const int NO_CAP = 1 << 60;

    public function __construct(
        /**
         * Documents read per query word before ranking: those in which it occurs most. A words
         * bucket (the documents with the same number of query words) of at most 4 × this many
         * documents is read and ranked exactly, and so is a first word in at most that many
         * documents; above that the ranking is approximate (SearchResult::$exhaustive false).
         * Higher = more exact pages on broad queries, lower = faster.
         *
         * @infection-ignore-all DecrementInteger,IncrementInteger: default value; only moves the cap
         */
        public int $candidatesPerWord = 500,
        /**
         * Most matching documents counted for facet values: for search() / searchBoolean(), a
         * filtered browse, and facetSearch() with a query. Counts are approximate when more
         * documents match (SearchResult::$approximateFacets). Counts with no filter and no query
         * are always exact. Counting costs about 0.6–1 µs per document, so the default bounds it
         * near 50 ms per facet group.
         *
         * @infection-ignore-all DecrementInteger,IncrementInteger: default value; mutations only move the cap
         */
        public int $facetCountDocs = 50_000,
        /**
         * Most documents a search can reach: offset and limit are clamped so that no hit ranked
         * past this number is returned, and SearchResult::$totalHits stops at it ($totalHitsCapped
         * is then true, so a UI can show "1000+"). Applies to search(), searchBoolean() and a
         * browse (empty query); facet counts are not affected. A search counts its matches up to
         * this number, so a larger value costs more on broad queries.
         *
         * @infection-ignore-all DecrementInteger,IncrementInteger: default value; mutations only move the cap
         */
        public int $totalHits = 1_000,
    ) {
        $values = [
            'candidatesPerWord' => $candidatesPerWord,
            'facetCountDocs'    => $facetCountDocs,
            'totalHits'         => $totalHits,
        ];
        foreach ($values as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("{$name} must be >= 1.");
            }
        }
    }

    /** The defaults: 500 candidates per word, facet counts over 50,000 documents, 1,000 hits. */
    public static function fast(): self
    {
        return new self();
    }

    /**
     * Every word read whole and every facet count over all matching documents: rankings, totals
     * and facet counts are exact (a prefix that matches more than Config::$fuzzyMaxExpansions
     * terms is still cut). Costs grow with the matching documents: fine for a catalogue of tens
     * of thousands of documents, slow for broad queries on millions.
     */
    public static function exact(int $totalHits = 1_000): self
    {
        return new self(self::NO_CAP, self::NO_CAP, $totalHits);
    }
}

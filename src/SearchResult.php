<?php

declare(strict_types=1);

namespace Fuzor;

class SearchResult
{
    /**
     * @param list<int>                              $ids          Document IDs in relevance order (paged window).
     * @param int                                    $hits         Total matching documents across all pages.
     * @param array<int, float>                      $scores       BM25 scores keyed by doc ID; empty for boolean.
     * @param array<int, array<string, mixed>>|null  $documents    Stored documents keyed by doc ID; null when disabled.
     * @param array<string, mixed> $facetCounts Facet value counts; empty when not requested.
     *        String facets: array<string, int> (value → count).
     *        Numeric facets: array{min: float, max: float, count: int}.
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $hits,
        private readonly array $scores = [],
        private readonly ?array $documents = null,
        private readonly array $facetCounts = [],
    ) {
    }

    /** True when BM25 scores are available (false for boolean search results). */
    public function hasScores(): bool
    {
        return $this->scores !== [];
    }

    /** BM25 score for the given doc ID, or null in boolean mode or if the ID is not in the result. */
    public function score(int $id): float|null
    {
        return $this->scores[$id] ?? null;
    }

    /**
     * All BM25 scores keyed by doc ID, or an empty array for boolean search results.
     *
     * @return array<int, float>
     */
    public function scores(): array
    {
        return $this->scores;
    }

    /** True when the document store is enabled on the index that produced this result. */
    public function hasDocuments(): bool
    {
        return $this->documents !== null;
    }

    /**
     * Stored document for the given doc ID, or null when the store is disabled or the ID is not in the result.
     *
     * @return array<string, mixed>|null
     */
    public function document(int $id): array|null
    {
        return $this->documents[$id] ?? null;
    }

    /**
     * All stored documents keyed by doc ID, or null when the store is disabled.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function documents(): array|null
    {
        return $this->documents;
    }

    /** True when facet counts were computed for this result. */
    public function hasFacets(): bool
    {
        return $this->facetCounts !== [];
    }

    /**
     * All facet counts keyed by facet key name.
     *
     * String facets: array<string, int> (value → count, ordered by count desc).
     * Numeric facets: array{min: float, max: float, count: int}.
     *
     * @return array<string, mixed>
     */
    public function facetCounts(): array
    {
        return $this->facetCounts;
    }

    /**
     * Count for a specific string facet value, or null if not present.
     *
     * Returns null for numeric facets (use facetCounts()['price']['min'] etc. instead).
     */
    public function facetCount(string $key, string $value): ?int
    {
        $counts = $this->facetCounts[$key] ?? null;
        if (!is_array($counts) || isset($counts['min'])) {
            return null;
        }
        $count = $counts[$value] ?? null;
        return is_numeric($count) ? (int) $count : null;
    }
}

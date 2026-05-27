<?php

declare(strict_types=1);

namespace Fuzor;

class SearchResult
{
    /** @var list<array<string, mixed>> Documents in relevance order; stubs with only 'id' when store is disabled. */
    public readonly array $hits;

    /** Number of documents in this page (≤ limit). */
    public readonly int $hitsCount;

    /** Total matching documents across all pages. */
    public readonly int|null $totalHits;

    /** Original query string. */
    public readonly string $query;

    /** Page limit. */
    public readonly int|null $limit;

    /** Page offset. */
    public readonly int|null $offset;

    /**
     * Facet value counts keyed by facet field name.
     * String facets: array<string, int> (value → count). Numeric facets: array{min: float, max: float, count: int}.
     *
     * @var array<string, mixed>
     */
    public readonly array $facetDistribution;

    /** @var array<int, float> BM25 scores keyed by doc ID; empty for boolean search. */
    private readonly array $scores;

    /** @var list<int> Document IDs in relevance order; used internally for score lookups. */
    private readonly array $ids;

    /**
     * @param list<int>                             $ids
     * @param array<int, float>                     $scores
     * @param array<int, array<string, mixed>>|null $documents
     * @param array<string, mixed>                  $facetCounts
     */
    public function __construct(
        array $ids,
        int|null $totalHits,
        array $scores = [],
        ?array $documents = null,
        array $facetCounts = [],
        string $query = '',
        int|null $limit = null,
        int|null $offset = null,
    ) {
        $this->ids               = $ids;
        $this->totalHits         = $totalHits;
        $this->scores            = $scores;
        $this->query             = $query;
        $this->limit             = $limit;
        $this->offset            = $offset;
        $this->facetDistribution = $facetCounts;

        $this->hits = $documents === null
            ? array_map(fn(int $id) => ['id' => $id], $ids)
            : array_map(fn(int $id) => $documents[$id] ?? ['id' => $id], $ids);

        $this->hitsCount = count($this->hits);
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getLimit(): int|null
    {
        return $this->limit;
    }

    public function getOffset(): int|null
    {
        return $this->offset;
    }

    /** Number of documents in this page. */
    public function getHitsCount(): int
    {
        return $this->hitsCount;
    }

    /** Total matching documents across all pages. */
    public function getTotalHits(): int|null
    {
        return $this->totalHits;
    }

    /** @return list<int> Document IDs in relevance order (current page). */
    public function getIds(): array
    {
        return $this->ids;
    }

    /** @return list<array<string, mixed>> */
    public function getHits(): array
    {
        return $this->hits;
    }

    /** @return array<string, mixed> Document at position $index (0-based); empty array when out of bounds. */
    public function getHit(int $index): array
    {
        return $this->hits[$index] ?? [];
    }

    /** @return array<string, mixed> */
    public function getFacetDistribution(): array
    {
        return $this->facetDistribution;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hits'              => $this->hits,
            'query'             => $this->query,
            'hitsCount'         => $this->hitsCount,
            'totalHits'         => $this->totalHits,
            'limit'             => $this->limit,
            'offset'            => $this->offset,
            'facetDistribution' => $this->facetDistribution,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }
}

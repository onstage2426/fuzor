<?php

declare(strict_types=1);

namespace Fuzor;

/** @implements \IteratorAggregate<int, array<string, mixed>> */
final class SearchResult implements \Countable, \IteratorAggregate
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
     * All facets (string and numeric) are value → count maps.
     *
     * @var array<string, array<array-key, int>>
     */
    public readonly array $facetDistribution;

    /**
     * Aggregate stats for numeric facet fields: min and max over the result set.
     * Only present for fields where every document has a numeric value.
     *
     * @var array<string, array{min: float, max: float}>
     */
    public readonly array $facetStats;

    /**
     * Human-readable notices about options that were ignored or had no effect, e.g. a sort,
     * filter, facets, or distinct field that is not a declared facet field. Meant for logs
     * and debugging; the wording is not a stable API.
     *
     * @var list<string>
     */
    public readonly array $warnings;

    /**
     * False when a cap may have cut matching documents out of the result: a keyword matched more
     * than Limits::$candidatesPerWord documents, or the last keyword's prefix matched more than
     * Config::$fuzzyMaxExpansions terms. $hits then come from the best candidates, so the page
     * may differ from a full ranking; $totalHits is still counted exactly (up to
     * Limits::$totalHits, see $totalHitsCapped), also with distinct and in searchBoolean(),
     * except over a capped prefix expansion. Always true for a browse (empty query). Facet counts are reported
     * separately in $approximateFacets.
     */
    public readonly bool $exhaustive;

    /**
     * True when more documents match than Limits::$totalHits: $totalHits is then that cap
     * (show it as "1000+"), and no hit past it can be paged to.
     */
    public readonly bool $totalHitsCapped;

    /**
     * Facet fields whose counts in $facetDistribution / $facetStats were computed over a capped
     * set of matching documents (Limits::$facetCountDocs) and are therefore approximate.
     *
     * @var list<string>
     */
    public readonly array $approximateFacets;

    /** @var list<int> Document IDs in relevance order; used internally for score lookups. */
    private readonly array $ids;

    /**
     * @param list<int>                                    $ids
     * @param int|null                                     $totalHits
     * @param array<int, array<string, mixed>>|null        $documents
     * @param array<string, array<array-key, int>>         $facetCounts
     * @param string                                       $query
     * @param int|null                                     $limit
     * @param int|null                                     $offset
     * @param array<string, array{min: float, max: float}> $facetStats
     * @param list<string>                                 $warnings
     * @param list<string>                                 $approximateFacets
     *
     * @internal Built by Index::search() / searchBoolean().
     */
    public function __construct(
        array $ids,
        int|null $totalHits,
        ?array $documents = null,
        array $facetCounts = [],
        string $query = '',
        int|null $limit = null,
        int|null $offset = null,
        array $facetStats = [],
        array $warnings = [],
        bool $exhaustive = true,
        array $approximateFacets = [],
        bool $totalHitsCapped = false,
    ) {
        $this->ids               = $ids;
        $this->totalHits         = $totalHits;
        $this->query             = $query;
        $this->limit             = $limit;
        $this->offset            = $offset;
        $this->facetDistribution = $facetCounts;
        $this->facetStats        = $facetStats;
        $this->warnings          = $warnings;
        $this->exhaustive        = $exhaustive;
        $this->approximateFacets = $approximateFacets;
        $this->totalHitsCapped   = $totalHitsCapped;

        $this->hits = $documents === null
            ? array_map(fn(int $id) => ['id' => $id], $ids)
            : array_map(fn(int $id) => $documents[$id] ?? ['id' => $id], $ids);

        $this->hitsCount = count($this->hits);
    }

    /** Enables count($result). */
    public function count(): int
    {
        return $this->hitsCount;
    }

    /** @return \ArrayIterator<int, array<string, mixed>> Enables foreach ($result as $hit). */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->hits);
    }

    /** @return list<int> Document IDs in relevance order (current page). */
    public function getIds(): array
    {
        return $this->ids;
    }

    /**
     * Document at position $index (0-based); $default when out of bounds.
     *
     * @param  array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function getHit(int $index, array $default = []): array
    {
        return $this->hits[$index] ?? $default;
    }

    /**
     * This result with $totalHits stopped at $maxTotalHits.
     *
     * @internal Index applies Limits::$totalHits with it.
     */
    public function withTotalHitsCap(int $maxTotalHits): self
    {
        if ($this->totalHits === null || $this->totalHits <= $maxTotalHits) {
            return $this;
        }
        return clone($this, [
            'totalHits'       => $maxTotalHits,
            'totalHitsCapped' => true,
            'warnings'        => [
                ...$this->warnings,
                "More than Limits::\$totalHits ({$maxTotalHits}) documents match; totalHits stops there.",
            ],
        ]);
    }

    /**
     * This result with '_rankingScoreDetails' added to each hit (SearchOptions::$showRankingScoreDetails).
     *
     * @param array<int, array<string, array<string, mixed>>> $details Doc ID → rule → details.
     *
     * @internal Built by Index.
     */
    public function withRankingScoreDetails(array $details): self
    {
        return clone($this, [
            'hits' => array_map(
                fn(array $hit, int $id): array => [...$hit, '_rankingScoreDetails' => $details[$id] ?? []],
                $this->hits,
                $this->ids,
            ),
        ]);
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
            'facetStats'        => $this->facetStats,
            'warnings'          => $this->warnings,
            'exhaustive'        => $this->exhaustive,
            'approximateFacets' => $this->approximateFacets,
            'totalHitsCapped'   => $this->totalHitsCapped,
        ];
    }

    public function toJSON(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }
}

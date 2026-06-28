<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Result returned by Index::facetSearch().
 *
 * Holds the matching facet values and their document counts, ordered by count descending.
 *
 * @implements \IteratorAggregate<int, array{value: string, count: int}>
 */
final class FacetSearchResult implements \Countable, \IteratorAggregate
{
    /**
     * Matching facet values with hit counts, ordered by count descending.
     *
     * @var list<array{value: string, count: int}>
     */
    public readonly array $facetHits;

    /** The facet value prefix that was searched; empty string when no prefix was given. */
    public readonly string $facetQuery;

    /**
     * @param list<array{value: string, count: int}> $facetHits
     * @param string $facetQuery
     */
    public function __construct(array $facetHits, string $facetQuery = '')
    {
        $this->facetHits  = $facetHits;
        $this->facetQuery = $facetQuery;
    }

    /** @return list<array{value: string, count: int}> */
    public function getFacetHits(): array
    {
        return $this->facetHits;
    }

    public function getFacetQuery(): string
    {
        return $this->facetQuery;
    }

    /** Enables count($result). */
    public function count(): int
    {
        return count($this->facetHits);
    }

    /** @return \ArrayIterator<int, array{value: string, count: int}> Enables foreach ($result as $hit). */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->facetHits);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'facetHits'  => $this->facetHits,
            'facetQuery' => $this->facetQuery,
        ];
    }

    public function toJSON(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }
}

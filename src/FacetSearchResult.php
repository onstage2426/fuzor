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
     * Human-readable notices about parts of the query that were ignored or had no effect,
     * e.g. a facetName or filter key that is not a declared facet field. Meant for logs and
     * debugging; the wording is not a stable API.
     *
     * @var list<string>
     */
    public readonly array $warnings;

    /**
     * False when the FTS query's candidate documents were capped (Config::$maxFacetCountDocs per
     * keyword, or Config::$fuzzyMaxExpansions prefix terms), so the counts are approximate.
     * Always true without a query.
     */
    public readonly bool $exhaustive;

    /**
     * @param list<array{value: string, count: int}> $facetHits
     * @param string       $facetQuery
     * @param list<string> $warnings
     */
    public function __construct(
        array $facetHits,
        string $facetQuery = '',
        array $warnings = [],
        bool $exhaustive = true,
    ) {
        $this->facetHits  = $facetHits;
        $this->facetQuery = $facetQuery;
        $this->warnings   = $warnings;
        $this->exhaustive = $exhaustive;
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

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function isExhaustive(): bool
    {
        return $this->exhaustive;
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
            'warnings'   => $this->warnings,
            'exhaustive' => $this->exhaustive,
        ];
    }

    public function toJSON(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }
}

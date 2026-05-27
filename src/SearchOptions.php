<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Per-query search options for Index::search() and Index::searchBoolean().
 *
 * Passed as the second argument to search() / searchBoolean(); all properties
 * have defaults so new SearchOptions() produces identical behaviour to calling
 * search() with no extra arguments.
 */
final readonly class SearchOptions
{
    /**
     * @param bool          $asYouType     Match the last keyword as a prefix (as-you-type / autocomplete mode).
     * @param int           $limit         Maximum number of hits to return.
     * @param int           $offset        Number of top-ranked results to skip (pagination).
     * @param array<string, string|list<string>|FacetRange> $filter Facet filters; keyed by facet field name.
     * @param list<string>  $facets        Facet field names to compute value counts for.
     * @param list<string>  $sort          Sort specs: 'field:asc' / 'field:desc'; left-to-right priority.
     * @param string|null   $distinct      Facet field to collapse duplicate values on (null = off).
     * @param int           $distinctCount Maximum hits per distinct value when $distinct is set.
     */
    public function __construct(
        public readonly bool $asYouType = true,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
        public readonly array $filter = [],
        public readonly array $facets = [],
        public readonly array $sort = [],
        public readonly ?string $distinct = null,
        public readonly int $distinctCount = 1,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Query parameters for Index::facetSearch().
 *
 * All properties except $facetName have defaults; construct with named arguments.
 */
final readonly class FacetSearchQuery
{
    /**
     * @param string $facetName  Facet field to search within; must be a declared facetField on the index.
     * @param string $facetQuery Prefix matched case-insensitively against facet values; empty = all values.
     * @param string $query      Optional FTS phrase to restrict the candidate document set; empty = all docs.
     *                           Keywords are AND-combined (all must match), consistent with searchBoolean() default.
     *                           Quoted phrases (e.g. '"science fiction"') are applied as contiguous-word constraints.
     *                           Candidates are capped at Config::$maxFacetCountDocs, so counts may be approximate
     *                           for queries that match a very large portion of the corpus.
     * @param array<string, string|list<string>|FacetRange> $filter Facet filters applied before counting; same type as SearchOptions::$filter.
     * @param int    $limit      Maximum number of facet values to return, ordered by count descending.
     */
    public function __construct(
        public readonly string $facetName,
        public readonly string $facetQuery = '',
        public readonly string $query = '',
        public readonly array $filter = [],
        public readonly int $limit = 100,
    ) {
    }
}

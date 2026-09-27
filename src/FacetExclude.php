<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Exclusion constraint for facet filtering.
 *
 * Wraps anything a positive filter value accepts — a value, a list of values, or a FacetRange —
 * and inverts it: a document passes when none of its values for the field matches. A document
 * that does not have the field at all passes, so "not excluded" holds for it.
 *
 * Pass as a filter value to Index::search(), Index::searchBoolean(), and FacetSearchQuery. It
 * combines with the other fields' filters by AND, and unlike a positive filter it also applies
 * when counting its own field in facetDistribution.
 */
final readonly class FacetExclude
{
    /**
     * @param string|list<string>|FacetRange $filter Values (or numeric range) whose documents are excluded.
     */
    public function __construct(
        public string|array|FacetRange $filter,
    ) {
    }
}

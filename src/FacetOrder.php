<?php

declare(strict_types=1);

namespace Fuzor;

/** Order of the values in a facet distribution and in facetSearch() results. */
enum FacetOrder: string
{
    /** Most documents first; ties by value. */
    case Count = 'count';

    /**
     * Ascending by value: numbers first, by value, then strings compared case-insensitively
     * (accents are not folded).
     */
    case Alpha = 'alpha';
}

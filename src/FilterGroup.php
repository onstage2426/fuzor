<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * The children of a compound filter condition (a Filter::any() group, or not() of several
 * conditions), each a list of conditions combined by AND, kept for the paths that evaluate the
 * group per document class instead of in SQL.
 *
 * @internal
 * @phpstan-import-type FacetCondition from Index
 */
final readonly class FilterGroup
{
    /**
     * @param non-empty-list<non-empty-list<FacetCondition>> $children
     */
    public function __construct(
        public array $children,
    ) {
    }
}

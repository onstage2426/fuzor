<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Numeric range constraint for facet filtering.
 *
 * Pass as a filter value to Index::search() / Index::searchBoolean() to match
 * documents whose numeric facet value falls within the given bounds.
 * At least one bound must be set. All bounds are inclusive by default (gte/lte);
 * use gt/lt for strict exclusion.
 */
final readonly class FacetRange
{
    public function __construct(
        public readonly ?float $gte = null,
        public readonly ?float $lte = null,
        public readonly ?float $gt = null,
        public readonly ?float $lt = null,
    ) {
    }

    /** Inclusive range: $gte ≤ value ≤ $lte. */
    public static function between(float $gte, float $lte): self
    {
        return new self(gte: $gte, lte: $lte);
    }

    /** Lower bound only (inclusive): value ≥ $gte. */
    public static function min(float $gte): self
    {
        return new self(gte: $gte);
    }

    /** Upper bound only (inclusive): value ≤ $lte. */
    public static function max(float $lte): self
    {
        return new self(lte: $lte);
    }

    /** Strict lower bound: value > $gt. */
    public static function gt(float $gt): self
    {
        return new self(gt: $gt);
    }

    /** Strict upper bound: value < $lt. */
    public static function lt(float $lt): self
    {
        return new self(lt: $lt);
    }
}

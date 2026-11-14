<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * One sort spec of SearchOptions::$sort: a sortable field and a direction.
 *
 *     new SearchOptions(sort: [Sort::asc('price')])
 *     new SearchOptions(sort: ['price:asc'])            // the same, as a string
 *
 * The field is checked against the index's sortableAttributes when the options are used
 * (QueryException); the spec itself is checked when it is made.
 */
final readonly class Sort implements \Stringable
{
    private function __construct(
        public string $field,
        public bool $ascending,
    ) {
    }

    /**
     * Lowest values first.
     *
     * @throws \InvalidArgumentException If $field is empty.
     */
    public static function asc(string $field): self
    {
        return self::create($field, true);
    }

    /**
     * Highest values first.
     *
     * @throws \InvalidArgumentException If $field is empty.
     */
    public static function desc(string $field): self
    {
        return self::create($field, false);
    }

    /**
     * A spec written as 'field:asc' or 'field:desc' (direction case-insensitive), as in a request
     * parameter.
     *
     * @throws \InvalidArgumentException If $spec has another form.
     */
    public static function fromString(string $spec): self
    {
        if (preg_match('/^(.+):(asc|desc)$/i', $spec, $m) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid sort spec '{$spec}': expected 'field:asc' or 'field:desc'."
            );
        }
        return new self($m[1], strtolower($m[2]) === 'asc');
    }

    /** The spec as 'field:asc' / 'field:desc'. */
    public function __toString(): string
    {
        return $this->field . ($this->ascending ? ':asc' : ':desc');
    }

    /** @throws \InvalidArgumentException */
    private static function create(string $field, bool $ascending): self
    {
        if ($field === '') {
            throw new \InvalidArgumentException('A sort field name cannot be empty.');
        }
        return new self($field, $ascending);
    }
}

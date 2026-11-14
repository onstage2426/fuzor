<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * A filter that combines conditions with AND, OR and NOT.
 *
 * Accepted wherever a filter map is (SearchOptions::$filter, FacetSearchQuery::$filter,
 * Index::deleteByFilter()). Each entry is either another Filter or a filter map with the meaning
 * the plain map has: its keys are combined with AND, a list of values on one key with OR, and a
 * value may be a FacetRange or a FacetExclude.
 *
 *     Filter::all(['tags' => 'sale'], ['tags' => 'new'])               // both tags
 *     Filter::any(['brand' => 'Casio'], ['category' => 'Watches'])     // either
 *     Filter::not(['brand' => 'Casio', 'colour' => 'Black'])           // not both
 *
 * A document without a field does not match a condition on it, so it passes a not() of one.
 * all() without entries matches every document and any() without entries matches none, as an
 * empty list of values does.
 *
 * Construction checks the shape only; the fields are checked against the index's
 * filterableAttributes when the filter is used (QueryException).
 */
final readonly class Filter
{
    /** Most levels of nesting, counting each all() / any() / not(). */
    public const int MAX_DEPTH = 32;

    public const string ALL = 'all';
    public const string ANY = 'any';
    public const string NOT = 'not';

    /**
     * @param self::ALL|self::ANY|self::NOT                              $operator
     * @param list<array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter> $entries
     */
    private function __construct(
        public string $operator,
        public array $entries,
        private int $depth,
    ) {
    }

    /**
     * Documents that match every entry.
     *
     * @param  array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter ...$entries
     * @throws \InvalidArgumentException If an entry is not a filter map or a Filter, or the nesting is too deep.
     */
    public static function all(array|Filter ...$entries): self
    {
        return self::create(self::ALL, $entries);
    }

    /**
     * Documents that match at least one entry.
     *
     * @param  array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter ...$entries
     * @throws \InvalidArgumentException If an entry is not a filter map or a Filter, or the nesting is too deep.
     */
    public static function any(array|Filter ...$entries): self
    {
        return self::create(self::ANY, $entries);
    }

    /**
     * Documents that do not match the entry.
     *
     * @param  array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter $entry
     * @throws \InvalidArgumentException If the entry is not a filter map or a Filter, or the nesting is too deep.
     */
    public static function not(array|Filter $entry): self
    {
        return self::create(self::NOT, [$entry]);
    }

    /**
     * Every field the filter names, in order of first appearance, also under not() and in
     * groups that can never match: Index checks them all against filterableAttributes.
     *
     * @internal
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->entries as $entry) {
            foreach ($entry instanceof self ? $entry->fields() : array_keys($entry) as $field) {
                $fields[(string) $field] = true;
            }
        }
        return array_keys($fields);
    }

    /**
     * The filter in a normal form with the same meaning: true (every document), false (none),
     * or an all() whose entries are leaves (one-field maps) and groups. A group is an any() of
     * two or more leaves, all() groups and not() groups, or a not() of an all() group; an all()
     * group has two or more entries.
     *
     * Rewrites, each exact: nested all() / any() flattened; a group of one is its entry; empty
     * all() is true and empty any() false, which then fold away; not() of a leaf flips its
     * FacetExclude, not(not(x)) is x, not() of any() is all() of each entry's not(); in any(),
     * positive value leaves on one field merge into one value list, and in all(), excluded
     * value lists on one field into one exclusion list.
     *
     * @internal
     */
    public function normalized(): self|bool
    {
        $node = self::simplify($this);
        return $node instanceof self && $node->operator === self::ALL
            ? $node
            : (is_bool($node) ? $node : new self(self::ALL, [$node], 0));
    }

    /**
     * @param  array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter $entry
     * @return array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter|bool
     */
    private static function simplify(array|self $entry): array|self|bool
    {
        if (is_array($entry)) {
            if (count($entry) <= 1) {
                return $entry === [] ? true : $entry;
            }
            return new self(self::ALL, array_map(fn($f, $v) => [(string) $f => $v], array_keys($entry), $entry), 0);
        }
        if ($entry->operator === self::NOT) {
            return self::negate(self::simplify($entry->entries[0]));
        }
        $isAll = $entry->operator === self::ALL;
        $parts = [];
        foreach ($entry->entries as $child) {
            $part = self::simplify($child);
            if (is_bool($part)) {
                // true is the identity of all() and absorbs any(); false the other way round.
                if ($part === $isAll) {
                    continue;
                }
                return $part;
            }
            if ($part instanceof self && $part->operator === $entry->operator) {
                array_push($parts, ...$part->entries);
            } else {
                $parts[] = $part;
            }
        }
        $parts = self::mergeLeaves($parts, exclude: $isAll);
        return match (count($parts)) {
            0       => $isAll,
            1       => $parts[0],
            default => new self($entry->operator, $parts, 0),
        };
    }

    /**
     * The simplified negation of a simplified entry.
     *
     * @param  array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter|bool $node
     * @return array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter|bool
     */
    private static function negate(array|self|bool $node): array|self|bool
    {
        if (is_bool($node)) {
            return !$node;
        }
        if (is_array($node)) {
            $field = (string) array_key_first($node);
            $value = $node[$field];
            return [$field => $value instanceof FacetExclude ? $value->filter : new FacetExclude($value)];
        }
        return match ($node->operator) {
            self::NOT => $node->entries[0],
            self::ANY => self::simplify(new self(
                self::ALL,
                array_map(fn($entry) => self::wrap(self::negate($entry)), $node->entries),
                0,
            )),
            default   => new self(self::NOT, [$node], 0),
        };
    }

    /**
     * A simplified node as an entry again (true and false as the empty groups they stand for).
     *
     * @param  array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter|bool $node
     * @return array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter
     */
    private static function wrap(array|self|bool $node): array|self
    {
        return is_bool($node) ? new self($node ? self::ALL : self::ANY, [], 0) : $node;
    }

    /**
     * In any(), positive value leaves on one field as one value list; in all() ($exclude),
     * excluded value lists on one field as one exclusion list. Ranges are never merged: on a
     * field with several values per document two ranges are not one.
     *
     * @param  list<array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter> $parts
     * @return list<array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter>
     */
    private static function mergeLeaves(array $parts, bool $exclude): array
    {
        /** @var array<string, int> $at  field → position of its merged leaf */
        $at = [];
        /** @var array<string, list<string>> $lists */
        $lists  = [];
        $merged = [];
        foreach ($parts as $part) {
            $values = null;
            $field  = '';
            if (is_array($part)) {
                $field = (string) array_key_first($part);
                $value = $part[$field];
                $inner = $value instanceof FacetExclude ? $value->filter : $value;
                if ($value instanceof FacetExclude === $exclude && !$inner instanceof FacetRange) {
                    $values = is_array($inner) ? $inner : [$inner];
                }
            }
            if ($values === null) {
                $merged[] = $part;
                continue;
            }
            if (!isset($at[$field])) {
                $at[$field]    = count($merged);
                $lists[$field] = $values;
                $merged[]      = $part;
                continue;
            }
            $union          = array_values(array_unique([...$lists[$field], ...$values]));
            $lists[$field]  = $union;
            $list           = count($union) === 1 ? $union[0] : $union;
            $merged[$at[$field]] = [$field => $exclude ? new FacetExclude($list) : $list];
        }
        return array_values($merged);
    }

    /**
     * @param  self::ALL|self::ANY|self::NOT $operator
     * @param  array<array-key, mixed>       $entries
     * @throws \InvalidArgumentException
     */
    private static function create(string $operator, array $entries): self
    {
        if (!array_is_list($entries)) {
            throw new \InvalidArgumentException(
                "Filter::{$operator}() takes its entries as positional arguments, not named ones."
            );
        }
        $depth = 1;
        foreach ($entries as $i => $entry) {
            if ($entry instanceof self) {
                $depth = max($depth, $entry->depth + 1);
                continue;
            }
            if (!is_array($entry)) {
                throw new \InvalidArgumentException(sprintf(
                    'Filter::%s() entry %d must be a filter map or a Filter, %s given.',
                    $operator,
                    $i,
                    get_debug_type($entry),
                ));
            }
            self::checkMap(sprintf('Filter::%s() entry %d', $operator, $i), $entry);
        }
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(sprintf('A Filter may nest at most %d levels.', self::MAX_DEPTH));
        }
        /** @var list<array<string, string|int|float|list<string|int|float>|FacetRange|FacetExclude>|Filter> $entries */
        return new self($operator, $entries, $depth);
    }

    /**
     * Check a filter where it is passed (SearchOptions, FacetSearchQuery, Index::deleteByFilter()),
     * so a malformed map fails where it is built. A Filter was checked when it was made.
     *
     * @param  array<array-key, mixed>|Filter $filter
     * @throws \InvalidArgumentException
     *
     * @internal
     */
    public static function check(array|Filter $filter, string $where): void
    {
        if (is_array($filter)) {
            self::checkMap($where, $filter);
        }
    }

    /**
     * @param  string                  $where Names the map in messages ('Filter::all() entry 0').
     * @param  array<array-key, mixed> $map
     * @throws \InvalidArgumentException
     */
    private static function checkMap(string $where, array $map): void
    {
        if ($map !== [] && array_is_list($map)) {
            throw new \InvalidArgumentException(sprintf(
                "%s must map field names to values (['field' => 'value']), not be a list.",
                $where,
            ));
        }
        $scalar = static fn(mixed $v): bool => is_string($v) || is_int($v) || is_float($v) && is_finite($v);
        foreach ($map as $field => $value) {
            $inner = $value instanceof FacetExclude ? $value->filter : $value;
            $valid = $scalar($inner) || $inner instanceof FacetRange
                || is_array($inner) && array_is_list($inner) && array_filter(
                    $inner,
                    fn(mixed $v): bool => !$scalar($v),
                ) === [];
            if (!$valid) {
                throw new \InvalidArgumentException(sprintf(
                    '%s: the value of "%s" must be a string, a finite number, a list of them, a FacetRange'
                    . ' or a FacetExclude, %s given.',
                    $where,
                    $field,
                    is_float($inner) ? 'a number that is not finite' : get_debug_type($value),
                ));
            }
        }
    }
}

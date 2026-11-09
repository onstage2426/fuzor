<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\FacetExclude;
use Fuzor\FacetSearchQuery;
use Fuzor\Filter;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Numbers as filter values select what their text selects: a field's values are stored as
 * text, a number as PHP writes it, so 5, 5.0 and '5' all match a stored 5 and a stored '5'.
 * Values no document can hold throw.
 */
class FilterValueTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_filter_value_' . uniqid() . '.db';
        $index      = new Index($this->path, schema: new SchemaConfig(
            filterableAttributes: ['stars', 'size', 'tag'],
            searchableAttributes: ['title'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'lamp', 'stars' => 5, 'size' => 10, 'tag' => 'a'],
            ['id' => 2, 'title' => 'lamp', 'stars' => 4.5, 'size' => '10', 'tag' => 'b'],
            ['id' => 3, 'title' => 'lamp', 'stars' => '5', 'size' => 12, 'tag' => 'a'],
            ['id' => 4, 'title' => 'lamp', 'stars' => [3, 4.5], 'size' => 0, 'tag' => 'c'],
            ['id' => 5, 'title' => 'lamp', 'stars' => 1, 'tag' => 'b'],
        ]);
        $index->close();
    }

    protected function tearDown(): void
    {
        Index::remove($this->path);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, list<int>}> */
    public static function numbers(): array
    {
        return [
            'an int'                   => [['stars' => 5], ['stars' => '5'], [1, 3]],
            'a float with no fraction' => [['stars' => 5.0], ['stars' => '5'], [1, 3]],
            'a float'                  => [['stars' => 4.5], ['stars' => '4.5'], [2, 4]],
            'a list of numbers'        => [['stars' => [5, 4.5]], ['stars' => ['5', '4.5']], [1, 2, 3, 4]],
            'numbers and strings'      => [['stars' => ['1', 3]], ['stars' => ['1', '3']], [4, 5]],
            'zero'                     => [['size' => 0], ['size' => '0'], [4]],
            'an int on string text'    => [['size' => 10], ['size' => '10'], [1, 2]],
            'an excluded number'       => [
                ['stars' => new FacetExclude(5)],
                ['stars' => new FacetExclude('5')],
                [2, 4, 5],
            ],
            'an excluded list'         => [
                ['stars' => new FacetExclude([4.5, 1])],
                ['stars' => new FacetExclude(['4.5', '1'])],
                [1, 3],
            ],
            'next to a string'         => [['stars' => 5, 'tag' => 'a'], ['stars' => '5', 'tag' => 'a'], [1, 3]],
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $text   The same filter with each value written as text.
     * @param list<int>            $expected
     */
    #[DataProvider('numbers')]
    public function testNumbersMatchLikeTheirText(array $filter, array $text, array $expected): void
    {
        $index = new Index($this->path, readonly: true);
        foreach (['', 'lamp'] as $q) {
            $forms = [
                [$filter, $text],
                [Filter::all(self::filter($filter)), Filter::all(self::filter($text))],
                [
                    Filter::any(self::filter($filter), ['tag' => 'none']),
                    Filter::any(self::filter($text), ['tag' => 'none']),
                ],
            ];
            foreach ($forms as [$f, $t]) {
                $result = $index->search($q, self::options($f));
                $this->assertEqualsCanonicalizing($expected, $result->getIds(), "search '{$q}'");
                $this->assertSame(
                    $index->search($q, self::options($t))->toArray(),
                    $result->toArray(),
                    "same as the text, search '{$q}'",
                );
                $this->assertSame(
                    $index->searchBoolean($q, self::options($t))->toArray(),
                    $index->searchBoolean($q, self::options($f))->toArray(),
                    "same as the text, searchBoolean '{$q}'",
                );
                $this->assertSame(
                    $index->facetSearch(new FacetSearchQuery('tag', q: $q, filter: self::filter($t)))->toArray(),
                    $index->facetSearch(new FacetSearchQuery('tag', q: $q, filter: self::filter($f)))->toArray(),
                    "facetSearch '{$q}'",
                );
            }
        }
        $index->close();
        $writable = new Index($this->path);
        $this->assertSame(count($expected), $writable->deleteByFilter(self::filter($filter)));
        $writable->close();
    }

    /** @return array<string, array{mixed}> */
    public static function impossible(): array
    {
        return [
            'true'          => [true],
            'false'         => [false],
            'null'          => [null],
            'NaN'           => [NAN],
            'infinity'      => [INF],
            'a bool in a list' => [['5', true]],
            'an object'     => [new \stdClass()],
            'a nested list' => [[['5']]],
            'excluded null' => [new FacetExclude(self::nulls())],
        ];
    }

    #[DataProvider('impossible')]
    public function testValuesNoDocumentCanHoldThrow(mixed $value): void
    {
        $index = new Index($this->path);
        $calls = [
            'browse'         => fn() => $index->search('', self::options(['stars' => $value])),
            'search'         => fn() => $index->search('lamp', self::options(['stars' => $value])),
            'searchBoolean'  => fn() => $index->searchBoolean('lamp', self::options(['stars' => $value])),
            'facetSearch'    => fn() => $index->facetSearch(
                new FacetSearchQuery('tag', filter: self::filter(['stars' => $value])),
            ),
            'deleteByFilter' => fn() => $index->deleteByFilter(self::filter(['stars' => $value])),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted " . get_debug_type($value));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('"stars"', $e->getMessage(), $name);
            }
        }
        $this->assertCount(5, $index);
        $index->close();
    }

    /**
     * A list holding null, typed as the constructor takes it (the values are checked at run time).
     *
     * @return list<string>
     */
    private static function nulls(): array
    {
        /** @var list<string> $nulls */
        $nulls = json_decode('[null]', true);
        return $nulls;
    }

    /** @param array<array-key, mixed>|Filter $filter */
    private static function options(array|Filter $filter): SearchOptions
    {
        return new SearchOptions(limit: 50, filter: self::filter($filter), facets: ['stars', 'size', 'tag']);
    }

    /**
     * The filter as the typed parameter takes it; the values are checked at run time.
     *
     * @param  array<array-key, mixed>|Filter $filter
     * @return array<string, string|int|float|list<string|int|float>|\Fuzor\FacetRange|FacetExclude>|Filter
     */
    private static function filter(array|Filter $filter): array|Filter
    {
        /** @var array<string, string|int|float|list<string|int|float>|\Fuzor\FacetRange|FacetExclude>|Filter $filter */
        return $filter;
    }
}

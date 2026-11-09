<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\Exceptions\QueryException;
use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\Filter;
use Fuzor\Index;
use Fuzor\Limits;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Filter::all() / any() / not(): construction, and filters that reduce to conditions combined by
 * AND, against the plain filter map and a scan of the documents.
 *
 * @phpstan-type Doc array{id: int, title: string, color: list<string>, size?: string, stock: string, price: float}
 * @phpstan-type FilterMap array<string, list<string>|string|FacetRange|FacetExclude>
 */
class FilterTest extends TestCase
{
    private const int DOCS = 240;
    private const array COLORS = ['red', 'green', 'blue', 'black', 'white'];
    private const array SIZES = ['s', 'm', 'l'];
    private const array STOCK = ['in', 'out', 'back'];
    private const array FACETS = ['color', 'size', 'stock', 'price'];

    private string $path;

    /** @var array<int, Doc> */
    private array $docs = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_filter_' . uniqid() . '.db';
        $random     = new Randomizer(new Mt19937(11));
        $words      = ['shirt', 'shoe', 'sock', 'scarf'];
        for ($id = 1; $id <= self::DOCS; $id++) {
            $doc = [
                'id'    => $id,
                'title' => 'product ' . $words[$random->getInt(0, 3)],
                'color' => array_values(array_unique([
                    self::COLORS[$random->getInt(0, 4)],
                    self::COLORS[$random->getInt(0, 4)],
                ])),
                'stock' => self::STOCK[$random->getInt(0, 2)],
                'price' => $random->getInt(1, 40) * 2.5,
            ];
            if ($random->getInt(1, 10) <= 7) {
                $doc['size'] = self::SIZES[$random->getInt(0, 2)];
            }
            $this->docs[$id] = $doc;
        }
        $index = new Index($this->path, schema: new SchemaConfig(
            filterableAttributes: [...self::FACETS, 'unused'],
            sortableAttributes: ['price'],
            searchableAttributes: ['title'],
        ));
        $index->insert(array_values($this->docs));
        foreach (range(3, self::DOCS, 41) as $id) {
            $index->delete($id);
            unset($this->docs[$id]);
        }
        $index->close();
    }

    protected function tearDown(): void
    {
        Index::remove($this->path);
    }

    /** @return array<string, array{string, array<array-key, mixed>}> */
    public static function malformed(): array
    {
        return [
            'a list instead of a map'  => ['all', [['color', 'red']]],
            'a number as value'        => ['any', [['price' => 5]]],
            'a number in a list'       => ['all', [['color' => ['red', 5]]]],
            'a nested map as value'    => ['all', [['color' => ['a' => 'red']]]],
            'null as value'            => ['not', [['color' => null]]],
            'an object as value'       => ['all', [['color' => new \stdClass()]]],
            'named entries'            => ['all', ['first' => ['color' => 'red']]],
        ];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformed')]
    public function testMalformedEntriesThrow(string $method, array $arguments): void
    {
        $build = [Filter::class, $method];
        $this->assertTrue(is_callable($build));
        $this->expectException(\InvalidArgumentException::class);
        call_user_func_array($build, $arguments);
    }

    public function testANumberInAnExclusionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @var list<string> $numbers */
        $numbers = json_decode('[1]', true);
        Filter::all(['price' => new FacetExclude($numbers)]);
    }

    public function testNestingIsLimited(): void
    {
        $filter = Filter::all(['color' => 'red']);
        for ($level = 2; $level <= Filter::MAX_DEPTH; $level++) {
            $filter = $level % 2 === 0 ? Filter::any($filter) : Filter::all($filter);
        }
        $this->expectException(\InvalidArgumentException::class);
        Filter::not($filter);
    }

    /** @return array<string, array{int}> */
    public static function caps(): array
    {
        return [
            'uncapped'            => [500],
            'words and facets cut' => [8],
        ];
    }

    /**
     * A filter map and all() of it select the same documents through the same paths: every
     * search, browse and facet search gives the same result.
     */
    #[DataProvider('caps')]
    public function testAllOfAMapEqualsTheMap(int $cap): void
    {
        $index  = $this->open($cap);
        $random = new Randomizer(new Mt19937($cap));
        for ($i = 0; $i < 60; $i++) {
            $map = $this->randomMap($random);
            $perKey = array_map(fn(string $key): array => [$key => $map[$key]], array_keys($map));
            foreach ([Filter::all($map), Filter::all(...$perKey)] as $tree) {
                $label = json_encode($map, JSON_THROW_ON_ERROR);
                foreach (['', 'product', 'shirt', 'sh'] as $q) {
                    $options = fn(int $side): SearchOptions => self::options(
                        $side === 0 ? $map : $tree,
                        limit: 30,
                        offset: $i % 4 === 3 ? 10 : 0,
                        sort: $i % 2 === 0 ? ['price:asc'] : [],
                        distinct: $i % 5 === 4 ? 'stock' : null,
                    );
                    $this->assertSame(
                        $index->search($q, $options(0))->toArray(),
                        $index->search($q, $options(1))->toArray(),
                        "search '{$q}' {$label}",
                    );
                    $this->assertSame(
                        $index->searchBoolean($q, $options(0))->toArray(),
                        $index->searchBoolean($q, $options(1))->toArray(),
                        "searchBoolean '{$q}' {$label}",
                    );
                    $this->assertSame(
                        $index->facetSearch(new FacetSearchQuery(facetName: 'color', q: $q, filter: $map))->toArray(),
                        $index->facetSearch(new FacetSearchQuery(facetName: 'color', q: $q, filter: $tree))->toArray(),
                        "facetSearch '{$q}' {$label}",
                    );
                }
            }
        }
        $index->close();
    }

    /** @return array<string, array{Filter, FilterMap|Filter}> */
    public static function equivalents(): array
    {
        return [
            'any() of one key is a value list' => [
                Filter::any(['color' => 'red'], ['color' => ['blue', 'red']]),
                ['color' => ['red', 'blue']],
            ],
            'any() of one key next to another key' => [
                Filter::all(Filter::any(['size' => 's'], ['size' => 'l']), ['stock' => 'in']),
                ['size' => ['s', 'l'], 'stock' => 'in'],
            ],
            'not() of a value is an exclusion' => [
                Filter::not(['color' => 'red']),
                ['color' => new FacetExclude('red')],
            ],
            'not() of an exclusion is the value' => [
                Filter::not(['size' => new FacetExclude(['s', 'm'])]),
                ['size' => ['s', 'm']],
            ],
            'not(not())' => [
                Filter::not(Filter::not(['stock' => 'out', 'price' => FacetRange::max(40.0)])),
                ['stock' => 'out', 'price' => FacetRange::max(40.0)],
            ],
            'not() of any() excludes each' => [
                Filter::not(Filter::any(['color' => 'red'], ['size' => 's'], ['price' => FacetRange::min(80.0)])),
                [
                    'color' => new FacetExclude('red'),
                    'size'  => new FacetExclude('s'),
                    'price' => new FacetExclude(FacetRange::min(80.0)),
                ],
            ],
            'exclusions on one key are one list' => [
                Filter::all(['color' => new FacetExclude('red')], Filter::not(['color' => ['blue', 'red']])),
                ['color' => new FacetExclude(['red', 'blue'])],
            ],
            'all() inside all()' => [
                Filter::all(Filter::all(['stock' => 'in'], Filter::all(['size' => 'm'])), ['color' => 'red']),
                ['stock' => 'in', 'size' => 'm', 'color' => 'red'],
            ],
            'all() as an entry matches everything' => [
                Filter::all(Filter::all(), [], ['stock' => 'back']),
                ['stock' => 'back'],
            ],
            'a field without values in any()' => [
                Filter::any(['unused' => 'x'], ['stock' => 'in']),
                ['stock' => 'in'],
            ],
            'a field without values in an all() in any()' => [
                Filter::all(Filter::any(['unused' => 'x', 'size' => 's'], ['size' => 'l']), ['stock' => 'out']),
                ['size' => 'l', 'stock' => 'out'],
            ],
            'an exclusion without values in not()' => [
                Filter::not(['unused' => new FacetExclude('x'), 'stock' => 'in']),
                ['stock' => new FacetExclude('in')],
            ],
            'an exclusion without values in not() of a group' => [
                Filter::not(['unused' => new FacetExclude('x'), 'color' => 'red', 'size' => 'm']),
                Filter::not(['color' => 'red', 'size' => 'm']),
            ],
            'any() with all() matches everything' => [
                Filter::all(Filter::any(Filter::all(), ['color' => 'red']), ['size' => 'l']),
                ['size' => 'l'],
            ],
        ];
    }

    /**
     * Different ways of writing the same filter give the same result, also the facet counts:
     * which keys count without their own filter is decided after the filter is simplified.
     *
     * @param FilterMap|Filter $map
     */
    #[DataProvider('equivalents')]
    public function testEquivalentFiltersGiveTheSameResult(Filter $tree, array|Filter $map): void
    {
        foreach ([500, 8] as $cap) {
            $index = $this->open($cap);
            foreach (['', 'product', 'sh'] as $q) {
                foreach ([[], ['price:desc']] as $sort) {
                    $options = fn(int $side): SearchOptions => self::options(
                        $side === 0 ? $map : $tree,
                        limit: 50,
                        sort: $sort,
                    );
                    $this->assertSame(
                        $index->search($q, $options(0))->toArray(),
                        $index->search($q, $options(1))->toArray(),
                        "search '{$q}' cap {$cap}",
                    );
                    $this->assertSame(
                        $index->searchBoolean($q, $options(0))->toArray(),
                        $index->searchBoolean($q, $options(1))->toArray(),
                        "searchBoolean '{$q}' cap {$cap}",
                    );
                }
                $this->assertSame(
                    $index->facetSearch(new FacetSearchQuery(facetName: 'size', q: $q, filter: $map))->toArray(),
                    $index->facetSearch(new FacetSearchQuery(facetName: 'size', q: $q, filter: $tree))->toArray(),
                    "facetSearch '{$q}' cap {$cap}",
                );
            }
            $index->close();
        }
    }

    /**
     * Two values of one key combined by AND: documents holding both, counted over that result
     * (not without the key's filters, which would show values that cannot be added).
     */
    #[DataProvider('caps')]
    public function testAndWithinOneKey(int $cap): void
    {
        $index  = $this->open($cap);
        foreach ([['red', 'blue'], ['black', 'white'], ['green', 'red']] as [$a, $b]) {
            foreach (
                [
                    Filter::all(['color' => $a], ['color' => $b]),
                    Filter::all(['color' => $a, 'stock' => 'in'], ['color' => $b]),
                    Filter::all(['color' => $a], ['color' => $b], ['color' => new FacetExclude('green')]),
                ] as $n => $filter
            ) {
                $matches = array_filter($this->docs, fn(array $doc): bool => in_array($a, $doc['color'], true)
                    && in_array($b, $doc['color'], true)
                    && ($n !== 1 || $doc['stock'] === 'in')
                    && ($n !== 2 || !in_array('green', $doc['color'], true)));
                $withoutStock = array_filter($this->docs, fn(array $doc): bool => in_array($a, $doc['color'], true)
                    && in_array($b, $doc['color'], true));
                foreach (['', 'product'] as $q) {
                    $result = $index->search($q, new SearchOptions(limit: 200, filter: $filter, facets: self::FACETS));
                    $label  = "{$a} and {$b} ({$n}) '{$q}'";
                    $this->assertSame(count($matches), $result->totalHits, $label);
                    $this->assertEqualsCanonicalizing(array_keys($matches), $result->getIds(), $label);
                    $this->assertEqualsCanonicalizing(
                        self::countValues($matches, 'color'),
                        $result->facetDistribution['color'] ?? [],
                        "color of {$label}",
                    );
                    $this->assertEqualsCanonicalizing(
                        self::countValues($n === 1 ? $withoutStock : $matches, 'stock'),
                        $result->facetDistribution['stock'] ?? [],
                        "stock of {$label}",
                    );
                }
            }
        }
        $index->close();
    }

    public function testEmptyGroups(): void
    {
        $index = $this->open(500);
        $all   = count($this->docs);
        $this->assertSame($all, $index->search('', new SearchOptions(filter: Filter::all()))->totalHits);
        $this->assertSame(
            $all,
            $index->search('', new SearchOptions(filter: Filter::all([], Filter::all())))->totalHits,
        );
        $this->assertSame(0, $index->search('', new SearchOptions(filter: Filter::any()))->totalHits);
        $this->assertSame(0, $index->search('product', new SearchOptions(filter: Filter::any()))->totalHits);
        $this->assertSame(0, $index->searchBoolean('product', new SearchOptions(filter: Filter::any()))->totalHits);
        $this->assertSame(
            0,
            $index->search('', new SearchOptions(filter: Filter::all(['stock' => 'in'], Filter::any())))->totalHits,
        );
        $this->assertSame($all, $index->search('', new SearchOptions(filter: Filter::not(Filter::any())))->totalHits);
        $this->assertSame(0, $index->search('', new SearchOptions(filter: Filter::not(Filter::all())))->totalHits);
        $this->assertSame(
            [],
            $index->facetSearch(new FacetSearchQuery(facetName: 'color', filter: Filter::any()))->facetHits,
        );
        $index->close();
    }

    public function testDeleteByFilter(): void
    {
        $index   = new Index($this->path);
        $deleted = $index->deleteByFilter(Filter::all(['color' => 'red'], ['color' => 'blue']));
        $gone    = array_filter(
            $this->docs,
            fn(array $doc): bool => in_array('red', $doc['color'], true) && in_array('blue', $doc['color'], true),
        );
        $this->assertSame(count($gone), $deleted);
        $this->assertSame(count($this->docs) - count($gone), count($index));
        foreach (array_keys($gone) as $id) {
            $this->assertFalse($index->has($id));
        }
        $this->assertSame(0, $index->deleteByFilter(Filter::any()));
        $index->close();
    }

    /** @return array<string, array{Filter}> */
    public static function noConstraint(): array
    {
        return [
            'all()'             => [Filter::all()],
            'all() of empty'    => [Filter::all([], Filter::all())],
            'not() of nothing'  => [Filter::not(Filter::any())],
        ];
    }

    #[DataProvider('noConstraint')]
    public function testDeleteByFilterRejectsAFilterWithoutConstraint(Filter $filter): void
    {
        $index = new Index($this->path);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $index->deleteByFilter($filter);
        } finally {
            $this->assertSame(count($this->docs), count($index));
            $index->close();
        }
    }

    /** @return array<string, array{\Closure(Index, Filter): mixed}> */
    public static function entryPoints(): array
    {
        return [
            'search'         => [fn(Index $i, Filter $f): mixed => $i->search('product', self::options($f, 20))],
            'browse'         => [fn(Index $i, Filter $f): mixed => $i->search('', self::options($f, 20))],
            'searchBoolean'  => [fn(Index $i, Filter $f): mixed => $i->searchBoolean('product', self::options($f, 20))],
            'facetSearch'    => [fn(Index $i, Filter $f): mixed => $i->facetSearch(
                new FacetSearchQuery(facetName: 'color', filter: $f),
            )],
            'deleteByFilter' => [fn(Index $i, Filter $f): mixed => $i->deleteByFilter($f)],
        ];
    }

    /**
     * A field outside filterableAttributes throws wherever it sits in the tree, also under not()
     * and in a group that can never match.
     *
     * @param \Closure(Index, Filter): mixed $use
     */
    #[DataProvider('entryPoints')]
    public function testUndeclaredFieldsThrowAnywhereInTheTree(\Closure $use): void
    {
        $index = new Index($this->path);
        foreach (
            [
                Filter::all(['stock' => 'in'], ['title' => 'product']),
                Filter::not(['nope' => 'x']),
                Filter::all(
                    Filter::any(),
                    Filter::not(Filter::all(['stock' => 'in'], ['nope' => new FacetExclude('x')])),
                ),
            ] as $filter
        ) {
            try {
                $use($index, $filter);
                $this->fail('no QueryException for ' . json_encode($filter, JSON_THROW_ON_ERROR));
            } catch (QueryException $e) {
                $this->assertStringContainsString('Filter', $e->getMessage());
            }
        }
        $this->assertSame(count($this->docs), count($index));
        $index->close();
    }

    /**
     * @param FilterMap|Filter $filter
     * @param list<string>     $sort
     */
    private static function options(
        array|Filter $filter,
        int $limit,
        int $offset = 0,
        array $sort = [],
        ?string $distinct = null,
    ): SearchOptions {
        return new SearchOptions(
            limit: $limit,
            offset: $offset,
            filter: $filter,
            facets: self::FACETS,
            sort: $sort,
            distinct: $distinct,
        );
    }

    private function open(int $cap): Index
    {
        return new Index($this->path, config: new Config(limits: new Limits(
            candidatesPerWord: $cap,
            facetCountDocs: $cap * 10,
        )), readonly: true);
    }

    /** @return FilterMap */
    private function randomMap(Randomizer $random): array
    {
        $map    = [];
        $values = [
            'color' => match ($random->getInt(0, 4)) {
                0       => self::COLORS[$random->getInt(0, 4)],
                1       => self::some($random),
                2       => new FacetExclude(self::some($random)),
                default => null,
            },
            'size' => match ($random->getInt(0, 3)) {
                0       => self::SIZES[$random->getInt(0, 2)],
                1       => new FacetExclude(self::SIZES[$random->getInt(0, 2)]),
                default => null,
            },
            'stock' => match ($random->getInt(0, 3)) {
                0       => ['in', 'back'],
                1       => self::STOCK[$random->getInt(0, 2)],
                default => null,
            },
            'price' => match ($random->getInt(0, 3)) {
                0       => new FacetRange(gte: 10.0, lte: 80.0),
                1       => new FacetExclude(new FacetRange(gte: 50.0)),
                default => null,
            },
            'unused' => $random->getInt(0, 9) === 0 ? new FacetExclude('x') : null,
        ];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $map[$key] = $value;
            }
        }
        return $map;
    }

    /** @return list<string> */
    private static function some(Randomizer $random): array
    {
        /** @var list<string> $colors */
        $colors = $random->shuffleArray(self::COLORS);
        return array_slice($colors, 0, $random->getInt(1, 3));
    }

    /**
     * @param  array<int, Doc> $docs
     * @return array<array-key, int>
     */
    private static function countValues(array $docs, string $key): array
    {
        $counts = [];
        foreach ($docs as $doc) {
            foreach ((array) ($doc[$key] ?? []) as $value) {
                $text          = (string) $value;
                $counts[$text] = ($counts[$text] ?? 0) + 1;
            }
        }
        return $counts;
    }
}

<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\Exceptions\QueryException;
use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\Index;
use Fuzor\Limits;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * SearchOptions::$facetStatsOnly against the facet it stands for: the same facetStats over the
 * same matches as listing the field in $facets, and an otherwise identical result. Where the
 * facet is approximate (counted over Limits::$facetCountDocs matches), stats-only stats that are
 * not flagged approximate must be the exact ones.
 *
 * @phpstan-import-type ShopSearch from ShopCatalog
 */
class FacetStatsOnlyTest extends TestCase
{
    private const int SHOP_DOCS = 700;
    private const array SHOP_NUMBERS = ['price', 'unit_price', 'date'];

    private static string $shopPath;

    private string $path = '';

    public static function setUpBeforeClass(): void
    {
        self::$shopPath = sys_get_temp_dir() . '/fuzor_stats_shop_' . uniqid() . '.db';
        $index = new Index(self::$shopPath, force: true, schema: ShopCatalog::schema());
        $index->insert(ShopCatalog::documents(self::SHOP_DOCS));
        $index->close();
    }

    public static function tearDownAfterClass(): void
    {
        Index::remove(self::$shopPath);
    }

    protected function tearDown(): void
    {
        if ($this->path !== '') {
            Index::remove($this->path);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function shopSearches(): array
    {
        $cases = [];
        foreach (array_keys(ShopCatalog::searches()) as $name) {
            foreach (['shop', 'tight', 'few-facet-docs'] as $limits) {
                $cases["{$name} {$limits}"] = [$name, $limits];
            }
        }
        return $cases;
    }

    /**
     * Every shop page, with its own limits, with tight ones (candidates cut, facets counted over
     * a capped set) and with candidates above the facet cap, for each numeric field and for
     * search() and searchBoolean().
     */
    #[DataProvider('shopSearches')]
    public function testShopPagesGiveTheFacetsStats(string $name, string $limits): void
    {
        $search = ShopCatalog::searches()[$name];
        $config = match ($limits) {
            'shop'  => $search['config'],
            'tight' => self::withConfig($search['config'], [
                'limits' => new Limits(candidatesPerWord: 15, facetCountDocs: 120, totalHits: 10100),
            ]),
            // Candidates above the facet cap: the facet counts a slice of them.
            default => self::withConfig($search['config'], [
                'limits' => new Limits(candidatesPerWord: 5000, facetCountDocs: 40, totalHits: 10100),
            ]),
        };
        $exact  = self::withConfig($config, ['limits' => Limits::exact(10100)]);
        foreach (['search', 'searchBoolean'] as $method) {
            foreach (self::SHOP_NUMBERS as $field) {
                $this->assertStatsOnlyMatchesFacet(self::$shopPath, $config, $exact, $method, $search, $field);
            }
        }
        $this->assertStatsOnlyMatchesFacet(self::$shopPath, $config, $exact, 'search', $search, ...self::SHOP_NUMBERS);
    }

    /**
     * A key whose matching values sit in the middle of its range, so a walk from either end runs
     * out of budget and the field is counted as a facet; a key with string values among the
     * numbers; a multi-valued numeric key; pending deletes; negations and phrases.
     */
    public function testWalksThatRunOutMixedKeysAndMultipleValues(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_stats_' . uniqid() . '.db';
        $random     = new Randomizer(new Mt19937(11));
        $docs       = [];
        for ($id = 1; $id <= 3000; $id++) {
            $narrow = $id % 10 === 0;
            $doc    = [
                'id'     => $id,
                'title'  => ($narrow ? 'narrow ' : '') . ['red', 'green', 'blue'][$id % 3] . ' item '
                    . ['alpha', 'beta', 'gamma', 'delta'][$random->getInt(0, 3)],
                'group'  => $narrow ? 'narrow' : 'wide',
                'color'  => ['red', 'green', 'blue'][$id % 3],
                // The narrow group holds values in the middle of the range only.
                'score'  => $narrow ? $random->getInt(4000, 4100) : $random->getInt(0, 10000),
                'sizes'  => [$random->getInt(1, 50), $random->getInt(1, 50)],
                'weight' => $random->getInt(1, 100),
            ];
            if ($random->getInt(1, 20) === 1) {
                $doc['mixed'] = 'n/a';
            } elseif ($random->getInt(1, 3) > 1) {
                $doc['mixed'] = $random->getInt(1, 500) / 4;
            }
            $docs[] = $doc;
        }
        $index = new Index($this->path, force: true, schema: new SchemaConfig(
            language: 'en',
            filterableAttributes: ['group', 'color', 'score', 'sizes', 'weight', 'mixed'],
            searchableAttributes: ['title'],
        ));
        $index->insert($docs);
        $index->delete(...range(5, 60, 5));
        $index->close();

        $configs = [
            new Config(limits: new Limits(candidatesPerWord: 40, facetCountDocs: 5000)),
            new Config(limits: new Limits(candidatesPerWord: 5000, facetCountDocs: 5000)),
        ];
        $exact   = new Config(limits: Limits::exact());
        $cases  = [
            ['', ['group' => 'narrow']],
            ['', ['group' => 'narrow', 'color' => 'red']],
            ['', ['group' => 'narrow', 'score' => new FacetRange(gte: 4050.0)]],
            ['', ['group' => new FacetExclude('wide')]],
            ['', ['color' => new FacetExclude(['red', 'green'])]],
            ['', ['weight' => new FacetRange(lte: 3.0)]],
            // The field's own filter, dropping only its lowest value: a walk that kept it would stop at 2.
            ['', ['weight' => new FacetRange(gte: 2.0)]],
            ['', ['weight' => new FacetRange(gte: 2.0), 'color' => 'red']],
            ['item', ['weight' => new FacetRange(gte: 2.0)]],
            ['item', ['weight' => new FacetRange(gte: 2.0), 'color' => new FacetExclude('blue')]],
            ['', []],
            ['-narrow', []],
            ['narrow', []],
            ['narrow red', []],
            ['narrow', ['color' => 'blue']],
            ['narrow', ['score' => new FacetRange(gte: 4050.0)]],
            ['item', ['group' => 'narrow']],
            ['item -alpha', ['group' => new FacetExclude('wide')]],
            ['"red item"', []],
            ['red item', ['weight' => new FacetRange(gte: 30.0, lte: 31.0)]],
            ['i', []],
            ['zzzz', []],
        ];
        foreach ($configs as $config) {
            foreach ($cases as [$query, $filter]) {
                $search = ['query' => $query, 'config' => $config, 'options' => new SearchOptions(
                    limit: 5,
                    filter: $filter,
                    facets: ['color'],
                )];
                foreach (['search', 'searchBoolean'] as $method) {
                    foreach (['score', 'sizes', 'weight', 'mixed', 'color'] as $field) {
                        $this->assertStatsOnlyMatchesFacet($this->path, $config, $exact, $method, $search, $field);
                    }
                }
            }
        }
    }

    /**
     * facet_keys.has_strings is set by every path that writes a string value (one document,
     * several, update, upsert), stays set when the strings are deleted or replaced by numbers,
     * and is reset by clear() and recomputed by rebuild().
     */
    public function testEveryWritePathFlagsAKeyWithStrings(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_stats_flag_' . uniqid() . '.db';
        $index      = new Index($this->path, force: true, schema: new SchemaConfig(
            filterableAttributes: ['one', 'many', 'updated', 'upserted', 'numbers'],
        ));
        $index->insert([self::flagDoc(1, ['one' => 'n/a', 'numbers' => 1, 'updated' => 2, 'upserted' => 3])]);
        $index->insert([
            self::flagDoc(2, ['many' => 4, 'numbers' => 5]),
            self::flagDoc(3, ['many' => ['x', 6], 'numbers' => 7.5]),
        ]);
        $this->assertSame(
            ['many' => 1, 'numbers' => 0, 'one' => 1, 'updated' => 0, 'upserted' => 0],
            $this->stringFlags($this->path),
        );

        $index->update([self::flagDoc(1, ['one' => 1, 'updated' => 'n/a', 'upserted' => 3])]);
        $index->upsert([self::flagDoc(2, ['many' => 4, 'upserted' => 'n/a']), self::flagDoc(4, ['numbers' => 8])]);
        $flagged = ['many' => 1, 'numbers' => 0, 'one' => 1, 'updated' => 1, 'upserted' => 1];
        $this->assertSame($flagged, $this->stringFlags($this->path));

        $index->delete(1, 2, 3);
        $this->assertSame($flagged, $this->stringFlags($this->path));

        $index->insert([self::flagDoc(5, ['one' => 'n/a', 'numbers' => 9])]);
        Index::rebuild($this->path);
        $this->assertSame(['numbers' => 0, 'one' => 1], $this->stringFlags($this->path));

        $index = new Index($this->path);
        $index->clear();
        $this->assertSame(['numbers' => 0, 'one' => 0], $this->stringFlags($this->path));
        $index->insert([self::flagDoc(6, ['numbers' => 'n/a']), self::flagDoc(7, ['one' => 1])]);
        $this->assertSame(['numbers' => 1, 'one' => 0], $this->stringFlags($this->path));
        $index->close();
    }

    /**
     * A key whose strings were all deleted keeps its flag, so its stats come from the facet
     * count instead of the walk: still the facet's stats. A reader opened before another
     * connection writes the first string sees the flag at its next search.
     */
    public function testAStaleOrNewStringFlagKeepsTheFacetsStats(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_stats_stale_' . uniqid() . '.db';
        $index      = new Index($this->path, force: true, schema: new SchemaConfig(
            filterableAttributes: ['color', 'mixed'],
            searchableAttributes: ['title'],
        ));
        $docs = [];
        for ($id = 1; $id <= 200; $id++) {
            $docs[] = ['id' => $id, 'title' => 'item ' . ($id % 2 ? 'odd' : 'even'),
                'color' => $id % 2 ? 'red' : 'blue', 'mixed' => $id === 7 ? 'n/a' : $id * 1.5];
        }
        $index->insert($docs);
        $reader = new Index($this->path, readonly: true);
        $only   = new SearchOptions(limit: 3, filter: ['color' => 'red'], facetStatsOnly: ['mixed']);
        $before = $reader->search('', $only);
        $this->assertSame([], $before->facetStats);

        $index->delete(7);
        $exact  = new Config(limits: Limits::exact());
        $red    = new SearchOptions(limit: 3, filter: ['color' => 'red']);
        $search = ['query' => '', 'config' => $exact, 'options' => $red];
        foreach (['', 'item', 'odd'] as $query) {
            foreach (['search', 'searchBoolean'] as $method) {
                $search['query'] = $query;
                $this->assertStatsOnlyMatchesFacet($this->path, $exact, $exact, $method, $search, 'mixed');
            }
        }
        $after = $reader->search('', $only);
        $this->assertSame(['mixed' => ['min' => 1.5, 'max' => 298.5]], $after->facetStats);

        // A numbers-only key flagged by another connection while this reader has it cached.
        $numbers = new SearchOptions(limit: 3, facetStatsOnly: ['mixed'], filter: ['color' => 'blue']);
        $statsOf = static fn(): array => $reader->search('', $numbers)->facetStats;
        $this->assertSame(['mixed' => ['min' => 3.0, 'max' => 300.0]], $statsOf());
        $index->clear();
        $index->insert([['id' => 1, 'title' => 'item', 'color' => 'blue', 'mixed' => 2]]);
        $this->assertSame(['mixed' => ['min' => 2.0, 'max' => 2.0]], $statsOf());
        $index->insert([['id' => 2, 'title' => 'item', 'color' => 'blue', 'mixed' => 'n/a']]);
        $this->assertSame([], $statsOf());
        $reader->close();
        $index->close();
    }

    /** @return array<mixed> facet key name → facet_keys.has_strings */
    private function stringFlags(string $path): array
    {
        $stmt = new \PDO('sqlite:' . $path)->query('SELECT name, has_strings FROM facet_keys ORDER BY name');
        $this->assertNotFalse($stmt);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function flagDoc(int $id, array $fields): array
    {
        return ['id' => $id, 'title' => 'item', ...$fields];
    }

    public function testTheResponseIsUnchangedWithoutTheOption(): void
    {
        $index  = new Index(self::$shopPath, readonly: true);
        $result = $index->search('', new SearchOptions(limit: 1, facets: ['brand']));
        $this->assertSame([], $result->facetStats);
        $this->assertSame([], $result->approximateFacets);
        $this->assertSame(['brand'], array_keys($result->facetDistribution));
    }

    public function testStatsOnlyFieldsHaveNoDistributionAndFollowTheFacets(): void
    {
        $index  = new Index(self::$shopPath, readonly: true);
        $result = $index->search('', new SearchOptions(
            limit: 1,
            facets: ['unit_price', 'brand'],
            facetStatsOnly: ['date', 'price', 'sku'],
        ));
        $this->assertSame(['unit_price', 'brand'], array_keys($result->facetDistribution));
        // The facets' stats first, then the stats-only fields in their order; a string field has none.
        $this->assertSame(['unit_price', 'date', 'price'], array_keys($result->facetStats));
    }

    public function testAFieldInBothListsThrows(): void
    {
        $index = new Index(self::$shopPath, readonly: true);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'price'");
        $index->search('tile', new SearchOptions(facets: ['brand', 'price'], facetStatsOnly: ['price']));
    }

    public function testAFieldThatIsNotFilterableThrows(): void
    {
        $index = new Index(self::$shopPath, readonly: true);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Facet stats field 'menu_order' is not filterable");
        $index->search('', new SearchOptions(facetStatsOnly: ['menu_order']));
    }

    /**
     * The same search with $fields as facets and as facetStatsOnly: identical hits, total,
     * flags and other facets; the same stats where the facet is exact, and where it is not,
     * either the facet's approximate stats (flagged) or the exact ones.
     *
     * @param ShopSearch $search
     */
    private function assertStatsOnlyMatchesFacet(
        string $path,
        Config $config,
        Config $exact,
        string $method,
        array $search,
        string ...$fields,
    ): void {
        $options = $search['options'];
        $others  = array_values(array_diff($options->facets, $fields));
        $label   = "{$method} \"{$search['query']}\" " . json_encode($options->filter) . ' ' . implode(',', $fields);

        $facet = $this->searchWith($path, $config, $method, $search['query'], self::withOptions($options, [
            'facets' => [...$others, ...$fields],
        ]));
        $only  = $this->searchWith($path, $config, $method, $search['query'], self::withOptions($options, [
            'facets'         => $others,
            'facetStatsOnly' => $fields,
        ]));
        $truth = $this->searchWith($path, $exact, $method, $search['query'], self::withOptions($options, [
            'facets' => [...$others, ...$fields],
        ]));

        $this->assertSame($facet->getIds(), $only->getIds(), $label);
        $this->assertSame($facet->totalHits, $only->totalHits, $label);
        $this->assertSame($facet->exhaustive, $only->exhaustive, $label);
        $this->assertSame(
            array_diff_key($facet->facetDistribution, array_flip($fields)),
            $only->facetDistribution,
            $label,
        );
        $this->assertSame(
            array_diff_key($facet->facetStats, array_flip($fields)),
            array_diff_key($only->facetStats, array_flip($fields)),
            $label,
        );
        foreach ($fields as $field) {
            $stats       = $only->facetStats[$field] ?? null;
            $approximate = in_array($field, $only->approximateFacets, true);
            if (!in_array($field, $facet->approximateFacets, true)) {
                $this->assertFalse($approximate, "{$label}: {$field} flagged");
                $this->assertSame($facet->facetStats[$field] ?? null, $stats, "{$label}: {$field}");
            } elseif ($approximate) {
                $this->assertSame($facet->facetStats[$field] ?? null, $stats, "{$label}: {$field}");
            } else {
                $this->assertSame($truth->facetStats[$field] ?? null, $stats, "{$label}: {$field} exact");
            }
        }
    }

    private function searchWith(
        string $path,
        Config $config,
        string $method,
        string $query,
        SearchOptions $options,
    ): SearchResult {
        $index = new Index($path, config: $config, readonly: true);
        try {
            return $method === 'search' ? $index->search($query, $options) : $index->searchBoolean($query, $options);
        } finally {
            $index->close();
        }
    }

    /** @param array<string, mixed> $changes */
    private static function withOptions(SearchOptions $options, array $changes): SearchOptions
    {
        return new \ReflectionClass(SearchOptions::class)->newInstanceArgs([...get_object_vars($options), ...$changes]);
    }

    /** @param array<string, mixed> $changes */
    private static function withConfig(Config $config, array $changes): Config
    {
        return new \ReflectionClass(Config::class)->newInstanceArgs([...get_object_vars($config), ...$changes]);
    }
}

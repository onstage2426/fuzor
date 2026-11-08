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

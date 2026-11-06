<?php

declare(strict_types=1);

namespace Fuzor\Benchmarks;

use Fuzor\FacetSearchQuery;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Tests\ShopCatalog;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * PHPBench suite for Fuzor.
 *
 * Run all benchmarks:
 *   ./vendor/bin/phpbench run benchmarks/FuzorBench.php --report=default
 *
 * Run only search benchmarks:
 *   ./vendor/bin/phpbench run benchmarks/FuzorBench.php --group=search --report=default
 *
 * Store a baseline, then compare:
 *   ./vendor/bin/phpbench run benchmarks/FuzorBench.php --store --tag=baseline --report=default
 *   ./vendor/bin/phpbench run benchmarks/FuzorBench.php --report=default --ref=baseline
 *
 * Run the pages of a web shop (a new read-only Index per request, as on a site):
 *   ./vendor/bin/phpbench run benchmarks/FuzorBench.php --group=shop --report=default
 *
 * Notes:
 * - PHPBench's remote executor spawns a new PHP process per iteration, so there is no
 *   shared memory between iterations. The search DB is stored at a fixed path on disk
 *   and rebuilt lazily when missing; this avoids re-building it for every iteration.
 * - The search DB path intentionally persists across phpbench runs for consistency.
 *   Delete /tmp/fuzor_phpbench_search.db to force a rebuild.
 */
class FuzorBench
{
    /**
     * Shared search index — built once, reused across all search/boolean/fuzzy iterations.
     * Persists between phpbench invocations; delete manually to force a rebuild.
     */
    private const SEARCH_DB = '/tmp/fuzor_phpbench_search.db';

    /**
     * Facet index — same corpus with synthetic genre/year facet fields.
     * Persists between phpbench invocations; delete manually to force a rebuild.
     */
    private const FACET_DB = '/tmp/fuzor_phpbench_facet.db';

    /**
     * Generated shop catalogue (tests/ShopCatalog.php).
     * Persists between phpbench invocations; delete manually to force a rebuild.
     */
    private const SHOP_DB = '/tmp/fuzor_phpbench_shop.db';

    private const SHOP_DOCS = 8000;

    /** @var list<array{id: int, text: string}> */
    private static array $docs = [];

    private Index $index;

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    private const MOVIES_URL   = 'https://raw.githubusercontent.com/onstage2426/fuzor/refs/heads/assets/datasets/movies.json';
    private const MOVIES_CACHE = '/tmp/fuzor_phpbench_movies.json';

    private static function loadDocs(): void
    {
        if (self::$docs !== []) {
            return;
        }
        if (!file_exists(self::MOVIES_CACHE)) {
            $data = file_get_contents(self::MOVIES_URL);
            if ($data === false) {
                throw new \RuntimeException('Failed to download movies dataset from ' . self::MOVIES_URL);
            }
            file_put_contents(self::MOVIES_CACHE, $data);
        }
        $json = (string) file_get_contents(self::MOVIES_CACHE);
        /** @var list<array<string, mixed>> $movies */
        $movies = json_decode($json, true);
        self::$docs = array_map(fn(array $m): array => [
            'id'   => (int) (is_int($m['id']) || is_string($m['id']) ? $m['id'] : 0),
            'text' => trim(is_string($m['title'] ?? null) ? $m['title'] : '') . ' '
                    . trim(is_string($m['overview'] ?? null) ? $m['overview'] : ''),
        ], $movies);
    }

    private static function ensureSearchDb(): void
    {
        if (file_exists(self::SEARCH_DB)) {
            return;
        }
        self::loadDocs();
        $idx = new Index(self::SEARCH_DB, force: true, schema: new SchemaConfig(language: 'en'));
        $idx->insert(self::$docs);
        $idx->close();
    }

    private static function ensureFacetDb(): void
    {
        if (file_exists(self::FACET_DB)) {
            return;
        }
        self::loadDocs();
        $genres = ['Action', 'Drama', 'Comedy', 'Thriller', 'Horror', 'Romance', 'Science Fiction', 'Documentary'];
        $idx    = new Index(self::FACET_DB, force: true, schema: new SchemaConfig(
            language:    'en',
            filterableAttributes: ['genre', 'year'],
            sortableAttributes: ['year'],
        ));
        $docs = array_map(function (array $doc) use ($genres): array {
            return [
                'id'    => $doc['id'],
                'text'  => $doc['text'],
                'genre' => $genres[$doc['id'] % count($genres)],
                'year'  => 1980 + ($doc['id'] % 45),
            ];
        }, self::$docs);
        $idx->insert($docs);
        $idx->close();
    }

    private static function ensureShopDb(): void
    {
        if (file_exists(self::SHOP_DB)) {
            return;
        }
        $idx = new Index(self::SHOP_DB, force: true, schema: ShopCatalog::schema());
        $idx->insert(ShopCatalog::documents(self::SHOP_DOCS));
        $idx->close();
    }

    public function setUpIndex(): void
    {
        self::loadDocs();
    }

    public function setUpSearch(): void
    {
        self::ensureSearchDb();
        $this->index = new Index(self::SEARCH_DB);
    }

    public function setUpFacet(): void
    {
        self::ensureFacetDb();
        $this->index = new Index(self::FACET_DB);
    }

    public function setUpPrefix(): void
    {
        self::ensureSearchDb();
        $this->index = new Index(self::SEARCH_DB);
    }

    public function tearDownSearch(): void
    {
        $this->index->close();
    }

    // -----------------------------------------------------------------------
    // Index benchmarks
    // -----------------------------------------------------------------------

    #[Groups(['index'])]
    #[BeforeMethods('setUpIndex')]
    #[Iterations(2)]
    #[Revs(1)]
    #[Warmup(1)]
    public function benchInsertMany(): void
    {
        $path = sys_get_temp_dir() . '/fuzor_bench_im_' . getmypid() . '.db';
        @unlink($path);
        $idx = new Index($path, force: true, schema: new SchemaConfig(language: 'en'));
        $idx->insert(self::$docs);
        $idx->close();
        @unlink($path);
    }

    #[Groups(['index'])]
    #[BeforeMethods('setUpIndex')]
    #[Iterations(3)]
    #[Revs(1)]
    #[Warmup(1)]
    public function benchInsertSequential(): void
    {
        $path = sys_get_temp_dir() . '/fuzor_bench_is_' . getmypid() . '.db';
        @unlink($path);
        $idx = new Index($path, force: true, schema: new SchemaConfig(language: 'en'));
        foreach (array_slice(self::$docs, 0, 1000) as $doc) {
            $idx->insert([$doc]);
        }
        $idx->close();
        @unlink($path);
    }

    // -----------------------------------------------------------------------
    // Shop pages — a new read-only connection per request
    // -----------------------------------------------------------------------

    public function setUpShop(): void
    {
        self::ensureShopDb();
    }

    /** @param array{search: string} $params */
    #[Groups(['shop'])]
    #[BeforeMethods('setUpShop')]
    #[Iterations(5)]
    #[Revs(20)]
    #[Warmup(1)]
    #[ParamProviders('provideShopSearches')]
    public function benchShopPage(array $params): void
    {
        $search = ShopCatalog::searches()[$params['search']];
        $index  = new Index(self::SHOP_DB, config: $search['config'], readonly: true);
        $index->search($search['query'], $search['options']);
        $index->close();
    }

    /** @return iterable<string, array{search: string}> */
    public function provideShopSearches(): iterable
    {
        foreach (array_keys(ShopCatalog::searches()) as $name) {
            yield $name => ['search' => $name];
        }
    }

    // -----------------------------------------------------------------------
    // Search — exact BM25
    // -----------------------------------------------------------------------

    /** @param array{query: string} $params */
    #[Groups(['search'])]
    #[BeforeMethods('setUpSearch')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(50)]
    #[Warmup(1)]
    #[ParamProviders('provideSearchQueries')]
    public function benchSearch(array $params): void
    {
        $this->index->search($params['query'], new SearchOptions(asYouType: false));
    }

    /** @return iterable<string, array{query: string}> */
    public function provideSearchQueries(): iterable
    {
        yield 'anarchism'    => ['query' => 'anarchism'];
        yield 'space'        => ['query' => 'space'];
        yield 'love'         => ['query' => 'love'];
        yield 'war'          => ['query' => 'war'];
        yield 'dragon'       => ['query' => 'dragon'];
        yield 'secret agent' => ['query' => 'secret agent'];
    }

    // -----------------------------------------------------------------------
    // Search — as-you-type prefix
    // -----------------------------------------------------------------------

    /** @param array{query: string} $params */
    #[Groups(['search', 'prefix'])]
    #[BeforeMethods('setUpPrefix')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(50)]
    #[Warmup(1)]
    #[ParamProviders('providePrefixQueries')]
    public function benchSearchPrefix(array $params): void
    {
        $this->index->search($params['query']);
    }

    /** @return iterable<string, array{query: string}> */
    public function providePrefixQueries(): iterable
    {
        yield 'sci' => ['query' => 'sci'];
        yield 'dra' => ['query' => 'dra'];
        yield 'adv' => ['query' => 'adv'];
    }

    // -----------------------------------------------------------------------
    // Search — typo tolerance (Levenshtein fallback)
    // -----------------------------------------------------------------------

    /** @param array{query: string} $params */
    #[Groups(['search', 'fuzzy'])]
    #[BeforeMethods('setUpSearch')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(20)]
    #[Warmup(1)]
    #[ParamProviders('provideFuzzyQueries')]
    public function benchSearchTypo(array $params): void
    {
        $this->index->search($params['query']);
    }

    /** @return iterable<string, array{query: string}> */
    public function provideFuzzyQueries(): iterable
    {
        yield 'spase'   => ['query' => 'spase'];
        yield 'draggon' => ['query' => 'draggon'];
        yield 'lovve'   => ['query' => 'lovve'];
        yield 'warroir' => ['query' => 'warroir'];
    }

    // -----------------------------------------------------------------------
    // Search — boolean
    // -----------------------------------------------------------------------

    /** @param array{query: string} $params */
    #[Groups(['search', 'boolean'])]
    #[BeforeMethods('setUpSearch')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(50)]
    #[Warmup(1)]
    #[ParamProviders('provideBooleanQueries')]
    public function benchSearchBoolean(array $params): void
    {
        $this->index->searchBoolean($params['query'], new SearchOptions(asYouType: false));
    }

    /** @return iterable<string, array{query: string}> */
    public function provideBooleanQueries(): iterable
    {
        yield 'space or war'                    => ['query' => 'space or war'];
        yield 'love -romance'                   => ['query' => 'love -romance'];
        yield 'hero and villain'                => ['query' => 'hero and villain'];
        yield 'action or adventure or thriller' => ['query' => 'action or adventure or thriller'];
    }

    // -----------------------------------------------------------------------
    // facetSearch — facet value enumeration / autocomplete
    // -----------------------------------------------------------------------

    /**
     * All values for a facet field — no FTS restriction, no prefix, no filter.
     * Exercises the pure clustered GROUP BY path on facet_values.
     */
    #[Groups(['search', 'facet'])]
    #[BeforeMethods('setUpFacet')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(200)]
    #[Warmup(1)]
    public function benchFacetSearchAll(): void
    {
        $this->index->facetSearch(new FacetSearchQuery(facetName: 'genre'));
    }

    /**
     * Prefix match on facet values — exercises LOWER(value) LIKE with ESCAPE.
     * 'sc' matches 'Science Fiction' but not the other 7 synthetic genres.
     */
    #[Groups(['search', 'facet'])]
    #[BeforeMethods('setUpFacet')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(200)]
    #[Warmup(1)]
    public function benchFacetSearchPrefix(): void
    {
        $this->index->facetSearch(new FacetSearchQuery(facetName: 'genre', facetQuery: 'sc'));
    }

    /**
     * FTS restriction — runs the query pipeline to build candidate doc IDs,
     * then counts genre values only for those docs.
     *
     * @param array{query: string} $params
     */
    #[Groups(['search', 'facet'])]
    #[BeforeMethods('setUpFacet')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(100)]
    #[Warmup(1)]
    #[ParamProviders('provideFacetSearchQueries')]
    public function benchFacetSearchWithFtsQuery(array $params): void
    {
        $this->index->facetSearch(new FacetSearchQuery(facetName: 'genre', q: (string) $params['query']));
    }

    /** @return iterable<string, array{query: string}> */
    public function provideFacetSearchQueries(): iterable
    {
        yield 'space'     => ['query' => 'space'];
        yield 'love'      => ['query' => 'love'];
        yield 'adventure' => ['query' => 'adventure'];
    }

    /**
     * Numeric range filter — restricts candidate docs via the facet_numeric_index,
     * then counts genre values for those docs.
     */
    #[Groups(['search', 'facet'])]
    #[BeforeMethods('setUpFacet')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(100)]
    #[Warmup(1)]
    public function benchFacetSearchWithFilter(): void
    {
        $this->index->facetSearch(new FacetSearchQuery(
            facetName: 'genre',
            filter:    ['year' => new \Fuzor\FacetRange(gte: 2000.0)],
        ));
    }
}

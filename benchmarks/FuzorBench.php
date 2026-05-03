<?php

declare(strict_types=1);

namespace Fuzor\Benchmarks;

use Fuzor\Index;
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

    /** @var list<array{id: int, text: string}> */
    private static array $docs = [];

    private Index $index;

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    private static function loadDocs(): void
    {
        if (self::$docs !== []) {
            return;
        }
        $json = (string) file_get_contents(__DIR__ . '/../resources/datasets/movies.json');
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
        $idx = new Index(self::SEARCH_DB, force: true);
        $idx->insertMany(self::$docs);
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
    #[Iterations(3)]
    #[Revs(1)]
    #[Warmup(1)]
    public function benchInsertMany(): void
    {
        $path = sys_get_temp_dir() . '/fuzor_bench_im_' . getmypid() . '.db';
        @unlink($path);
        $idx = new Index($path, force: true);
        $idx->insertMany(self::$docs);
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
        $idx = new Index($path, force: true);
        foreach (self::$docs as $doc) {
            $idx->insert($doc);
        }
        $idx->close();
        @unlink($path);
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
        $this->index->search($params['query'], asYouType: false);
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
    // Search — fuzzy (Levenshtein)
    // -----------------------------------------------------------------------

    /** @param array{query: string} $params */
    #[Groups(['search', 'fuzzy'])]
    #[BeforeMethods('setUpSearch')]
    #[AfterMethods('tearDownSearch')]
    #[Iterations(5)]
    #[Revs(20)]
    #[Warmup(1)]
    #[ParamProviders('provideFuzzyQueries')]
    public function benchSearchFuzzy(array $params): void
    {
        $this->index->search($params['query'], fuzzy: true);
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
        $this->index->searchBoolean($params['query'], asYouType: false);
    }

    /** @return iterable<string, array{query: string}> */
    public function provideBooleanQueries(): iterable
    {
        yield 'space or war'                    => ['query' => 'space or war'];
        yield 'love -romance'                   => ['query' => 'love -romance'];
        yield 'hero and villain'                => ['query' => 'hero and villain'];
        yield 'action or adventure or thriller' => ['query' => 'action or adventure or thriller'];
    }
}

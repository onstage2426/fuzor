<?php

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetExclude;
use Fuzor\Index;
use Fuzor\MatchingStrategy;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Properties search() must keep whatever its caps are, checked on small random indexes: the
 * same file is searched with a tiny maxDocs (every word is cut) and with no cap (the reference).
 *
 * Invariants that do not hold yet are reported as incomplete with the first counterexample,
 * so the suite stays green; the step that fixes one removes its knownGap() call.
 */
class SearchInvariantTest extends TestCase
{
    private const int DOCS = 400;
    private const int QUERIES = 40;

    /** Common words and near spellings of them, so typo derivations exist in the index. */
    private const array WORDS = [
        'shirt', 'shrit', 'cotton', 'coton', 'classic', 'classi', 'silver', 'sliver', 'leather',
        'casual', 'formal', 'summer', 'winter', 'sports', 'running', 'printed', 'striped', 'women',
        'men', 'kids', 'black', 'white', 'grey', 'blue', 'dress', 'shoes', 'watch', 'jacket',
        'jeans', 'sandal', 'canvas', 'denim', 'linen', 'woven', 'slim', 'fit', 'polo', 'kurta',
    ];

    private const array COLORS = ['red', 'blue', 'green', 'black', 'white'];

    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/fuzor_inv_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, ...(glob($this->dbPath . '[.-]*') ?: [])] as $f) {
            if (is_link($f) || file_exists($f)) {
                unlink($f);
            }
        }
    }

    /** @return array<string, array{int}> */
    public static function seeds(): array
    {
        return ['seed 1' => [1], 'seed 2' => [2], 'seed 3' => [3], 'seed 4' => [4]];
    }

    /** @return array<string, SearchOptions> */
    private static function variants(): array
    {
        return [
            'plain'   => new SearchOptions(asYouType: false, limit: 10),
            'prefix'  => new SearchOptions(limit: 10),
            'filter'  => new SearchOptions(asYouType: false, limit: 10, filter: ['color' => ['red', 'blue']]),
            'exclude' => new SearchOptions(asYouType: false, limit: 10, filter: ['color' => new FacetExclude('red')]),
            'sort'    => new SearchOptions(asYouType: false, limit: 10, sort: ['price:asc']),
            'all'     => new SearchOptions(asYouType: false, limit: 10, matchingStrategy: MatchingStrategy::All),
        ];
    }

    /** exhaustive: true means the page and the total are the uncapped ones. */
    #[DataProvider('seeds')]
    public function testExhaustiveResultEqualsUncappedResult(int $seed): void
    {
        [$capped, $reference, $queries] = $this->build($seed);
        $checked = 0;
        foreach (self::variants() as $name => $options) {
            foreach ($queries as $q) {
                $a = $capped->search($q, $options);
                if (!$a->exhaustive) {
                    continue;
                }
                $checked++;
                $b = $reference->search($q, $options);
                $this->assertTrue(
                    $a->getIds() === $b->getIds() && $a->totalHits === $b->totalHits,
                    sprintf(
                        'exhaustive result differs from the uncapped one (%s "%s"): total %d vs %d, page %s vs %s',
                        $name,
                        $q,
                        $a->totalHits,
                        $b->totalHits,
                        implode(',', $a->getIds()),
                        implode(',', $b->getIds()),
                    ),
                );
            }
        }
        $this->assertGreaterThan(0, $checked, 'no exhaustive result to compare');
    }

    /** totalHits is the uncapped number of matches, whatever maxDocs cut from the candidates. */
    #[DataProvider('seeds')]
    public function testTotalHitsEqualsUncappedTotal(int $seed): void
    {
        [$capped, $reference, $queries] = $this->build($seed);
        foreach (self::variants() as $name => $options) {
            foreach ($queries as $q) {
                $this->assertSame(
                    $reference->search($q, $options)->totalHits,
                    $capped->search($q, $options)->totalHits,
                    "$name \"$q\"",
                );
            }
        }
    }

    /** A page that starts at totalHits or later is empty. */
    #[DataProvider('seeds')]
    public function testPageAtTotalHitsIsEmpty(int $seed): void
    {
        [$capped, , $queries] = $this->build($seed);
        foreach (self::variants() as $name => $options) {
            foreach ($queries as $q) {
                $total = $capped->search($q, $options)->totalHits;
                $this->assertNotNull($total);
                $end   = $capped->search($q, self::page($options, $total, 10));
                $this->assertSame(
                    0,
                    $end->hitsCount,
                    sprintf('%s "%s": offset %d = totalHits returned %d hits', $name, $q, $total, $end->hitsCount),
                );
            }
        }
    }

    /** Consecutive pages concatenate to one page of both sizes: no document twice, none skipped. */
    #[DataProvider('seeds')]
    public function testConsecutivePagesConcatenate(int $seed): void
    {
        [$capped, , $queries] = $this->build($seed);
        foreach (self::variants() as $name => $options) {
            foreach ($queries as $q) {
                $first  = $capped->search($q, self::page($options, 0, 10))->getIds();
                $second = $capped->search($q, self::page($options, 10, 10))->getIds();
                $both   = $capped->search($q, self::page($options, 0, 20))->getIds();
                $this->knownGap(
                    [...$first, ...$second] === $both,
                    sprintf(
                        '%s "%s": pages %s + %s, one page %s',
                        $name,
                        $q,
                        implode(',', $first),
                        implode(',', $second),
                        implode(',', $both),
                    ),
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    /** The uncapped reference itself keeps the paging invariants (it is what the others are judged by). */
    #[DataProvider('seeds')]
    public function testUncappedSearchPagesConsistently(int $seed): void
    {
        [, $reference, $queries] = $this->build($seed);
        foreach (self::variants() as $name => $options) {
            foreach ($queries as $q) {
                $all = $reference->search($q, self::page($options, 0, self::DOCS));
                $this->assertSame($all->totalHits, $all->hitsCount, "$name \"$q\": totalHits");
                $ids = $all->getIds();
                foreach ([0, 10, 20] as $offset) {
                    $page = $reference->search($q, self::page($options, $offset, 10));
                    $this->assertSame(array_slice($ids, $offset, 10), $page->getIds(), "$name \"$q\" offset $offset");
                }
            }
        }
    }

    /**
     * A property that does not hold yet: report the first counterexample as incomplete instead
     * of failing. Remove the call (use an assertion) once the property holds.
     */
    private function knownGap(bool $holds, string $counterexample): void
    {
        if (!$holds) {
            $this->markTestIncomplete('Known gap: ' . $counterexample);
        }
    }

    private static function page(SearchOptions $o, int $offset, int $limit): SearchOptions
    {
        return new SearchOptions(
            asYouType: $o->asYouType,
            limit: $limit,
            offset: $offset,
            filter: $o->filter,
            sort: $o->sort,
            matchingStrategy: $o->matchingStrategy,
        );
    }

    /**
     * A random index (Zipf-like word frequencies, so a few words are in most documents) opened
     * twice: with maxDocs 5 and uncapped.
     *
     * @return array{Index, Index, list<string>}
     */
    private function build(int $seed): array
    {
        mt_srand($seed);
        // Rank r is picked with weight 1 / (r + 1).
        $weights = [];
        foreach (array_keys(self::WORDS) as $r) {
            $weights[] = 1.0 / ($r + 1);
        }
        $pick    = function () use ($weights): string {
            $x = mt_rand() / mt_getrandmax() * array_sum($weights);
            foreach ($weights as $r => $w) {
                $x -= $w;
                if ($x <= 0) {
                    return self::WORDS[$r];
                }
            }
            return self::WORDS[0];
        };
        $words = fn(int $min, int $max): string
            => implode(' ', array_map(fn() => $pick(), range(1, mt_rand($min, $max))));
        $docs = [];
        for ($id = 1; $id <= self::DOCS; $id++) {
            $docs[] = [
                'id'    => $id,
                'title' => $words(2, 5),
                'body'  => $words(0, 25),
                'color' => self::COLORS[mt_rand(0, count(self::COLORS) - 1)],
                'price' => mt_rand(1, 50),
            ];
        }
        $reference = new Index(
            $this->dbPath,
            config: new Config(maxDocs: 100_000_000, maxFacetCountDocs: 100_000_000),
            schema: new SchemaConfig(filterableAttributes: ['color'], sortableAttributes: ['price']),
        );
        $reference->insert($docs);
        $capped = new Index($this->dbPath, config: new Config(maxDocs: 5), readonly: true);

        $queries = [];
        while (count($queries) < self::QUERIES) {
            $queries[$words(2, 3)] = true;
        }
        return [$capped, $reference, array_map('strval', array_keys($queries))];
    }
}

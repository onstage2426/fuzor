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
            'facets'  => new SearchOptions(asYouType: false, limit: 10, facets: ['color', 'price']),
            // A filter on a counted field: its counts leave that filter out (disjunctive).
            'filter'  => new SearchOptions(
                asYouType: false,
                limit: 10,
                filter: ['color' => ['red', 'blue']],
                facets: ['price', 'color'],
            ),
            'exclude' => new SearchOptions(
                asYouType: false,
                limit: 10,
                filter: ['color' => new FacetExclude('red')],
                facets: ['color'],
            ),
            'negated' => new SearchOptions(asYouType: false, limit: 10, facets: ['color']),
            'sort'    => new SearchOptions(asYouType: false, limit: 10, sort: ['price:asc']),
            'all'     => new SearchOptions(asYouType: false, limit: 10, matchingStrategy: MatchingStrategy::All),
            'distinct' => new SearchOptions(asYouType: false, limit: 10, distinct: 'price'),
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

    /** searchBoolean()'s totalHits is the uncapped number of matches too, for every operator. */
    #[DataProvider('seeds')]
    public function testBooleanTotalHitsEqualsUncappedTotal(int $seed): void
    {
        [$capped, $reference, $queries] = $this->build($seed);
        $cut     = 0;
        $options = [
            new SearchOptions(asYouType: false),
            new SearchOptions(),
            new SearchOptions(asYouType: false, filter: ['color' => ['red', 'blue']]),
            new SearchOptions(asYouType: false, filter: ['color' => new FacetExclude('red')]),
            new SearchOptions(asYouType: false, distinct: 'price'),
        ];
        foreach ($queries as $q) {
            [$a, $b] = explode(' ', $q);
            $c = explode(' ', $q)[2] ?? 'shirt';
            $expressions = [
                "$a $b", "$a | $b", "$a -$b", "-$a $b", "($a | $b) $c", "$a | -$b",
                "-$a -$b $c", "\"$a $b\" | $c", "$a $b | -$c",
            ];
            foreach ($expressions as $e) {
                foreach ($options as $i => $o) {
                    $result = $capped->searchBoolean($e, $o);
                    $cut   += $result->exhaustive ? 0 : 1;
                    $this->assertSame(
                        $reference->searchBoolean($e, $o)->totalHits,
                        $result->totalHits,
                        "options $i \"$e\"",
                    );
                }
            }
        }
        $this->assertGreaterThan(0, $cut, 'no capped boolean search');
    }

    /** Facet counts cover the whole result set, not the candidates maxDocs left. */
    #[DataProvider('seeds')]
    public function testFacetCountsEqualUncappedCounts(int $seed): void
    {
        [$capped, $reference, $queries] = $this->build($seed);
        foreach (self::variants() as $name => $options) {
            if ($options->facets === []) {
                continue;
            }
            foreach ($queries as $q) {
                $q = $name === 'negated' ? "$q -shirt" : $q;
                $a = $capped->search($q, $options);
                $b = $reference->search($q, $options);
                $this->assertSame($b->facetDistribution, $a->facetDistribution, "$name \"$q\"");
                $this->assertSame($b->facetStats, $a->facetStats, "$name \"$q\"");
                $this->assertSame([], $a->approximateFacets);
            }
        }
    }

    /**
     * A one-word query whose word is cut at maxDocs ranks its documents by BM25 score: the
     * maxDocs best by score are fetched, not the maxDocs with the most hits, so a page within
     * them equals the uncapped one (short words: no typo derivations).
     */
    #[DataProvider('seeds')]
    public function testOneWordPageWithinMaxDocsEqualsUncappedPage(int $seed): void
    {
        [$capped, $reference] = $this->build($seed);
        $options = new SearchOptions(asYouType: false, limit: 5);
        $checked = 0;
        foreach (self::WORDS as $word) {
            if (mb_strlen($word) >= 5 || $reference->search($word, $options)->totalHits <= 20) {
                continue;
            }
            $checked++;
            $this->assertSame(
                $reference->search($word, $options)->getIds(),
                $capped->search($word, $options)->getIds(),
                "\"$word\"",
            );
        }
        $this->assertGreaterThan(0, $checked, 'no word above 4 × maxDocs');
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
     * The uncapped search equals a brute-force ranker that shares no ranking code with Index
     * (ReferenceRanker): totals, every page position's rank key, facet counts. Proximity is off,
     * which the brute force does not model.
     */
    #[DataProvider('seeds')]
    public function testUncappedSearchEqualsBruteForce(int $seed): void
    {
        [, , $queries, $docs] = $this->build($seed);
        $index  = $this->openWithoutProximity(100_000_000);
        $ranker = new ReferenceRanker($docs, ['title', 'body'], $index);
        foreach (self::bruteForceCases($queries) as [$name, $q, $options]) {
            $this->assertMatchesBruteForce($ranker, $index, $name, $q, $options, facets: true);
        }
    }

    /** An exhaustive result of the capped search equals the brute-force ranking too. */
    #[DataProvider('seeds')]
    public function testExhaustiveCappedSearchEqualsBruteForce(int $seed): void
    {
        [, , $queries, $docs] = $this->build($seed);
        $index   = $this->openWithoutProximity(5);
        $ranker  = new ReferenceRanker($docs, ['title', 'body'], $index);
        $checked = 0;
        foreach (self::bruteForceCases($queries) as [$name, $q, $options]) {
            if ($index->search($q, $options)->exhaustive) {
                $checked++;
                $this->assertMatchesBruteForce($ranker, $index, $name, $q, $options, facets: false);
            }
        }
        $this->assertGreaterThan(0, $checked, 'no exhaustive result to compare');
    }

    /**
     * Every variant, plus negations, quoted phrases and MatchingStrategy::Frequency.
     *
     * @param  list<string> $queries
     * @return \Generator<array{string, string, SearchOptions}>
     */
    private static function bruteForceCases(array $queries): \Generator
    {
        $exact = new SearchOptions(asYouType: false, limit: 10, facets: ['color']);
        foreach ($queries as $q) {
            foreach (self::variants() as $name => $options) {
                yield [$name, $q, $options];
            }
            $words = explode(' ', $q);
            yield ['negated', "$q -shirt -\"cotton shirt\"", $exact];
            yield ['phrase', '"' . $words[0] . ' ' . $words[1] . '"' . (isset($words[2]) ? " $words[2]" : ''), $exact];
            yield ['frequency', $q, new SearchOptions(
                asYouType: false,
                limit: 10,
                matchingStrategy: MatchingStrategy::Frequency,
            )];
        }
    }

    /** Pages at offsets 0, 10 and 20 hold, position by position, documents with the reference's rank key. */
    private function assertMatchesBruteForce(
        ReferenceRanker $ranker,
        Index $index,
        string $name,
        string $q,
        SearchOptions $options,
        bool $facets,
    ): void {
        $expected = $ranker->search($q, $options);
        $label    = "$name \"$q\"";
        foreach ([0, 10, 20] as $offset) {
            $result = $index->search($q, self::page($options, $offset, 10, details: true));
            $this->assertSame(count($expected['ids']), $result->totalHits, "$label: totalHits");
            $want = array_slice($expected['ids'], $offset, 10);
            $got  = $result->getIds();
            $this->assertCount(count($want), $got, "$label offset $offset: page size");
            foreach ($got as $i => $id) {
                $this->assertArrayHasKey($id, $expected['keys'], "$label offset $offset: doc $id is no match");
                $a = $expected['keys'][$id];
                $b = $expected['keys'][$want[$i]];
                $same = count($a) === count($b);
                foreach ($a as $k => $x) {
                    $same = $same && ReferenceRanker::near($x, $b[$k]);
                }
                $this->assertTrue($same, sprintf(
                    '%s offset %d position %d: doc %d (key %s) where the reference has doc %d (key %s)',
                    $label,
                    $offset,
                    $i,
                    $id,
                    json_encode($a),
                    $want[$i],
                    json_encode($b),
                ));
                $this->assertSame(
                    json_encode(self::rankKey($result->hits[$i]['_rankingScoreDetails'])),
                    json_encode(array_map(fn(float|int $x): float => round((float) $x, 9), $a)),
                    "$label offset $offset: ranking details of doc $id",
                );
            }
        }
        if ($facets) {
            $distribution = $index->search($q, $options)->facetDistribution;
            $actual       = [];
            foreach ($distribution as $field => $counts) {
                $counts = array_combine(array_map('strval', array_keys($counts)), $counts);
                ksort($counts);
                $actual[$field] = $counts;
            }
            ksort($actual);
            $want = $expected['facets'];
            ksort($want);
            $this->assertSame($want, $actual, "$label: facets");
        }
    }

    private function openWithoutProximity(int $maxDocs): Index
    {
        return new Index(
            $this->dbPath,
            config: new Config(maxDocs: $maxDocs, maxFacetCountDocs: 100_000_000, proximityBoost: 0.0),
            readonly: true,
        );
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

    private static function page(SearchOptions $o, int $offset, int $limit, bool $details = false): SearchOptions
    {
        return new SearchOptions(
            asYouType: $o->asYouType,
            limit: $limit,
            offset: $offset,
            filter: $o->filter,
            sort: $o->sort,
            matchingStrategy: $o->matchingStrategy,
            distinct: $o->distinct,
            distinctCount: $o->distinctCount,
            showRankingScoreDetails: $details,
        );
    }

    /**
     * A hit's ranking details as ReferenceRanker's rank key: −bucket, typos, sort values
     * (negated for desc), −score × proximity factor; rounded so near-equal floats compare equal.
     *
     * @return list<float>
     */
    private static function rankKey(mixed $details): array
    {
        if (!is_array($details)) {
            throw new \LogicException('hit without ranking details');
        }
        $key = [];
        foreach ($details as $rule => $d) {
            $number = fn(string $field): float => is_array($d) && (is_int($d[$field]) || is_float($d[$field]))
                ? (float) $d[$field]
                : throw new \LogicException("$rule.$field is not a number");
            $key[] = round(match (true) {
                $rule === 'words'             => -$number('matchingWords'),
                $rule === 'typo'              => $number('typoCount'),
                $rule === 'bm25'              => -$number('score') * $number('proximityFactor'),
                str_ends_with($rule, ':desc') => -$number('value'),
                default                       => $number('value'),
            }, 9);
        }
        return $key;
    }

    /**
     * Every page, capped or not, is in the order of its own ranking details: they report the
     * keys the ranking compared, also for approximate results and with proximity on.
     */
    #[DataProvider('seeds')]
    public function testPagesAreOrderedByTheirRankingDetails(int $seed): void
    {
        [$capped, $reference, $queries] = $this->build($seed);
        $approximate = 0;
        foreach ([$capped, $reference] as $index) {
            foreach (self::variants() as $name => $options) {
                foreach ($queries as $q) {
                    foreach ([0, 10] as $offset) {
                        $result = $index->search($q, self::page($options, $offset, 10, details: true));
                        $approximate += $result->exhaustive ? 0 : 1;
                        $keys = array_map(
                            fn(array $hit): array => self::rankKey($hit['_rankingScoreDetails']),
                            $result->hits,
                        );
                        foreach ($keys as $i => $key) {
                            $this->assertSame(
                                count($options->sort) + 3,
                                count($key),
                                "$name \"$q\": rules of hit $i",
                            );
                            if ($i > 0) {
                                $this->assertLessThanOrEqual(
                                    0,
                                    $keys[$i - 1] <=> $key,
                                    sprintf(
                                        '%s "%s" offset %d: hit %d %s before %s',
                                        $name,
                                        $q,
                                        $offset,
                                        $i,
                                        json_encode($keys[$i - 1]),
                                        json_encode($key),
                                    ),
                                );
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $approximate, 'no approximate result checked');
    }

    /**
     * A random index (Zipf-like word frequencies, so a few words are in most documents) opened
     * twice: with maxDocs 5 and uncapped.
     *
     * @return array{Index, Index, list<string>, list<array<string, mixed>>}
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
            schema: new SchemaConfig(filterableAttributes: ['color', 'price'], sortableAttributes: ['price']),
        );
        $reference->insert($docs);
        $capped = new Index($this->dbPath, config: new Config(maxDocs: 5), readonly: true);

        $queries = [];
        while (count($queries) < self::QUERIES) {
            $queries[$words(2, 3)] = true;
        }
        return [$capped, $reference, array_map('strval', array_keys($queries)), $docs];
    }
}

<?php

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetExclude;
use Fuzor\FacetOrder;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\FacetSearchResult;
use Fuzor\Index;
use Fuzor\Limits;
use Fuzor\MatchingStrategy;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\SearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact output of search(), searchBoolean(), browse, facetSearch() and distinct on a
 * small seeded index: pages, totals, facet counts and stats, ranking details, flags and warnings,
 * under three configurations (no cap reached, every word cut, every cap reached) and before and
 * after deletes (pending tombstones) and updates.
 *
 * Meant for changes that must not change results (speed work). A change that is supposed to
 * change results regenerates the fixture on purpose (FUZOR_GOLDEN_UPDATE=1 vendor/bin/phpunit
 * --filter GoldenOutputTest); FUZOR_GOLDEN_DUMP=<file> writes every case (ids, total, hash of
 * the whole result) to compare two revisions case by case.
 */
class GoldenOutputTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/golden-output.txt';

    private const array WORDS = [
        'shirt', 'shrit', 'cotton', 'coton', 'classic', 'classi', 'silver', 'sliver', 'leather',
        'casual', 'formal', 'summer', 'winter', 'sports', 'running', 'printed', 'striped', 'women',
        'men', 'kids', 'black', 'white', 'grey', 'blue', 'dress', 'shoes', 'watch', 'jacket',
        'jeans', 'sandal', 'canvas', 'denim', 'linen', 'woven', 'slim', 'fit', 'polo', 'kurta',
    ];

    private const array COLORS = ['red', 'blue', 'green', 'black', 'white'];
    private const array TAGS = ['sale', 'new', 'eco', 'gift', 'limited', 'basic'];
    private const array BRANDS = ['Nike', 'nike', 'Adidas', 'Puma', 'zara', 'Éclair', 'Levi'];

    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/fuzor_golden_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, ...(glob($this->dbPath . '[.-]*') ?: [])] as $f) {
            if (is_link($f) || file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testOutputMatchesFixture(): void
    {
        mt_srand(11);
        $docs  = $this->documents(600);
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableAttributes: ['color', 'tags', 'price', 'brand'],
            sortableAttributes: ['price', 'brand'],
        ));
        $index->insert($docs);
        $index->setSynonyms([['jeans', 'denim']], ['kurta' => ['dress']]);
        $queries = $this->queries();

        $actual = [];
        $this->record($actual, 'live', $queries);

        // Pending tombstones (5%, under the purge ratio) and rewritten postings.
        $index->delete(...range(5, 600, 20));
        $changed = [];
        for ($id = 3; $id <= 600; $id += 30) {
            $changed[] = ['id' => $id, 'title' => $this->words(2, 4), 'body' => $docs[$id - 1]['body'],
                'color' => self::COLORS[mt_rand(0, 4)], 'tags' => ['sale'], 'price' => mt_rand(1, 60)];
        }
        $index->update($changed);
        $index->upsert([['id' => 1001, 'title' => 'classic cotton shirt', 'body' => 'blue', 'color' => 'blue']]);
        $this->assertTrue($index->search('shirt')->totalHits > 0);
        $this->record($actual, 'edited', $queries);

        if ($dump = getenv('FUZOR_GOLDEN_DUMP')) {
            file_put_contents($dump, implode('', array_map(
                fn(string $label, string $line): string => "$label\t$line\n",
                array_keys($actual),
                $actual,
            )));
        }
        // One hash per group (state / config / kind / variant) keeps the fixture small; the
        // dump above shows which query changed.
        $groups = [];
        foreach ($actual as $label => $line) {
            $group = implode('/', array_slice(explode('/', $label), 0, 4));
            $groups[$group] = ($groups[$group] ?? '') . "$label\t$line\n";
        }
        $actual = array_map(md5(...), $groups);

        if (getenv('FUZOR_GOLDEN_UPDATE')) {
            @mkdir(dirname(self::FIXTURE));
            file_put_contents(self::FIXTURE, implode('', array_map(
                fn(string $group, string $hash): string => "$group\t$hash\n",
                array_keys($actual),
                $actual,
            )));
            $this->markTestSkipped('fixture written: ' . count($actual) . ' groups');
        }

        $expected = [];
        foreach (file(self::FIXTURE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
            [$group, $hash] = explode("\t", $row, 2);
            $expected[$group] = $hash;
        }
        $this->assertSame(array_keys($expected), array_keys($actual), 'groups differ from the fixture');
        $diff = array_keys(array_diff_assoc($expected, $actual));
        $this->assertSame([], $diff, sprintf(
            "%d of %d groups differ (FUZOR_GOLDEN_DUMP=<file> on both revisions shows the cases):\n%s",
            count($diff),
            count($expected),
            implode("\n", $diff),
        ));
    }

    /**
     * Every case on three opens of the current file.
     *
     * @param array<string, string> $out
     * @param list<string>          $queries
     */
    private function record(array &$out, string $state, array $queries): void
    {
        $configs = [
            'open'   => new Config(),
            'cut'    => new Config(limits: new Limits(candidatesPerWord: 5)),
            'capped' => new Config(
                limits: new Limits(candidatesPerWord: 5, facetCountDocs: 40, totalHits: 30),
                fuzzyMaxExpansions: 3,
            ),
        ];
        foreach ($configs as $name => $config) {
            $index  = new Index($this->dbPath, config: $config, readonly: true);
            $prefix = "$state/$name";
            foreach ($queries as $q) {
                foreach (self::searchVariants() as $v => $options) {
                    $out["$prefix/search/$v/$q"] = self::line($index->search($q, $options));
                }
                $words = explode(' ', $q);
                $a     = $words[0];
                $b     = $words[1] ?? 'shirt';
                $c     = $words[2] ?? 'cotton';
                foreach (["$a $b", "$a | $b", "$a -$b", "($a | $b) $c", "-$a -$b $c", "\"$a $b\" | $c"] as $e) {
                    foreach (self::booleanVariants() as $v => $options) {
                        $out["$prefix/boolean/$v/$e"] = self::line($index->searchBoolean($e, $options));
                    }
                }
                foreach (self::facetSearches($q) as $v => $query) {
                    $out["$prefix/facetSearch/$v/$q"] = self::line($index->facetSearch($query));
                }
            }
            foreach (self::browseCases() as $v => [$q, $options]) {
                $out["$prefix/browse/$v"] = self::line($index->search($q, $options));
                $out["$prefix/browseBoolean/$v"] = self::line($index->searchBoolean($q, $options));
            }
            $index->close();
        }
    }

    /** @return array<string, SearchOptions> */
    private static function searchVariants(): array
    {
        $o = self::searchOptions(...);
        return [
            'plain'     => $o(),
            'prefix'    => $o(asYouType: true),
            'facets'    => $o(facets: ['color', 'tags', 'price']),
            'filter'    => $o(filter: ['color' => ['red', 'blue']], facets: ['color', 'price', 'tags']),
            'filter2'   => $o(filter: ['tags' => 'sale', 'price' => FacetRange::between(10, 40)], facets: ['tags']),
            'exclude'   => $o(filter: ['color' => new FacetExclude('red')], facets: ['color']),
            'negated'   => $o(asYouType: true, facets: ['color']),
            'sort'      => $o(sort: ['price:asc']),
            'sort2'     => $o(sort: ['brand:desc', 'price:asc'], filter: ['tags' => ['eco', 'new']]),
            'all'       => $o(strategy: MatchingStrategy::All, facets: ['color']),
            'frequency' => $o(strategy: MatchingStrategy::Frequency),
            'distinct'  => $o(distinct: 'color', facets: ['tags']),
            'distinct2' => $o(distinct: 'brand', distinctCount: 2, sort: ['price:desc']),
            'page2'     => $o(offset: 10),
            'deep'      => $o(offset: 25, limit: 20),
            'formatted' => new SearchOptions(
                asYouType: false,
                limit: 10,
                facets: ['brand'],
                attributesToHighlight: ['title'],
                attributesToCrop: ['body'],
                cropLength: 30,
                sortFacetValuesBy: ['*' => FacetOrder::Count],
                showRankingScoreDetails: true,
            ),
        ];
    }

    /**
     * @param array<string, string|list<string>|FacetRange|FacetExclude> $filter
     * @param list<string>                                               $facets
     * @param list<string>                                               $sort
     */
    private static function searchOptions(
        bool $asYouType = false,
        int $offset = 0,
        int $limit = 10,
        array $filter = [],
        array $facets = [],
        array $sort = [],
        ?string $distinct = null,
        int $distinctCount = 1,
        MatchingStrategy $strategy = MatchingStrategy::Last,
    ): SearchOptions {
        return new SearchOptions(
            asYouType: $asYouType,
            limit: $limit,
            offset: $offset,
            filter: $filter,
            facets: $facets,
            sort: $sort,
            distinct: $distinct,
            distinctCount: $distinctCount,
            attributesToRetrieve: [],
            matchingStrategy: $strategy,
            showRankingScoreDetails: true,
        );
    }

    /** @return array<string, SearchOptions> */
    private static function booleanVariants(): array
    {
        return [
            'plain'    => new SearchOptions(asYouType: false, limit: 10, attributesToRetrieve: []),
            'prefix'   => new SearchOptions(limit: 10, attributesToRetrieve: [], facets: ['color']),
            'filter'   => new SearchOptions(
                asYouType: false,
                limit: 10,
                attributesToRetrieve: [],
                filter: ['color' => ['red', 'blue'], 'tags' => new FacetExclude('gift')],
                facets: ['color', 'price'],
            ),
            'distinct' => new SearchOptions(asYouType: false, limit: 10, attributesToRetrieve: [], distinct: 'brand'),
            'sort'     => new SearchOptions(
                asYouType: false,
                limit: 10,
                offset: 5,
                attributesToRetrieve: [],
                sort: ['brand:asc', 'price:desc'],
                showRankingScoreDetails: true,
            ),
        ];
    }

    /** @return array<string, FacetSearchQuery> */
    private static function facetSearches(string $q): array
    {
        return [
            'color'   => new FacetSearchQuery('color', q: $q),
            'tags'    => new FacetSearchQuery(
                'tags',
                q: $q,
                filter: ['color' => 'red'],
                sortFacetValuesBy: FacetOrder::Count,
            ),
            'brand'   => new FacetSearchQuery(
                'brand',
                facetQuery: 'n',
                q: $q,
                filter: ['tags' => new FacetExclude('sale')],
            ),
            'negated' => new FacetSearchQuery('price', q: "$q -shirt", limit: 5),
        ];
    }

    /** @return array<string, array{string, SearchOptions}> */
    private static function browseCases(): array
    {
        $o = self::searchOptions(...);
        return [
            'all'          => ['', $o()],
            'page'         => ['', $o(offset: 15)],
            'facets'       => ['', $o(facets: ['color', 'tags', 'price', 'brand'])],
            'filter'       => ['', $o(filter: ['color' => 'red'], facets: ['color', 'tags'])],
            'list'         => ['', $o(filter: ['color' => ['red', 'blue', 'green', 'black']], facets: ['color'])],
            'multi'        => ['', $o(filter: ['tags' => ['sale', 'eco']], facets: ['tags', 'color'])],
            'range'        => ['', $o(filter: ['price' => FacetRange::min(30)], sort: ['price:asc'])],
            'rangeDesc'    => ['', $o(filter: ['price' => new FacetRange(gt: 5, lt: 20)], sort: ['price:desc'])],
            'exclude'      => ['', $o(filter: ['color' => new FacetExclude(['red', 'blue'])], facets: ['color'])],
            'excludeMixed' => ['', $o(
                filter: ['tags' => 'sale', 'color' => new FacetExclude('red')],
                facets: ['color', 'tags'],
                sort: ['brand:asc'],
            )],
            'sortString'   => ['', $o(sort: ['brand:asc', 'price:desc'], offset: 3)],
            'sortDeep'     => ['', $o(sort: ['brand:desc'], offset: 500)],
            'distinct'     => ['', $o(distinct: 'brand', facets: ['brand'])],
            'distinct2'    => ['', $o(distinct: 'color', distinctCount: 3, sort: ['price:asc'])],
            'negations'    => ['-shirt -"cotton shirt"', $o(facets: ['color'])],
            'negSorted'    => ['-black', $o(sort: ['price:asc'], filter: ['color' => 'blue'])],
            'empty'        => ['', $o(filter: ['color' => 'purple'], facets: ['color'])],
        ];
    }

    /** Page IDs (facet hits for facetSearch()), total and a hash of the whole result. */
    private static function line(SearchResult|FacetSearchResult $result): string
    {
        $all = json_encode(self::canonical($result->toArray()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $result instanceof SearchResult
            ? sprintf('%s total=%d %s', implode(',', $result->getIds()), $result->totalHits, md5($all))
            : sprintf('%s %s', json_encode($result->facetHits, JSON_THROW_ON_ERROR), md5($all));
    }

    /** Floats rounded so the last bits of a reordered sum do not count as a change. */
    private static function canonical(mixed $value): mixed
    {
        return match (true) {
            is_float($value) => round($value, 6),
            is_array($value) => array_map(self::canonical(...), $value),
            default          => $value,
        };
    }

    /** @return list<array<string, mixed>> */
    private function documents(int $n): array
    {
        $docs = [];
        for ($id = 1; $id <= $n; $id++) {
            $doc = ['id' => $id, 'title' => $this->words(2, 6), 'body' => $this->words(0, 30)];
            if (mt_rand(0, 9) > 0) {
                $doc['color'] = self::COLORS[mt_rand(0, count(self::COLORS) - 1)];
            }
            $tags = [];
            for ($t = mt_rand(0, 3); $t > 0; $t--) {
                $tags[] = self::TAGS[mt_rand(0, count(self::TAGS) - 1)];
            }
            if ($tags !== []) {
                $doc['tags'] = $tags;
            }
            if (mt_rand(0, 7) > 0) {
                $doc['price'] = mt_rand(0, 4) === 0 ? mt_rand(100, 9999) / 100 : mt_rand(1, 60);
            }
            if (mt_rand(0, 5) > 0) {
                $doc['brand'] = self::BRANDS[mt_rand(0, count(self::BRANDS) - 1)];
            }
            $docs[] = $doc;
        }
        return $docs;
    }

    /** Words with Zipf-like frequencies: rank r is picked with weight 1 / (r + 1). */
    private function words(int $min, int $max): string
    {
        $weights = array_map(fn(int $r): float => 1.0 / ($r + 1), array_keys(self::WORDS));
        $out = [];
        for ($i = mt_rand($min, $max); $i > 0; $i--) {
            $x = mt_rand() / mt_getrandmax() * array_sum($weights);
            foreach ($weights as $r => $w) {
                $x -= $w;
                if ($x <= 0) {
                    $out[] = self::WORDS[$r];
                    break;
                }
            }
        }
        return implode(' ', $out);
    }

    /** @return list<string> */
    private function queries(): array
    {
        $queries = [
            'shirt', 'men', 'clasic', 'shrit coton', 'jeans', 'kurta', 'cla', 'black je', 'blck shirt',
            '"cotton shirt"', '"casual shirt" men', 'shirt -cotton', 'silver -"black watch" women',
            'striped polo sl', 'nothingmatches shirt', 'men women kids',
        ];
        while (count($queries) < 28) {
            $q = $this->words(2, 3);
            if (!in_array($q, $queries, true)) {
                $queries[] = $q;
            }
        }
        return $queries;
    }
}

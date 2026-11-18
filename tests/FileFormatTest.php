<?php

namespace Fuzor\Tests;

use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Tests\Support\PluralStemmer;
use PHPUnit\Framework\TestCase;

/**
 * Keeps index files of revision 4 readable, with the same results.
 *
 * tests/fixtures/index-rev4.db is an index file as Fuzor 2.0 writes it (a stemmer, stop words,
 * stripHtml, synonyms, filterable, sortable and searchable fields, n-gram scripts, pending
 * tombstones and rewritten postings). The tests open a copy of it with the current code and
 * compare every read with an index built now from the same documents and the same writes: a
 * change to how text is indexed, or to the file format, makes them differ.
 *
 * Every 2.x release must pass this test against the fixture written for 2.0.0. A change that
 * fails it needs a new schema revision that keeps reading revision 4 files, or waits for 3.0;
 * it must not regenerate the fixture. Before 2.0.0 is tagged, a change to the revision 4 format
 * regenerates it (FUZOR_FORMAT_UPDATE=1 vendor/bin/phpunit --filter FileFormatTest).
 */
class FileFormatTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/index-rev4.db';

    private const array WORDS = [
        'shirt', 'shirts', 'cotton', 'classic', 'boxes', 'box', 'batteries', 'battery', 'leather',
        'boots', 'boot', 'glasses', 'class', 'summer', 'winter', 'running', 'striped', 'women',
        'men', 'black', 'white', 'blue', 'dress', 'dresses', 'watch', 'watches', 'jacket', 'jeans',
        'denim', 'linen', 'the', 'and', 'of', 'café', 'naïve', 'İstanbul',
    ];

    private const array SCRIPTS = ['東京タワー', '서울 지하철', 'ภาษาไทย', 'हिन्दी किताब', 'كتاب عربي'];
    private const array COLORS = ['red', 'blue', 'green', 'black'];
    private const array TAGS = ['sale', 'new', 'eco', 'gift'];
    private const array BRANDS = ['Northwind', 'northwind', 'Acme', 'Éclair', 'Zephyr'];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            foreach ([$path, ...(glob($path . '[.-]*') ?: [])] as $f) {
                if (is_link($f) || file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    public function testFixtureIsRevisionFour(): void
    {
        $this->writeFixtureOnRequest();
        $this->assertFileExists(self::FIXTURE);
        $this->assertFileDoesNotExist(self::FIXTURE . '-wal');
        $index = new Index($this->copyOfFixture(), readonly: true);
        $this->assertSame(4, $index->schemaVersion);
    }

    public function testOpensWithItsOwnSchema(): void
    {
        $this->writeFixtureOnRequest();
        $index = new Index($this->copyOfFixture(), schema: self::schema());
        $this->assertSame(4, $index->schemaVersion);
        $this->assertSame(PluralStemmer::class, $index->stemmer);
    }

    public function testReadsMatchAFreshIndex(): void
    {
        $this->writeFixtureOnRequest();
        $old   = new Index($this->copyOfFixture(), readonly: true);
        $fresh = $this->build($this->tempPath());
        $this->assertSameReads($fresh, $old);
    }

    public function testWritesMatchAFreshIndex(): void
    {
        $this->writeFixtureOnRequest();
        $old   = new Index($this->copyOfFixture());
        $fresh = $this->build($this->tempPath());
        foreach ([$old, $fresh] as $index) {
            mt_srand(23);
            $index->insert($this->documents(311, 340));
            $index->upsert($this->documents(10, 30));
            $index->upsert([['id' => 3, 'title' => 'the classic boxes', 'brand' => 'Acme', 'price' => 12]]);
            $index->delete(40, 41, 42, 360);
            $index->setSynonyms([['boot', 'jacket']], ['dress' => ['shirt']]);
            $index->deleteByFilter(['color' => 'green', 'tags' => 'gift']);
        }
        $this->assertSameReads($fresh, $old);

        $this->assertSame($fresh->optimize(), $old->optimize());
        $this->assertSameReads($fresh, $old);
    }

    private function assertSameReads(Index $expected, Index $actual): void
    {
        $this->assertSame(count($expected), count($actual));
        $this->assertSame($expected->getSynonyms(), $actual->getSynonyms());
        $this->assertSame(
            iterator_to_array($expected->stream(50), false),
            iterator_to_array($actual->stream(50), false),
        );
        foreach ($this->queries() as $q) {
            foreach ($this->options() as $name => $options) {
                $this->assertSame(
                    self::canonical($expected->search($q, $options)->toArray()),
                    self::canonical($actual->search($q, $options)->toArray()),
                    "search('$q') $name",
                );
                $this->assertSame(
                    self::canonical($expected->searchBoolean("$q | boot", $options)->toArray()),
                    self::canonical($actual->searchBoolean("$q | boot", $options)->toArray()),
                    "searchBoolean('$q | boot') $name",
                );
            }
            $this->assertEquals($expected->inspectQuery($q), $actual->inspectQuery($q), "inspectQuery('$q')");
            foreach (['color', 'tags', 'price'] as $facet) {
                $query = new FacetSearchQuery($facet, q: $q);
                $this->assertSame(
                    self::canonical($expected->facetSearch($query)->toArray()),
                    self::canonical($actual->facetSearch($query)->toArray()),
                    "facetSearch($facet, '$q')",
                );
            }
        }
    }

    /** @return list<string> */
    private function queries(): array
    {
        return [
            '', 'shirt', 'shirts', 'box', 'batterie', 'the class', 'clasic', 'shrit coton', 'boo',
            'jeans', 'dress', 'cafe', 'café', 'naïve', 'istanbul', '東京', 'タワー', '서울', 'ภาษา',
            'हिन्दी', 'كتاب', '"classic cotton"', 'women -black', 'black -"the cotton"', 'paragraph',
            'script', 'leather boots wi', 'nothingmatches',
        ];
    }

    /** @return array<string, SearchOptions> */
    private function options(): array
    {
        $all = ['facets' => ['color', 'tags', 'price', 'brand'], 'limit' => 15];
        return [
            'plain'     => new SearchOptions(...$all, asYouType: false, showRankingScoreDetails: true),
            'prefix'    => new SearchOptions(...$all, showRankingScoreDetails: true),
            'filter'    => new SearchOptions(
                ...$all,
                filter: ['color' => ['red', 'blue'], 'price' => FacetRange::between(5, 60)],
            ),
            'exclude'   => new SearchOptions(...$all, filter: ['tags' => new FacetExclude('sale')]),
            'sort'      => new SearchOptions(...$all, sort: ['brand:asc', 'price:desc'], offset: 3),
            'distinct'  => new SearchOptions(...$all, distinct: 'color'),
            'formatted' => new SearchOptions(
                limit: 10,
                attributesToHighlight: ['title', 'body'],
                attributesToCrop: ['body'],
                cropLength: 12,
                escapeFormatted: true,
            ),
        ];
    }

    private static function schema(): SchemaConfig
    {
        return new SchemaConfig(
            stemmer: PluralStemmer::class,
            filterableAttributes: ['color', 'tags', 'price', 'brand'],
            sortableAttributes: ['price', 'brand'],
            searchableAttributes: ['title', 'body', 'brand'],
            stripHtml: true,
            stopWords: ['the', 'and', 'of'],
        );
    }

    /** The fixture's documents and writes, on a new index at $path. */
    private function build(string $path): Index
    {
        mt_srand(17);
        $index = new Index($path, schema: self::schema());
        $index->insert($this->documents(1, 300));
        $index->setSynonyms([['jeans', 'denim']], ['watch' => ['watches', 'box']]);
        $index->update($this->documents(50, 70));
        $index->upsert($this->documents(295, 310));
        // 12 of 310: pending tombstones, under the purge ratio.
        $index->delete(...range(5, 300, 25));
        return $index;
    }

    /** @return list<array<string, mixed>> */
    private function documents(int $from, int $to): array
    {
        $docs = [];
        for ($id = $from; $id <= $to; $id++) {
            $doc = ['id' => $id, 'title' => $this->words(2, 5), 'body' => $this->html()];
            if (mt_rand(0, 4) > 0) {
                $doc['color'] = self::COLORS[mt_rand(0, count(self::COLORS) - 1)];
            }
            $tags = [];
            for ($t = mt_rand(0, 3); $t > 0; $t--) {
                $tags[] = self::TAGS[mt_rand(0, count(self::TAGS) - 1)];
            }
            if ($tags !== []) {
                $doc['tags'] = $tags;
            }
            if (mt_rand(0, 5) > 0) {
                $doc['price'] = mt_rand(0, 3) === 0 ? mt_rand(100, 9999) / 100 : mt_rand(1, 80);
            }
            if (mt_rand(0, 3) > 0) {
                $doc['brand'] = self::BRANDS[mt_rand(0, count(self::BRANDS) - 1)];
            }
            $docs[] = $doc;
        }
        return $docs;
    }

    private function html(): string
    {
        $body = '<p>' . $this->words(0, 12) . '</p>';
        if (mt_rand(0, 2) === 0) {
            $body .= '<ul><li>' . $this->words(1, 3) . '</li><li>' . $this->words(1, 3) . '</li></ul>';
        }
        if (mt_rand(0, 4) === 0) {
            $body .= '<script>var paragraph = 1;</script>' . self::SCRIPTS[mt_rand(0, count(self::SCRIPTS) - 1)];
        }
        if (mt_rand(0, 5) === 0) {
            $body .= ' &amp; caf&eacute;<br>sum&shy;mer';
        }
        return $body;
    }

    private function words(int $min, int $max): string
    {
        $out = [];
        for ($i = mt_rand($min, $max); $i > 0; $i--) {
            // Zipf-like: low ranks more often.
            $out[] = self::WORDS[intdiv(mt_rand(0, count(self::WORDS) - 1) * mt_rand(0, 100), 100)];
        }
        return implode(' ', $out);
    }

    /** Floats rounded so the last bits of a reordered sum do not count as a change. */
    private static function canonical(mixed $value): mixed
    {
        return match (true) {
            is_float($value) => round($value, 9),
            is_array($value) => array_map(self::canonical(...), $value),
            default          => $value,
        };
    }

    private function tempPath(): string
    {
        return $this->paths[] = sys_get_temp_dir() . '/fuzor_format_' . uniqid() . '.db';
    }

    private function copyOfFixture(): string
    {
        $path = $this->tempPath();
        $this->assertTrue(copy(self::FIXTURE, $path));
        return $path;
    }

    private function writeFixtureOnRequest(): void
    {
        if (!getenv('FUZOR_FORMAT_UPDATE')) {
            return;
        }
        $path  = $this->tempPath();
        $index = $this->build($path);
        $index->checkpoint('TRUNCATE');
        $index->close();
        @mkdir(dirname(self::FIXTURE));
        $this->assertTrue(copy($path, self::FIXTURE));
        $this->markTestSkipped('fixture written: ' . filesize(self::FIXTURE) . ' bytes');
    }
}

<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Exceptions\QueryException;
use Fuzor\Stemmer;
use Fuzor\Tests\Support\EnglishStemmer;
use Fuzor\Tests\Support\PluralStemmer;
use Fuzor\Tests\Support\StemmerWithArgument;
use Fuzor\Tests\Support\SwitchableStemmer;
use PHPUnit\Framework\TestCase;

class StemmerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/fuzor_test_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    private static function schema(): SchemaConfig
    {
        return new SchemaConfig(stemmer: EnglishStemmer::class);
    }

    // --- The test stemmer ---

    public function testEnglishStemmerReducesToRoot(): void
    {
        $s = new EnglishStemmer();
        $this->assertSame('run', $s->stem('running'));
        $this->assertSame('connect', $s->stem('connection'));
        $this->assertSame('connect', $s->stem('connections'));
        $this->assertSame('connect', $s->stem('connected'));
        $this->assertSame($s->stem('connect'), $s->stem($s->stem('connect')));
    }

    // --- The docs example ---

    public function testDocsExampleStemmerMatchesSingularAndPlural(): void
    {
        $s = new PluralStemmer();
        $this->assertSame(
            ['battery', 'box', 'glass', 'boot', 'shoe', 'class', 'bus', 'boot'],
            array_map($s->stem(...), ['batteries', 'boxes', 'glasses', 'boots', 'shoes', 'class', 'bus', 'boot']),
        );

        $index = new Index($this->dbPath, schema: new SchemaConfig(stemmer: PluralStemmer::class));
        $index->insert([['id' => 1, 'body' => 'Spare batteries'], ['id' => 2, 'body' => 'Hiking boots']]);
        $this->assertSame([1], $index->search('battery', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([2], $index->search('boot', new SearchOptions(asYouType: false))->getIds());
        // A light stemmer leaves the start of a word alone, so a word being typed still finds it.
        $this->assertSame([2], $index->search('hikin')->getIds());
    }

    public function testDocsExampleIsTheTestedClass(): void
    {
        $class = static function (string $file): string {
            preg_match('/^final class PluralStemmer.*?^}/ms', (string) file_get_contents($file), $m);
            return $m[0] ?? '';
        };
        $docs = $class(dirname(__DIR__) . '/docs/language.md');
        $this->assertNotSame('', $docs);
        $this->assertSame($class(__DIR__ . '/Support/PluralStemmer.php'), $docs);
    }

    // --- Schema ---

    public function testIndexStoresTheStemmerClassAndReadsItBack(): void
    {
        $index = new Index($this->dbPath, schema: self::schema());
        $this->assertSame(EnglishStemmer::class, $index->stemmer);
        $index->close();

        $reopened = new Index($this->dbPath, readonly: true);
        $this->assertSame(EnglishStemmer::class, $reopened->stemmer);
        $this->assertFalse($reopened->stemmerChanged);
        $this->assertTrue($reopened->inspectQuery('running')->stemmerActive);
    }

    public function testIndexWithoutStemmerHasNone(): void
    {
        $index = new Index($this->dbPath);
        $this->assertNull($index->stemmer);
        $this->assertFalse($index->inspectQuery('running')->stemmerActive);
    }

    public function testUnknownStemmerClassThrowsBeforeTheFileIsCreated(): void
    {
        try {
            // @phpstan-ignore argument.type (the point of the test)
            new Index($this->dbPath, schema: new SchemaConfig(stemmer: 'Fuzor\\Tests\\NoSuchStemmer'));
            $this->fail('Expected a QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NoSuchStemmer', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dbPath);
    }

    public function testClassNotImplementingStemmerThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(Stemmer::class);
        // @phpstan-ignore argument.type (the point of the test)
        new Index($this->dbPath, schema: new SchemaConfig(stemmer: \stdClass::class));
    }

    public function testStemmerWithRequiredConstructorArgumentThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('constructor');
        new Index($this->dbPath, schema: new SchemaConfig(stemmer: StemmerWithArgument::class));
    }

    public function testOpeningAnIndexWhoseStemmerClassIsMissingThrows(): void
    {
        new Index($this->dbPath, schema: self::schema())->close();
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec("UPDATE info SET value = 'Vendor\\Gone\\Stemmer' WHERE key = 'stemmer'");
        $pdo = null;

        try {
            new Index($this->dbPath);
            $this->fail('Expected a QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Vendor\\Gone\\Stemmer', $e->getMessage());
            $this->assertStringContainsString('rebuild()', $e->getMessage());
        }
    }

    public function testChangedStemmerIdWarnsUntilRebuild(): void
    {
        SwitchableStemmer::$id = 'switchable@1';
        try {
            $index = new Index($this->dbPath, schema: new SchemaConfig(stemmer: SwitchableStemmer::class));
            $index->insert([['id' => 1, 'body' => 'red boots']]);
            $this->assertSame([], $index->search('boot')->warnings);
            $index->close();

            SwitchableStemmer::$id = 'switchable@2';
            $index = new Index($this->dbPath);
            $this->assertTrue($index->stemmerChanged);
            $result = $index->search('boots');
            $this->assertSame([1], $result->getIds(), 'the index still searches');
            $this->assertCount(1, $result->warnings);
            $this->assertStringContainsString('switchable@1', $result->warnings[0]);
            $this->assertStringContainsString('switchable@2', $result->warnings[0]);
            $this->assertNotSame([], $index->searchBoolean('boots')->warnings);
            $index->close();

            Index::rebuild($this->dbPath);
            $index = new Index($this->dbPath);
            $this->assertFalse($index->stemmerChanged);
            $this->assertSame([], $index->search('boots')->warnings);
            $this->assertSame(SwitchableStemmer::class, $index->stemmer);
            $index->close();
        } finally {
            SwitchableStemmer::$id = 'switchable@1';
            Index::remove($this->dbPath);
        }
    }

    public function testHighlighterAndSnippeterUseTheIndexStemmer(): void
    {
        $index = new Index($this->dbPath, schema: self::schema());
        $text  = 'We ran a connection test.';
        $this->assertStringContainsString(
            'connection',
            $index->snippeter(windowSize: 12)->snippet('connected', $text),
        );
        $this->assertSame(
            ['b' => 'We ran a <mark>connection</mark> test.'],
            $index->highlighter()->highlightMany('connected', ['b' => $text], ['connect']),
        );
    }

    // --- Index integration ---

    public function testStemmedFormMatchesSurfaceFormAtQuery(): void
    {
        // Index "running"; search "run" (the stem) — with asYouType off so it's exact.
        $index = new Index($this->dbPath, schema: self::schema());
        $index->insert([['id' => 1, 'body' => 'running quickly']]);

        $this->assertContains(1, $index->search('run', new SearchOptions(asYouType: false))->getIds());
    }

    public function testDifferentSurfaceFormsMatchSameStem(): void
    {
        $index = new Index($this->dbPath, schema: self::schema());
        $index->insert([['id' => 1, 'body' => 'connection to the server']]);

        // Both "connect" and "connections" stem to "connect"
        $this->assertContains(1, $index->search('connect', new SearchOptions(asYouType: false))->getIds());
        $this->assertContains(1, $index->search('connections', new SearchOptions(asYouType: false))->getIds());
    }

    public function testWithoutStemmerNoStemming(): void
    {
        // Without a stemmer, exact tokens are stored — "run" must not match "running".
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'running quickly']]);

        $this->assertSame([], $index->search('run', new SearchOptions(asYouType: false))->getIds());
        $this->assertContains(1, $index->search('running', new SearchOptions(asYouType: false))->getIds());
    }

    public function testBooleanSearchAppliesStemming(): void
    {
        $index = new Index($this->dbPath, schema: self::schema());
        $index->insert([['id' => 1, 'body' => 'connections to the network']]);
        $index->insert([['id' => 2, 'body' => 'network errors only']]);

        // "connected" stems to "connect", same as "connections" — doc 1 must match
        $result = $index->searchBoolean('connected');
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
    }

    public function testInsertManyWithStemming(): void
    {
        $index = new Index($this->dbPath, schema: self::schema());
        $index->insert([
            ['id' => 1, 'body' => 'connections are important'],
            ['id' => 2, 'body' => 'running fast'],
        ]);

        $this->assertContains(1, $index->search('connect', new SearchOptions(asYouType: false))->getIds());
        $this->assertContains(2, $index->search('run', new SearchOptions(asYouType: false))->getIds());
    }
}

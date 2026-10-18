<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Exceptions\QueryException;
use Fuzor\Stopwords;
use PHPUnit\Framework\TestCase;

class StopwordsTest extends TestCase
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

    // --- Stopwords class ---

    public function testFilterRemovesListedWords(): void
    {
        $sw = new Stopwords(['the', 'a']);
        $this->assertSame(['quick', 'brown', 'fox'], $sw->filter(['the', 'quick', 'a', 'brown', 'fox']));
    }

    public function testFilterReindexesResult(): void
    {
        $result = new Stopwords(['the'])->filter(['the', 'cat']);
        $this->assertSame(['cat'], $result);
    }

    public function testFilterEmptyArray(): void
    {
        $this->assertSame([], new Stopwords(['the'])->filter([]));
    }

    // --- Index integration ---

    public function testDefaultLanguageIsNull(): void
    {
        $index = new Index($this->dbPath);
        $this->assertNull($index->language);
    }

    public function testLanguageAtCreationIsReadBack(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $this->assertSame('en', $index->language);
    }

    public function testLanguageIsRestoredOnOpen(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $index->close();

        $reopened = new Index($this->dbPath);
        $this->assertSame('en', $reopened->language);
    }

    public function testUnknownLanguageAtCreationThrows(): void
    {
        $this->expectException(QueryException::class);
        new Index($this->dbPath, schema: new SchemaConfig(language: 'xx'));
    }

    public function testLanguageAloneFiltersNoWords(): void
    {
        // Like Meilisearch: no stop words unless the schema lists them. "c" and "men" are
        // product words ("usb c cable", "men shirt").
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $index->insert([['id' => 1, 'body' => 'the usb c cable for men']]);

        $this->assertSame([], $index->stopWords);
        $this->assertSame([1], $index->search('the')->getIds());
        $this->assertSame([1], $index->search('men', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame(['usb', 'c', 'cabl'], $index->inspectQuery('usb c cable')->filteredTokens);
    }

    public function testListedStopWordsAreLeftOutOfIndexAndQueries(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en', stopWords: ['The', 'of']));
        $index->insert([['id' => 1, 'body' => 'the quick brown fox'], ['id' => 2, 'body' => 'a bag of the fox']]);

        $this->assertSame(['the', 'of'], $index->stopWords);
        $this->assertSame([], $index->search('the')->getIds());
        // Left out of the query too, so "the fox" matches both documents fully.
        $this->assertSame(['fox'], $index->inspectQuery('the fox')->filteredTokens);
        $this->assertEqualsCanonicalizing([1, 2], $index->search('the fox')->getIds());
        $this->assertTrue($index->inspectQuery('the fox')->stopwordsActive);
    }

    public function testStopWordsAreRestoredOnOpenAndKeptByRebuild(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(stopWords: ['the']));
        $index->insert([['id' => 1, 'body' => 'the fox']]);
        $index->close();

        $this->assertSame(['the'], new Index($this->dbPath)->stopWords);
        Index::rebuild($this->dbPath);
        $rebuilt = new Index($this->dbPath);
        $this->assertSame(['the'], $rebuilt->stopWords);
        $this->assertSame([], $rebuilt->search('the')->getIds());
    }

    public function testNumericStopWordStaysAString(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(stopWords: ['2', '2']));
        $this->assertSame(['2'], $index->stopWords);
    }

    public function testStopWordOfSeveralWordsThrowsBeforeCreatingTheFile(): void
    {
        try {
            new Index($this->dbPath, schema: new SchemaConfig(stopWords: ['t-shirt']));
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('t-shirt', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dbPath);
    }

    public function testWithoutStopWordsEveryWordIsIndexed(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'the quick brown fox']]);

        $this->assertContains(1, $index->search('the')->getIds());
    }

    public function testAllStopwordQueryDoesNotCrash(): void
    {
        // A query made up entirely of stop words must not crash or throw; the fallback
        // in filterQueryTokens re-enables the original tokens when all are stripped.
        $index = new Index($this->dbPath, schema: new SchemaConfig(stopWords: ['the', 'and', 'or']));
        $index->insert([['id' => 1, 'body' => 'quick brown fox']]);

        $result = $index->search('the and or');
        // never indexed, so no matches
        $this->assertSame([], $result->getIds());
    }
}

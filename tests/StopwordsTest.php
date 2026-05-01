<?php

namespace Fuzor\Tests;

use Fuzor\Index;
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

    // --- Stopwords::supports ---

    public function testSupportsReturnsTrueForKnownLanguage(): void
    {
        $this->assertTrue(Stopwords::supports('en'));
        $this->assertTrue(Stopwords::supports('fr'));
    }

    public function testSupportsReturnsFalseForUnknownLanguage(): void
    {
        $this->assertFalse(Stopwords::supports('xx'));
        $this->assertFalse(Stopwords::supports('ne')); // stemmer-only
    }

    // --- Stopwords class ---

    public function testFilterRemovesEnglishStopwords(): void
    {
        $sw = new Stopwords('en');
        $this->assertSame(['quick', 'brown', 'fox'], $sw->filter(['the', 'quick', 'brown', 'fox']));
    }

    public function testFilterPreservesNonStopwords(): void
    {
        $sw = new Stopwords('en');
        $this->assertSame(['elephant', 'jazz', 'cryptography'], $sw->filter(['elephant', 'jazz', 'cryptography']));
    }

    public function testFilterReindexesResult(): void
    {
        $sw     = new Stopwords('en');
        $result = $sw->filter(['the', 'cat']);
        $this->assertArrayHasKey(0, $result);
        $this->assertSame('cat', $result[0]);
    }

    public function testFilterEmptyArray(): void
    {
        $sw = new Stopwords('en');
        $this->assertSame([], $sw->filter([]));
    }

    public function testFilterAllStopwords(): void
    {
        $sw = new Stopwords('en');
        $this->assertSame([], $sw->filter(['the', 'and', 'or', 'a', 'is']));
    }

    public function testDifferentLanguage(): void
    {
        $sw     = new Stopwords('fr');
        $result = $sw->filter(['le', 'renard', 'brun', 'rapide']);
        $this->assertNotContains('le', $result);
        $this->assertContains('renard', $result);
    }

    public function testUnknownLanguageThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Stopwords('xx');
    }

    // --- Index integration ---

    public function testDefaultLanguageIsNull(): void
    {
        $index = Index::create($this->dbPath);
        $this->assertNull($index->language);
    }

    public function testLanguageAtCreationIsReadBack(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $this->assertSame('en', $index->language);
    }

    public function testLanguageIsRestoredOnOpen(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $index->close();

        $reopened = Index::open($this->dbPath);
        $this->assertSame('en', $reopened->language);
    }

    public function testUnknownLanguageAtCreationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Index::create($this->dbPath, language: 'xx');
    }

    public function testStopwordsExcludedFromIndex(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $index->insert(['id' => 1, 'body' => 'the quick brown fox']);

        // 'the' is a stopword — searching for it should return no results
        $this->assertSame([], $index->search('the')['ids']);

        // content terms should still be findable
        $this->assertContains(1, $index->search('fox')['ids']);
    }

    public function testWithoutLanguageStopwordsAreIndexed(): void
    {
        $index = Index::create($this->dbPath);
        // no language set — 'the' is indexed normally
        $index->insert(['id' => 1, 'body' => 'the quick brown fox']);

        $this->assertContains(1, $index->search('the')['ids']);
    }

    public function testAllStopwordQueryDoesNotCrash(): void
    {
        // With language='en', stopwords are filtered from both inserts and queries.
        // A query made up entirely of stopwords must not crash or throw; the fallback
        // in filterQueryTokens re-enables the original tokens when all are stripped.
        $index = Index::create($this->dbPath, language: 'en');
        $index->insert(['id' => 1, 'body' => 'quick brown fox']);

        $result = $index->search('the and or');
        // 'the', 'and', 'or' are stopwords and were never indexed — no matches expected
        $this->assertSame([], $result['ids']);
    }
}

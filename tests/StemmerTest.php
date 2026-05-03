<?php

namespace Fuzor\Tests;

use Fuzor\Index;
use Fuzor\Stemmer;
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

    // --- Stemmer::supports ---

    public function testSupportsReturnsTrueForKnownLanguage(): void
    {
        $this->assertTrue(Stemmer::supports('en'));
        $this->assertTrue(Stemmer::supports('fr'));
        $this->assertTrue(Stemmer::supports('de'));
    }

    public function testSupportsReturnsFalseForUnknownLanguage(): void
    {
        $this->assertFalse(Stemmer::supports('zh'));
        $this->assertFalse(Stemmer::supports('xx'));
    }

    // --- Stemmer::stemToken ---

    public function testStemTokenEnglishReducesToRoot(): void
    {
        $s = new Stemmer('en');
        $this->assertSame('run', $s->stemToken('running'));
        $this->assertSame('connect', $s->stemToken('connection'));
        $this->assertSame('connect', $s->stemToken('connections'));
        $this->assertSame('connect', $s->stemToken('connected'));
    }

    public function testStemTokenFrenchReducesToRoot(): void
    {
        $s = new Stemmer('fr');
        $this->assertSame('chant', $s->stemToken('chanter'));
        $this->assertSame('chant', $s->stemToken('chantait'));
    }

    public function testStemTokenAlreadyStemmedWordIsIdempotent(): void
    {
        $s = new Stemmer('en');
        $stem = $s->stemToken('connect');
        $this->assertSame($stem, $s->stemToken($stem));
    }

    public function testConstructorThrowsForUnsupportedLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Stemmer('zh');
    }

    // --- Stemmer::stemTokens ---

    public function testStemTokensAppliesStemmingToEachToken(): void
    {
        $s = new Stemmer('en');
        $this->assertSame(
            ['run', 'connect', 'generous'],
            $s->stemTokens(['running', 'connections', 'generously'])
        );
    }

    public function testStemTokensEmptyArrayReturnsEmpty(): void
    {
        $s = new Stemmer('en');
        $this->assertSame([], $s->stemTokens([]));
    }

    // --- Index integration ---

    public function testStemmedFormMatchesSurfaceFormAtQuery(): void
    {
        // Index "running"; search "run" (the stem) — with asYouType off so it's exact.
        $index = new Index($this->dbPath, language: 'en');
        $index->insert(['id' => 1, 'body' => 'running quickly']);

        $this->assertContains(1, $index->search('run', asYouType: false)['ids']);
    }

    public function testDifferentSurfaceFormsMatchSameStem(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $index->insert(['id' => 1, 'body' => 'connection to the server']);

        // Both "connect" and "connections" stem to "connect"
        $this->assertContains(1, $index->search('connect', asYouType: false)['ids']);
        $this->assertContains(1, $index->search('connections', asYouType: false)['ids']);
    }

    public function testWithoutLanguageNoStemming(): void
    {
        // Without a language set, exact tokens are stored — "run" must not match "running".
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'running quickly']);

        $this->assertSame([], $index->search('run', asYouType: false)['ids']);
        $this->assertContains(1, $index->search('running', asYouType: false)['ids']);
    }

    public function testBooleanSearchAppliesStemming(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $index->insert(['id' => 1, 'body' => 'connections to the network']);
        $index->insert(['id' => 2, 'body' => 'network errors only']);

        // "connected" stems to "connect", same as "connections" — doc 1 must match
        $result = $index->searchBoolean('connected');
        $this->assertContains(1, $result['ids']);
        $this->assertNotContains(2, $result['ids']);
    }

    public function testLanguageWithNoStemmerIndexesExactTokens(): void
    {
        // Without a language set, tokens are stored as-is — "run" must not match "running".
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'running quickly']);

        // stemming disabled — "run" should not match "running"
        $this->assertSame([], $index->search('run', asYouType: false)['ids']);
    }

    public function testInsertManyWithStemming(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $index->insertMany([
            ['id' => 1, 'body' => 'connections are important'],
            ['id' => 2, 'body' => 'running fast'],
        ]);

        $this->assertContains(1, $index->search('connect', asYouType: false)['ids']);
        $this->assertContains(2, $index->search('run', asYouType: false)['ids']);
    }
}

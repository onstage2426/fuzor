<?php

namespace Fuzor\Tests;

use Fuzor\Index;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
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

    // --- Factory ---

    public function testCreateReturnsIndexInstance(): void
    {
        $index = Index::create($this->dbPath);
        $this->assertInstanceOf(Index::class, $index);
    }

    public function testOpenReturnsIndexInstance(): void
    {
        $idx = Index::create($this->dbPath);
        unset($idx);
        gc_collect_cycles();

        $index = Index::open($this->dbPath);
        $this->assertInstanceOf(Index::class, $index);
    }

    public function testCloseReleasesConnection(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->close();

        // Connection released — a second open on the same file must succeed.
        $reopened = Index::open($this->dbPath);
        $this->assertContains(1, $reopened->search('sedan')['ids']);
    }

    public function testOpenNonexistentPathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Index::open('/nonexistent/fuzor_no_such.db');
    }

    public function testCreateInNonexistentDirectoryThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Index::create('/nonexistent/dir/index.db');
    }

    public function testCreateOnExistingFileThrows(): void
    {
        Index::create($this->dbPath);

        $this->expectException(\RuntimeException::class);
        Index::create($this->dbPath);
    }

    public function testCreateWithForceOverwritesExistingFile(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        unset($index);

        $fresh = Index::create($this->dbPath, force: true);
        $this->assertSame([], $fresh->search('sedan')['ids']);
    }

    // --- insert / search ---

    public function testInsertWithoutIdThrows(): void
    {
        $index = Index::create($this->dbPath);
        $this->expectException(\InvalidArgumentException::class);
        $index->insert(['title' => 'no id here']);
    }

    public function testInsertDuplicateIdThrows(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->expectException(\RuntimeException::class);
        $index->insert(['id' => 1, 'title' => 'duplicate']);
    }

    public function testInsertManyWithMissingIdThrows(): void
    {
        $index = Index::create($this->dbPath);
        $this->expectException(\InvalidArgumentException::class);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['title' => 'no id'],
        ]);
    }

    public function testInsertManyWithDuplicateInputIdsThrows(): void
    {
        $index = Index::create($this->dbPath);
        $this->expectException(\InvalidArgumentException::class);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 1, 'title' => 'duplicate in same batch'],
        ]);
    }

    public function testInsertManyWithExistingIdThrows(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->expectException(\RuntimeException::class);
        $index->insertMany([['id' => 1, 'title' => 'already exists']]);
    }

    public function testUpdateWithoutIdThrows(): void
    {
        $index = Index::create($this->dbPath);
        $this->expectException(\InvalidArgumentException::class);
        $index->update(['title' => 'no id here']);
    }

    public function testInsertAndSearchReturnsMatchingId(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'fast sedan', 'body' => 'comfortable city car']);

        $result = $index->search('sedan');
        $this->assertContains(1, $result['ids']);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car', 'body' => '']);

        $result = $index->search('helicopter');
        $this->assertSame([], $result['ids']);
        $this->assertSame(0, $result['hits']);
    }

    public function testSearchReturnsBm25Scores(): void
    {
        $index = Index::create($this->dbPath);
        // Two docs so idf = log(2/1) > 0; a single-doc corpus gives idf = log(1/1) = 0.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car', 'body' => ''],
            ['id' => 2, 'title' => 'suv truck', 'body' => ''],
        ]);

        $result = $index->search('sedan');
        $this->assertArrayHasKey('docScores', $result);
        $this->assertArrayHasKey(1, $result['docScores']);
        $this->assertGreaterThan(0.0, $result['docScores'][1]);
    }

    public function testSearchHitsCountsAllMatchingDocs(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan', 'body' => ''],
            ['id' => 2, 'title' => 'sedan', 'body' => ''],
            ['id' => 3, 'title' => 'coupe', 'body' => ''],
        ]);

        $result = $index->search('sedan');
        $this->assertSame(2, $result['hits']);
    }

    public function testSearchRespectsNumOfResults(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan', 'body' => ''],
            ['id' => 2, 'title' => 'sedan', 'body' => ''],
            ['id' => 3, 'title' => 'sedan', 'body' => ''],
        ]);

        $result = $index->search('sedan', 2);
        $this->assertCount(2, $result['ids']);
        $this->assertSame(3, $result['hits']); // total untruncated
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'SEDAN', 'body' => '']);

        $this->assertContains(1, $index->search('sedan')['ids']);
        $this->assertContains(1, $index->search('SEDAN')['ids']);
    }

    // --- insertMany ---

    public function testInsertManyIndexesAllDocuments(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car', 'body' => ''],
            ['id' => 2, 'title' => 'suv truck', 'body' => ''],
        ]);

        $this->assertContains(1, $index->search('sedan')['ids']);
        $this->assertContains(2, $index->search('suv')['ids']);
    }

    public function testInsertManyWithEmptyArrayIsNoop(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([]);

        $this->assertSame(0, $index->search('anything')['hits']);
    }

    // --- update ---

    public function testUpdateReplacesOldContent(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car', 'body' => '']);
        $index->update(['id' => 1, 'title' => 'suv truck', 'body' => '']);

        $this->assertEmpty($index->search('sedan')['ids']);
        $this->assertContains(1, $index->search('suv')['ids']);
    }

    public function testUpdatePreservesTotalDocumentCount(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan', 'body' => ''],
            ['id' => 2, 'title' => 'coupe', 'body' => ''],
        ]);
        $index->update(['id' => 1, 'title' => 'suv', 'body' => '']);

        // Both docs still searchable
        $this->assertContains(1, $index->search('suv')['ids']);
        $this->assertContains(2, $index->search('coupe')['ids']);
    }

    // --- delete ---

    public function testDeleteRemovesDocumentFromResults(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car', 'body' => '']);
        $index->delete(1);

        $this->assertEmpty($index->search('sedan')['ids']);
    }

    public function testDeleteDoesNotAffectOtherDocuments(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car', 'body' => ''],
            ['id' => 2, 'title' => 'suv truck', 'body' => ''],
        ]);
        $index->delete(1);

        $this->assertContains(2, $index->search('suv')['ids']);
    }

    public function testDeleteNonexistentDocumentIsNoop(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan', 'body' => '']);
        $index->delete(999);

        $this->assertContains(1, $index->search('sedan')['ids']);
    }

    // --- searchFuzzy ---

    public function testSearchFuzzyMatchesTypo(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz', 'body' => 'luxury car']);

        $result = $index->searchFuzzy('mercdes');
        $this->assertContains(1, $result['ids']);
    }

    public function testSearchFuzzyReturnsDocScores(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Volkswagen Golf', 'body' => '']);

        $result = $index->searchFuzzy('volksagen');
        $this->assertArrayHasKey('docScores', $result);
    }

    // --- searchBoolean ---

    public function testSearchBooleanAnd(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan coupe', 'body' => ''],
            ['id' => 2, 'title' => 'sedan only', 'body' => ''],
            ['id' => 3, 'title' => 'coupe only', 'body' => ''],
        ]);

        $result = $index->searchBoolean('sedan coupe');
        $this->assertSame([1], $result['ids']);
    }

    public function testSearchBooleanOr(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car', 'body' => ''],
            ['id' => 2, 'title' => 'suv truck', 'body' => ''],
            ['id' => 3, 'title' => 'coupe sports', 'body' => ''],
        ]);

        $result = $index->searchBoolean('sedan or suv');
        $this->assertContains(1, $result['ids']);
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(3, $result['ids']);
    }

    public function testSearchBooleanNot(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan', 'body' => ''],
            ['id' => 2, 'title' => 'audi sedan', 'body' => ''],
        ]);

        $result = $index->searchBoolean('sedan -bmw');
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(1, $result['ids']);
    }

    public function testSearchBooleanNotRespectsAsYouTypePrefix(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'mercedes sedan', 'body' => ''],
            ['id' => 2, 'title' => 'audi sedan', 'body' => ''],
        ]);

        // Both docs match 'sedan', but doc 1 must be excluded because
        // 'mercedes' starts with 'merc' and asYouType prefix NOT is enabled.
        $index->asYouType = true;
        $result = $index->searchBoolean('sedan -merc');
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(1, $result['ids']);
    }

    public function testSearchBooleanDoesNotReturnDocScores(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan', 'body' => '']);

        $result = $index->searchBoolean('sedan');
        $this->assertArrayNotHasKey('docScores', $result);
        $this->assertArrayHasKey('ids', $result);
        $this->assertArrayHasKey('hits', $result);
    }

    // --- as-you-type prefix ---

    public function testAsYouTypePrefixMatchesPartialWord(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz', 'body' => '']);

        $index->asYouType = true;
        $result = $index->search('merc');
        $this->assertContains(1, $result['ids']);
    }

    public function testAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz', 'body' => '']);

        $index->asYouType = false;
        $result = $index->search('merc');
        $this->assertNotContains(1, $result['ids']);
    }
}

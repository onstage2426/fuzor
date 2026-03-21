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
        Index::create($this->dbPath)->close();

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
        $index->close();

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
        $index->insert(['id' => 1, 'title' => 'sedan car']);

        $result = $index->search('helicopter');
        $this->assertSame([], $result['ids']);
        $this->assertSame(0, $result['hits']);
    }

    public function testSearchReturnsBm25Scores(): void
    {
        $index = Index::create($this->dbPath);
        // Two docs so the term is not universal; smoothed IDF is always > 0 anyway.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
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
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'coupe'],
        ]);

        $result = $index->search('sedan');
        $this->assertSame(2, $result['hits']);
    }

    public function testSearchRespectsNumOfResults(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $result = $index->search('sedan', 2);
        $this->assertCount(2, $result['ids']);
        $this->assertSame(3, $result['hits']); // total untruncated
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'SEDAN']);

        $this->assertContains(1, $index->search('sedan')['ids']);
        $this->assertContains(1, $index->search('SEDAN')['ids']);
    }

    // --- insertMany ---

    public function testInsertManyIndexesAllDocuments(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
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
        $index->insert(['id' => 1, 'title' => 'sedan car']);
        $index->update(['id' => 1, 'title' => 'suv truck']);

        $this->assertEmpty($index->search('sedan')['ids']);
        $this->assertContains(1, $index->search('suv')['ids']);
    }

    public function testUpdatePreservesTotalDocumentCount(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update(['id' => 1, 'title' => 'suv']);

        // total_documents must stay at 2, not grow to 3.
        $this->assertSame(2, $index->search('suv')['hits'] + $index->search('coupe')['hits']);
    }

    // --- delete ---

    public function testDeleteRemovesDocumentFromResults(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car']);
        $index->delete(1);

        $this->assertEmpty($index->search('sedan')['ids']);
    }

    public function testDeleteDoesNotAffectOtherDocuments(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);
        $index->delete(1);

        $this->assertContains(2, $index->search('suv')['ids']);
    }

    public function testDeleteNonexistentDocumentIsNoop(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
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
        $index->insert(['id' => 1, 'title' => 'Volkswagen Golf']);

        $result = $index->searchFuzzy('volksagen');
        $this->assertContains(1, $result['ids']);
        $this->assertArrayHasKey(1, $result['docScores']);
        $this->assertGreaterThan(0.0, $result['docScores'][1]);
    }

    // --- searchBoolean ---

    public function testSearchBooleanAnd(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan coupe'],
            ['id' => 2, 'title' => 'sedan only'],
            ['id' => 3, 'title' => 'coupe only'],
        ]);

        $result = $index->searchBoolean('sedan coupe');
        $this->assertContains(1, $result['ids']);
        $this->assertNotContains(2, $result['ids']);
        $this->assertNotContains(3, $result['ids']);
    }

    public function testSearchBooleanOr(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
            ['id' => 3, 'title' => 'coupe sports'],
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
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
        ]);

        $result = $index->searchBoolean('sedan -bmw');
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(1, $result['ids']);
    }

    public function testSearchBooleanNotRespectsAsYouTypePrefix(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'mercedes sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
        ]);

        // Both docs match 'sedan', but doc 1 must be excluded because
        // 'mercedes' starts with 'merc' and asYouType prefix NOT is enabled.
        $index->asYouType = true;
        $result = $index->searchBoolean('sedan -merc');
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(1, $result['ids']);
    }

    public function testSearchBooleanDocScoresIsNull(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->searchBoolean('sedan');
        // docScores is typed as null in the return signature; the assertion documents the contract.
        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertNull($result['docScores']);
    }

    public function testSearchBooleanAndLastTermPrefixMatches(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'bmw coupe'],
            ['id' => 3, 'title' => 'audi sedan'],
        ]);

        // 'sed' is a prefix of 'sedan' — asYouType should expand the last AND term.
        $index->asYouType = true;
        $result = $index->searchBoolean('bmw sed');
        $this->assertContains(1, $result['ids']);
        $this->assertNotContains(2, $result['ids']);
        $this->assertNotContains(3, $result['ids']);
    }

    public function testSearchBooleanOrLastTermPrefixMatches(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw coupe'],
            ['id' => 2, 'title' => 'audi sedan'],
            ['id' => 3, 'title' => 'tesla electric'],
        ]);

        // 'sed' is a prefix of 'sedan' — asYouType should expand the last OR term.
        $index->asYouType = true;
        $result = $index->searchBoolean('bmw or sed');
        $this->assertContains(1, $result['ids']);
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(3, $result['ids']);
    }

    public function testSearchBooleanAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi coupe'],
        ]);

        // With asYouType off, 'sed' must not expand to 'sedan'.
        $index->asYouType = false;
        $result = $index->searchBoolean('bmw sed');
        $this->assertEmpty($result['ids']);
    }

    public function testSearchBooleanOnlyLastTermIsPrefixExpanded(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan coupe'],
            ['id' => 2, 'title' => 'sedan hatchback'],
        ]);

        // 'sed' should NOT expand 'sedan' for the first AND term;
        // 'cou' should expand 'coupe' only for the last term.
        $index->asYouType = true;
        $result = $index->searchBoolean('sed cou');
        // 'sed' is not the last term so it must match exactly — no doc has 'sed' literally.
        $this->assertEmpty($result['ids']);
    }

    // --- BM25 ordering ---

    public function testSearchOrdersByRelevance(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan sedan'],
        ]);

        $result = $index->search('sedan');
        // Doc 2 has higher term frequency so BM25 should rank it first.
        $this->assertSame(2, $result['ids'][0]);
    }

    // --- maxDocs ---

    public function testMaxDocsLimitsResultsPerKeyword(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
            ['id' => 4, 'title' => 'sedan'],
            ['id' => 5, 'title' => 'sedan'],
        ]);

        $index->maxDocs = 2;
        $result = $index->search('sedan');
        $this->assertCount(2, $result['ids']);
    }

    // --- fuzzy distance ---

    public function testFuzzyDistanceOneAcceptsDistanceOneTypo(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $index->fuzzyDistance = 1;
        // 'sedaan' is one insertion away from 'sedan'.
        $this->assertContains(1, $index->searchFuzzy('sedaan')['ids']);
    }

    public function testFuzzyDistanceOneRejectsDistanceTwoTypo(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $index->fuzzyDistance = 1;
        // 'seddaan' is two insertions away from 'sedan'.
        $this->assertNotContains(1, $index->searchFuzzy('seddaan')['ids']);
    }

    public function testSearchFuzzyNoMatchReturnsEmpty(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->searchFuzzy('xqzpwk');
        $this->assertSame([], $result['ids']);
        $this->assertSame(0, $result['hits']);
    }

    // --- update upsert ---

    public function testUpdateCreatesDocWhenIdNotFound(): void
    {
        $index = Index::create($this->dbPath);
        $index->update(['id' => 999, 'title' => 'sedan']);

        $this->assertContains(999, $index->search('sedan')['ids']);
    }

    // --- searchBoolean extended ---

    public function testSearchBooleanAndMissingTermReturnsEmpty(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        // Space is the AND operator; 'helicopter' is not indexed, so the AND yields nothing.
        $result = $index->searchBoolean('sedan helicopter');
        $this->assertSame([], $result['ids']);
    }

    public function testSearchBooleanHitsExceedsNumOfResults(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $result = $index->searchBoolean('sedan', 2);
        $this->assertCount(2, $result['ids']);
        $this->assertSame(3, $result['hits']);
    }

    public function testSearchBooleanMultipleNots(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
            ['id' => 3, 'title' => 'tesla sedan'],
        ]);

        $result = $index->searchBoolean('sedan -bmw -audi');
        $this->assertContains(3, $result['ids']);
        $this->assertNotContains(1, $result['ids']);
        $this->assertNotContains(2, $result['ids']);
    }

    // --- as-you-type prefix ---

    public function testAsYouTypePrefixMatchesPartialWord(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz']);

        $index->asYouType = true;
        $result = $index->search('merc');
        $this->assertContains(1, $result['ids']);
    }

    public function testAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz']);

        $index->asYouType = false;
        $result = $index->search('merc');
        $this->assertNotContains(1, $result['ids']);
    }
}

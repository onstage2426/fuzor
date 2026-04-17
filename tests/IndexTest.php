<?php

namespace Fuzor\Tests;

use Fuzor\Index;
use Fuzor\IndexHandle;
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
        $this->assertInstanceOf(IndexHandle::class, $index);
    }

    public function testOpenReturnsIndexInstance(): void
    {
        Index::create($this->dbPath)->close();

        $index = Index::open($this->dbPath);
        $this->assertInstanceOf(IndexHandle::class, $index);
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
        // Require the full structure: prefix, then the conflicting ID, then the suffix.
        // This kills concat-order mutations (ID moved to front/end) and partial-removal
        // mutations (missing ID or missing "Use update()" suffix).
        $this->expectExceptionMessageMatches('#^Documents already exist with ids: 1\. Use update\(\)#');
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

        $result = $index->search('sedan', numOfResults: 2);
        $this->assertCount(2, $result['ids']);
        $this->assertSame(3, $result['hits']); // total untruncated
    }

    public function testSearchDefaultNumOfResultsIsOneHundred(): void
    {
        // Insert 101 docs so the parameterless call must cap at exactly 100 — not 99 or 101.
        $index = Index::create($this->dbPath);
        $index->insertMany(array_map(fn($i) => ['id' => $i, 'title' => 'sedan'], range(1, 101)));

        $result = $index->search('sedan');
        $this->assertCount(100, $result['ids']);
        $this->assertSame(101, $result['hits']);

        $resultFuzzy = $index->search('sedan', fuzzy: true);
        $this->assertCount(100, $resultFuzzy['ids']);
        $this->assertSame(101, $resultFuzzy['hits']);

        $resultBool = $index->searchBoolean('sedan');
        $this->assertCount(100, $resultBool['ids']);
        $this->assertSame(101, $resultBool['hits']);
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

    // --- search (fuzzy) ---

    public function testSearchFuzzyMatchesTypo(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz', 'body' => 'luxury car']);

        $result = $index->search('mercdes', fuzzy: true);
        $this->assertContains(1, $result['ids']);
    }

    public function testSearchFuzzyReturnsDocScores(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Volkswagen Golf']);

        $result = $index->search('volksagen', fuzzy: true);
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
        $this->assertContains(1, $index->search('sedaan', fuzzy: true)['ids']);
    }

    public function testFuzzyDistanceOneRejectsDistanceTwoTypo(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $index->fuzzyDistance = 1;
        // 'seddaan' is two insertions away from 'sedan'.
        $this->assertNotContains(1, $index->search('seddaan', fuzzy: true)['ids']);
    }

    public function testSearchFuzzyNoMatchReturnsEmpty(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->search('xqzpwk', fuzzy: true);
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

    // --- search() is exact, not fuzzy ---

    public function testSearchIsExactNotFuzzy(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'hello']);

        // 'helo' is 1 edit away from 'hello'; exact search must not find it.
        $index->asYouType = false;
        $this->assertEmpty($index->search('helo')['ids']);

        // fuzzy search with the same typo must find it, confirming the term is indexed.
        $this->assertContains(1, $index->search('helo', fuzzy: true)['ids']);
    }

    // --- heap top-k ordering ---

    public function testSearchTopKFromHeapIsOrdered(): void
    {
        $index = Index::create($this->dbPath);
        // Five docs; TF increases with ID so BM25 score increases monotonically with ID.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan'],
            ['id' => 3, 'title' => 'sedan sedan sedan'],
            ['id' => 4, 'title' => 'sedan sedan sedan sedan'],
            ['id' => 5, 'title' => 'sedan sedan sedan sedan sedan'],
        ]);

        // Request only 3 results (total=5 > 3 triggers the heap path).
        $result = $index->search('sedan', numOfResults: 3);

        $this->assertCount(3, $result['ids']);
        // Doc 5 has the highest TF so it must be ranked first.
        $this->assertSame(5, $result['ids'][0]);
        // Results must be in descending score order.
        for ($i = 1; $i < count($result['ids']); $i++) {
            $prev = $result['ids'][$i - 1];
            $curr = $result['ids'][$i];
            $this->assertGreaterThanOrEqual(
                $result['docScores'][$curr],
                $result['docScores'][$prev],
            );
        }
    }

    // --- boolean operator precedence ---

    public function testBooleanAndBindsTighterThanOr(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],          // only sedan
            ['id' => 2, 'title' => 'coupe truck'],     // coupe AND truck
            ['id' => 3, 'title' => 'coupe'],           // only coupe
        ]);

        // "sedan or coupe truck" must parse as sedan OR (coupe AND truck).
        // Correct: matches 1 and 2; not 3 (coupe without truck).
        // With reversed precedence it would parse as (sedan OR coupe) AND truck — matching 2 only.
        $index->asYouType = false;
        $result = $index->searchBoolean('sedan or coupe truck');
        $this->assertContains(1, $result['ids']);
        $this->assertContains(2, $result['ids']);
        $this->assertNotContains(3, $result['ids']);
    }

    // --- single-term boolean as-you-type (lastTerm at postfix index 0) ---

    public function testSearchBooleanSingleTermAsYouTypePrefix(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);

        // A single-term boolean query: the term is at postfix index 0.
        // The loop that finds lastTerm must reach index 0 or prefix expansion breaks.
        $index->asYouType = true;
        $result = $index->searchBoolean('sed');
        $this->assertContains(1, $result['ids']);
        $this->assertNotContains(2, $result['ids']);
    }

    // --- fuzzy ordering by Levenshtein distance ---

    public function testFuzzySearchCloserMatchRanksFirst(): void
    {
        $index = Index::create($this->dbPath);
        // Query 'drago' (not indexed) is distance=1 from 'dragon' and distance=2 from 'draagon'.
        $index->insertMany([
            ['id' => 1, 'title' => 'dragon'],   // distance=1 from query
            ['id' => 2, 'title' => 'draagon'],  // distance=2 from query
        ]);

        $index->fuzzyDistance = 2;
        $index->asYouType     = false;
        $result = $index->search('drago', fuzzy: true);

        $this->assertContains(1, $result['ids']);
        $this->assertContains(2, $result['ids']);
        // The closer match (distance=1) must rank before the farther one (distance=2).
        $pos = array_flip($result['ids']);
        $this->assertLessThan($pos[2], $pos[1]);
    }

    // --- numOfResults=0 still counts total hits (mutant 45: || → &&) ---

    public function testSearchReturnsEmptyIdsWhenNumOfResultsIsZero(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $result = $index->search('sedan', numOfResults: 0);
        $this->assertSame([], $result['ids']);
        $this->assertSame(1, $result['hits']);
    }

    // --- BM25 score order differs from DB fetch order (mutant 48: arsort removed) ---

    public function testSearchResultsOrderedByBm25NotFetchOrder(): void
    {
        $index = Index::create($this->dbPath);
        // Doc 1: tf=1, dl=1 — very short, high BM25 score per term.
        // Doc 2: tf=3, dl=100 — long doc; DB fetches it first (higher hit_count), but BM25 penalises length.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan sedan ' . str_repeat('filler ', 97)],
        ]);
        $result = $index->search('sedan');
        // Short doc must rank first; without arsort the raw DB order (doc 2) would win.
        $this->assertSame(1, $result['ids'][0]);
    }

    // --- multi-keyword score accumulation (mutant 38: 0.0 ?? score resets accumulator) ---

    public function testMultiKeywordSearchAccumulatesScoresAcrossTerms(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'car sedan'],           // both terms, dl=2
            ['id' => 2, 'title' => str_repeat('sedan ', 10)],  // sedan only, tf=10, dl=10
        ]);
        $index->asYouType = false;
        // Doc 1 earns score for both 'car' (rare term, high IDF) and 'sedan'.
        // Doc 2 earns only a sedan score (high TF but heavily length-penalised).
        // Without accumulation the coalesce bug resets doc1 to sedan-only, so doc2 wins.
        $result = $index->search('car sedan');
        $this->assertSame(1, $result['ids'][0]);
    }

    // --- error message contains the directory that was rejected ---

    public function testCreateErrorMessageContainsDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        // Require BOTH the fixed prefix AND the path so that mutants which reverse the
        // concatenation order (path . "Directory does not exist: ") or drop the prefix
        // (leaving just the path) are caught.
        $this->expectExceptionMessageMatches('#^Directory does not exist: .*?/nonexistent/dir#');
        Index::create('/nonexistent/dir/index.db');
    }

    // --- Unicode normalization in boolean search ---

    public function testSearchBooleanNormalizesUnicodeUppercase(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'café']);
        $index->asYouType = false;
        $result = $index->searchBoolean('CAFÉ');
        $this->assertContains(1, $result['ids']);
    }

    // --- BM25 top-k selects by score not by hit count ---

    public function testSearchTopKSelectsByBm25NotByHitCount(): void
    {
        $index = Index::create($this->dbPath);
        // Docs 1 and 2: high term frequency, long documents → low BM25 (length penalty).
        // DB returns these first (by doc_id). Docs 3 and 4: single occurrence, very
        // short → higher BM25 despite lower hit_count.
        $index->insertMany([
            ['id' => 1, 'title' => str_repeat('sedan ', 5) . str_repeat('filler ', 195)],
            ['id' => 2, 'title' => str_repeat('sedan ', 3) . str_repeat('filler ', 147)],
            ['id' => 3, 'title' => 'sedan'],
            ['id' => 4, 'title' => 'sedan'],
        ]);
        // Heap path fires because total (4) > numOfResults (2).
        // Correct: short docs win on BM25 → ids [3, 4].
        // Mutant 34 (> → <=): short docs are never swapped in → ids [1, 2] instead.
        $result = $index->search('sedan', numOfResults: 2);
        $this->assertContains(3, $result['ids']);
        $this->assertContains(4, $result['ids']);
        $this->assertNotContains(1, $result['ids']);
        $this->assertNotContains(2, $result['ids']);
    }

    // --- prefix expansion returns all matching terms, not just the first ---

    public function testPrefixSearchExpandsAllMatchingTerms(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'suv'],
        ]);
        // asYouType defaults to true; query 's' must expand to both 'sedan' and 'suv'.
        // Mutant 190 returns only the first wordlist row, so one doc would be missing.
        $result = $index->search('s');
        $this->assertContains(1, $result['ids']);
        $this->assertContains(2, $result['ids']);
    }

    // --- fuzzy secondary sort: same distance → more popular term first ---

    public function testSearchFuzzySecondaryOrderByPopularity(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'dragon dragon dragon dragon dragon'], // 5 hits — high num_hits
            ['id' => 2, 'title' => 'drage'],                             // 1 hit — low num_hits
        ]);
        // 'drako' is NOT a prefix of 'dragon' or 'drage', so the prefix lookup finds nothing
        // and the fuzzy Levenshtein path kicks in.  Both 'dragon' (d=2) and 'drage' (d=2)
        // are at the same edit distance from 'drako', so the secondary sort by num_hits
        // decides order: DESC → 'dragon' first (5 hits), ASC (mutant) → 'drage' first (1 hit).
        $result = $index->search('drako', fuzzy: true);
        $this->assertSame(1, $result['ids'][0]);
    }

    // --- inspectQuery ---

    public function testInspectQueryRawTokensMatchTokenizer(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('Hello World');
        $this->assertSame(['hello', 'world'], $result['raw_tokens']);
    }

    public function testInspectQueryNoLanguageFilteredTokensEqualRaw(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('hello world');
        $this->assertSame($result['raw_tokens'], $result['filtered_tokens']);
        $this->assertFalse($result['stopwords_active']);
        $this->assertFalse($result['stemmer_active']);
    }

    public function testInspectQueryStopwordsActiveWhenLanguageSet(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result['stopwords_active']);
    }

    public function testInspectQueryStemmerActiveWhenLanguageSet(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result['stemmer_active']);
    }

    public function testInspectQueryFilteredTokensDropsStopwords(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $result = $index->inspectQuery('the quick');
        $this->assertNotContains('the', $result['filtered_tokens']);
        $this->assertContains('the', $result['raw_tokens']);
    }

    public function testInspectQueryAllStrippedTrueWhenOnlyStopwords(): void
    {
        // Single-token all-stopword query: filterQueryTokens only strips when count > 1,
        // so use two stopwords to trigger the all-stripped fallback.
        $index = Index::create($this->dbPath, language: 'en');
        $result = $index->inspectQuery('the and');
        $this->assertTrue($result['all_stripped']);
        // Fallback fires — filtered_tokens equals raw_tokens.
        $this->assertSame($result['raw_tokens'], $result['filtered_tokens']);
    }

    public function testInspectQueryAllStrippedFalseWithNoLanguage(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('the and');
        $this->assertFalse($result['all_stripped']);
    }

    public function testInspectQueryStemmerApplied(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $result = $index->inspectQuery('running');
        // 'running' stems to 'run' in English Snowball
        $this->assertSame('run', $result['filtered_tokens'][0]);
    }

    public function testInspectQueryRawToProcessedMapping(): void
    {
        $index = Index::create($this->dbPath, language: 'en');
        $result = $index->inspectQuery('running');
        $this->assertSame('running', $result['tokens'][0]['raw']);
        $this->assertSame('run', $result['tokens'][0]['processed']);
    }

    public function testInspectQueryFoundTrueForIndexedTerm(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->asYouType = false;
        $result = $index->inspectQuery('sedan');
        $this->assertTrue($result['tokens'][0]['found']);
        $this->assertGreaterThanOrEqual(1, $result['tokens'][0]['num_docs']);
        $this->assertGreaterThanOrEqual(1, $result['tokens'][0]['num_hits']);
    }

    public function testInspectQueryFoundFalseForMissingTerm(): void
    {
        $index = Index::create($this->dbPath);
        $index->asYouType = false;
        $result = $index->inspectQuery('zzznomatch');
        $this->assertFalse($result['tokens'][0]['found']);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
        $this->assertSame(0, $result['tokens'][0]['num_docs']);
        $this->assertSame(0, $result['tokens'][0]['num_hits']);
    }

    public function testInspectQueryMatchTypeExact(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->asYouType = false;
        $result = $index->inspectQuery('sedan');
        $this->assertSame('exact', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryMatchTypePrefix(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->asYouType = true;
        // 'sed' is the last (only) token → prefix expansion fires
        $result = $index->inspectQuery('sed');
        $this->assertSame('prefix', $result['tokens'][0]['match_type']);
        $terms = array_column($result['tokens'][0]['wordlist_rows'], 'term');
        $this->assertContains('sedan', $terms);
    }

    public function testInspectQueryMatchTypeFuzzy(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->asYouType = false;
        $result = $index->inspectQuery('sedaan', fuzzy: true);
        $this->assertSame('fuzzy', $result['tokens'][0]['match_type']);
        $this->assertNotNull($result['tokens'][0]['wordlist_rows'][0]['distance']);
    }

    public function testInspectQueryMatchTypeNoneWhenNoCandidate(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->asYouType = false;
        $result = $index->inspectQuery('zzznomatch', fuzzy: true);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryPrefixExpandsMultipleTerms(): void
    {
        $index = Index::create($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'body' => 'sedan'],
            ['id' => 2, 'body' => 'sediment'],
        ]);
        $index->asYouType = true;
        $result = $index->inspectQuery('sed');
        $terms = array_column($result['tokens'][0]['wordlist_rows'], 'term');
        $this->assertContains('sedan', $terms);
        $this->assertContains('sediment', $terms);
    }

    public function testInspectQueryIsLastOnlyTrueForFinalToken(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('fast sedan review');
        $this->assertFalse($result['tokens'][0]['is_last']);
        $this->assertFalse($result['tokens'][1]['is_last']);
        $this->assertTrue($result['tokens'][2]['is_last']);
    }

    public function testInspectQueryIndexInfoMatchesInfoMethod(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $this->assertSame($index->info(), $index->inspectQuery('sedan')['index_info']);
    }

    public function testInspectQueryBooleanPostfixAndOperator(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('php laravel');
        $this->assertContains('&', $result['boolean_postfix']);
    }

    public function testInspectQueryBooleanPostfixOrOperator(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('php or laravel');
        $this->assertContains('|', $result['boolean_postfix']);
    }

    public function testInspectQueryBooleanPostfixNotOperator(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('php -wordpress');
        $this->assertContains('~', $result['boolean_postfix']);
    }

    public function testInspectQueryEmptyPhraseReturnsEmptyLists(): void
    {
        $index = Index::create($this->dbPath);
        $result = $index->inspectQuery('');
        $this->assertSame([], $result['raw_tokens']);
        $this->assertSame([], $result['filtered_tokens']);
        $this->assertSame([], $result['tokens']);
    }

    public function testInspectQueryDoesNotChangeDocumentCount(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $before = $index->info()['total_documents'];
        $index->inspectQuery('sedan');
        $this->assertSame($before, $index->info()['total_documents']);
    }

    public function testInspectQueryWarmsWordlistCacheForSearch(): void
    {
        $index = Index::create($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->asYouType = false;
        $index->inspectQuery('sedan');
        // If cache is warm, search returns the same result without extra DB reads.
        $result = $index->search('sedan');
        $this->assertContains(1, $result['ids']);
    }
}

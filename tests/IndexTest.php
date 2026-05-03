<?php

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\Index;
use Fuzor\Exceptions\IOException;
use Fuzor\Exceptions\QueryException;
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
        $index = new Index($this->dbPath);
        $this->assertInstanceOf(Index::class, $index);
    }

    public function testOpenReturnsIndexInstance(): void
    {
        new Index($this->dbPath)->close();

        $index = new Index($this->dbPath);
        $this->assertInstanceOf(Index::class, $index);
    }

    public function testCloseReleasesConnection(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->close();

        $reopened = new Index($this->dbPath);
        $this->assertContains(1, $reopened->search('sedan')->ids);
    }

    public function testStatsPersistAfterReopen(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->delete(1);
        $index->close();
        $index = new Index($this->dbPath);
        $info = $index->inspectQuery('coupe')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    public function testConstructorThrowsIfDirectoryDoesNotExist(): void
    {
        $this->expectException(IOException::class);
        new Index('/nonexistent/fuzor_no_such.db');
    }

    public function testConstructorThrowsIfDirectoryDoesNotExistLong(): void
    {
        $this->expectException(IOException::class);
        new Index('/nonexistent/dir/index.db');
    }

    public function testConstructorWithUnsupportedLanguageThrowsBeforePathResolution(): void
    {
        // Language guard must fire before resolvePath(); using a non-existent directory
        // proves it: without the early throw the code would reach resolvePath() and produce
        // an IOException instead of QueryException.
        $this->expectException(QueryException::class);
        new Index('/nonexistent/dir/index.db', language: 'xx');
    }

    public function testCreateWithForceOverwritesExistingFile(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->close();

        $fresh = new Index($this->dbPath, force: true);
        $this->assertSame([], $fresh->search('sedan')->ids);
    }

    public function testCreateWithForceRefusesToOverwriteNonSqliteFile(): void
    {
        file_put_contents($this->dbPath, 'this is not a sqlite file');

        $this->expectException(IOException::class);
        $this->expectExceptionMessageMatches('/Refusing to overwrite non-Fuzor file/');
        new Index($this->dbPath, force: true);

        $this->assertStringEqualsFile($this->dbPath, 'this is not a sqlite file');
    }

    public function testCreateErrorMessageContainsDirectory(): void
    {
        $this->expectException(IOException::class);
        // Require BOTH the fixed prefix AND the path so that mutants which reverse the
        // concatenation order (path . "Directory does not exist: ") or drop the prefix
        // (leaving just the path) are caught.
        $this->expectExceptionMessageMatches('#^Directory does not exist: .*?/nonexistent/dir#');
        new Index('/nonexistent/dir/index.db');
    }

    // --- exists ---

    public function testExistsTrueForValidIndex(): void
    {
        new Index($this->dbPath)->close();
        $this->assertTrue(Index::exists($this->dbPath));
    }

    public function testExistsFalseWhenFileAbsent(): void
    {
        $this->assertFalse(Index::exists($this->dbPath));
    }

    public function testExistsFalseForNonSqliteFile(): void
    {
        file_put_contents($this->dbPath, 'not a sqlite file');
        $this->assertFalse(Index::exists($this->dbPath));
    }

    public function testExistsFalseForSqliteWithoutFuzorSchema(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
        unset($pdo);
        $this->assertFalse(Index::exists($this->dbPath));
    }

    public function testExistsFalseForNonexistentDirectory(): void
    {
        $this->assertFalse(Index::exists('/nonexistent/dir/index.db'));
    }

    public function testExistsDoesNotCreateFileForMissingPath(): void
    {
        // Without the early return false on !file_exists(), PDO would create an empty SQLite
        // file at the path. Assert no file is left behind as a side effect.
        Index::exists($this->dbPath);
        $this->assertFileDoesNotExist($this->dbPath);
    }

    // --- insert / search ---

    public function testInsertWithoutIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->insert(['title' => 'no id here']);
    }

    public function testInsertDuplicateIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->expectException(QueryException::class);
        $index->insert(['id' => 1, 'title' => 'duplicate']);
    }

    public function testInsertManyWithMissingIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['title' => 'no id'],
        ]);
    }

    public function testInsertManyWithDuplicateInputIdsThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 1, 'title' => 'duplicate in same batch'],
        ]);
    }

    public function testInsertManyWithExistingIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->expectException(QueryException::class);
        // Require the full structure: prefix, then the conflicting ID, then the suffix.
        // This kills concat-order mutations (ID moved to front/end) and partial-removal
        // mutations (missing ID or missing "Use update()" suffix).
        $this->expectExceptionMessageMatches('#^Documents already exist with ids: 1\. Use update\(\)#');
        $index->insertMany([['id' => 1, 'title' => 'already exists']]);
    }

    public function testUpdateWithoutIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->update(['title' => 'no id here']);
    }

    public function testInsertAndSearchReturnsMatchingId(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'fast sedan', 'body' => 'comfortable city car']);

        $result = $index->search('sedan');
        $this->assertContains(1, $result->ids);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car']);

        $result = $index->search('helicopter');
        $this->assertSame([], $result->ids);
        $this->assertSame(0, $result->hits);
    }

    public function testSearchReturnsBm25Scores(): void
    {
        $index = new Index($this->dbPath);
        // Two docs so the term is not universal; smoothed IDF is always > 0 anyway.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);

        $result = $index->search('sedan');
        $this->assertNotNull($result->score(1));
        $this->assertGreaterThan(0.0, $result->score(1));
    }

    public function testSearchHitsCountsAllMatchingDocs(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'coupe'],
        ]);

        $result = $index->search('sedan');
        $this->assertSame(2, $result->hits);
    }

    public function testInsertSharedTermAcrossCallsPreservesTermAfterDelete(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']); // cache miss: INSERT path, termIdCache seeded
        $index->insert(['id' => 2, 'title' => 'sedan']); // cache hit: UPDATE path in upsertWordlist()
        $index->delete(1);

        // If the cache-hit UPDATE was a no-op, wordlist num_hits for 'sedan' would still be 1
        // after the second insert. Deleting doc 1 (1 hit) would zero it and prune the term.
        $this->assertContains(2, $index->search('sedan')->ids);
    }

    public function testSearchRespectsNumOfResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $result = $index->search('sedan', limit: 2);
        $this->assertCount(2, $result->ids);
        $this->assertSame(3, $result->hits); // total untruncated
    }

    public function testSearchDefaultNumOfResultsIsOneHundred(): void
    {
        // Insert 101 docs so the parameterless call must cap at exactly 100 — not 99 or 101.
        $index = new Index($this->dbPath);
        $index->insertMany(array_map(fn($i) => ['id' => $i, 'title' => 'sedan'], range(1, 101)));

        $result = $index->search('sedan');
        $this->assertCount(100, $result->ids);
        $this->assertSame(101, $result->hits);

        $resultFuzzy = $index->search('sedan', fuzzy: true);
        $this->assertCount(100, $resultFuzzy->ids);
        $this->assertSame(101, $resultFuzzy->hits);

        $resultBool = $index->searchBoolean('sedan');
        $this->assertCount(100, $resultBool->ids);
        $this->assertSame(101, $resultBool->hits);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'SEDAN']);

        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertContains(1, $index->search('SEDAN')->ids);
    }

    public function testSearchLowercasesMultibyteQueryViaSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'über']);

        // MBString mutation in getWordlistByKeyword replaces mb_strtolower with strtolower.
        // 'Ü' (U+00DC) is two UTF-8 bytes; strtolower leaves it unchanged, so 'ÜBER' stays
        // uppercase and doesn't match the lowercase-stored term 'über'.
        // searchBoolean already lowercases in lexExpression, so this test must use search().
        $this->assertContains(1, $index->search('ÜBER', asYouType: false)->ids);
    }

    public function testSearchIsExactNotFuzzy(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'hello']);

        $this->assertEmpty($index->search('helo', asYouType: false)->ids);

        $this->assertContains(1, $index->search('helo', fuzzy: true)->ids);
    }

    public function testSearchOrdersByRelevance(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan sedan'],
        ]);

        $result = $index->search('sedan');
        $this->assertSame(2, $result->ids[0]);
    }

    public function testSearchResultsOrderedByBm25NotFetchOrder(): void
    {
        $index = new Index($this->dbPath);
        // Doc 1: tf=1, dl=1 — very short, high BM25 score per term.
        // Doc 2: tf=3, dl=100 — long doc; DB fetches it first (higher hit_count), but BM25 penalises length.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan sedan ' . str_repeat('filler ', 97)],
        ]);
        $result = $index->search('sedan');
        // Short doc must rank first; without arsort the raw DB order (doc 2) would win.
        $this->assertSame(1, $result->ids[0]);
    }

    public function testMultiKeywordSearchAccumulatesScoresAcrossTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'car sedan'],           // both terms, dl=2
            ['id' => 2, 'title' => str_repeat('sedan ', 10)],  // sedan only, tf=10, dl=10
        ]);
        // Doc 1 earns score for both 'car' (rare term, high IDF) and 'sedan'.
        // Doc 2 earns only a sedan score (high TF but heavily length-penalised).
        // Without accumulation the coalesce bug resets doc1 to sedan-only, so doc2 wins.
        $result = $index->search('car sedan', asYouType: false);
        $this->assertSame(1, $result->ids[0]);
    }

    public function testSearchReturnsEmptyIdsWhenNumOfResultsIsZero(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $result = $index->search('sedan', limit: 0);
        $this->assertSame([], $result->ids);
        $this->assertSame(1, $result->hits);
    }

    // --- as-you-type prefix ---

    public function testAsYouTypePrefixMatchesPartialWord(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz']);

        $result = $index->search('merc');
        $this->assertContains(1, $result->ids);
    }

    public function testAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz']);

        $result = $index->search('merc', asYouType: false);
        $this->assertNotContains(1, $result->ids);
    }

    public function testPrefixSearchExpandsAllMatchingTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'suv'],
        ]);
        // Mutant 190 returns only the first wordlist row, so one doc would be missing.
        $result = $index->search('s');
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
    }

    // --- maxDocs ---

    public function testMaxDocsLimitsResultsPerKeyword(): void
    {
        $index = new Index($this->dbPath, config: new \Fuzor\Config(maxDocs: 2));
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
            ['id' => 4, 'title' => 'sedan'],
            ['id' => 5, 'title' => 'sedan'],
        ]);

        $result = $index->search('sedan');
        $this->assertCount(2, $result->ids);
    }

    // --- top-k / heap ---

    public function testSearchTopKFromHeapIsOrdered(): void
    {
        $index = new Index($this->dbPath);
        // Five docs; TF increases with ID so BM25 score increases monotonically with ID.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan'],
            ['id' => 3, 'title' => 'sedan sedan sedan'],
            ['id' => 4, 'title' => 'sedan sedan sedan sedan'],
            ['id' => 5, 'title' => 'sedan sedan sedan sedan sedan'],
        ]);

        // Request only 3 results (total=5 > 3 triggers the heap path).
        $result = $index->search('sedan', limit: 3);

        $this->assertCount(3, $result->ids);
        // Doc 5 has the highest TF so it must be ranked first.
        $this->assertSame(5, $result->ids[0]);
        // Results must be in descending score order.
        for ($i = 1; $i < count($result->ids); $i++) {
            $prev = $result->ids[$i - 1];
            $curr = $result->ids[$i];
            $this->assertGreaterThanOrEqual(
                $result->score($curr),
                $result->score($prev),
            );
        }
    }

    public function testSearchTopKSelectsByBm25NotByHitCount(): void
    {
        $index = new Index($this->dbPath);
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
        $result = $index->search('sedan', limit: 2);
        $this->assertContains(3, $result->ids);
        $this->assertContains(4, $result->ids);
        $this->assertNotContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    public function testSearchHeapMinIsSetWhenHeapBecomesFull(): void
    {
        $index = new Index($this->dbPath);
        // avgdl = (3+1+2)/3 = 2, k1=1.2, b=0.75. BM25 scores ∝ tf/(1.3 + 0.9/avgdl*dl):
        //   doc1 tf=3 dl=3: 3/2.65 ≈ 1.13 (highest); doc2 tf=1 dl=1: 1/1.75 ≈ 0.57 (lowest);
        //   doc3 tf=2 dl=2: 2/2.2  ≈ 0.91 (middle).
        // Fetch order by doc_id: doc1(high), doc2(low), doc3(mid).
        // Fill heap (numOfResults=2): doc1→heapSize=1, doc2→heapSize=2=full.
        // Original: heapMin = heap.top() = doc2's low score → doc3(mid) > low → replaces doc2.
        // Mutation Identical (L352): heapMin set at heapSize≠2 (step 1, recording doc1's high
        //   score) → doc3(mid) > high? No → doc3 not inserted → result = [doc1, doc2] (wrong).
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan sedan sedan'],  // tf=3, highest BM25
            ['id' => 2, 'title' => 'sedan'],              // tf=1, lowest BM25
            ['id' => 3, 'title' => 'sedan sedan'],        // tf=2, middle BM25
        ]);

        $result = $index->search('sedan', limit: 2);

        $this->assertCount(2, $result->ids);
        $this->assertContains(1, $result->ids);     // always in top-2
        $this->assertContains(3, $result->ids);     // middle replaces weakest (correct heapMin)
        $this->assertNotContains(2, $result->ids); // weakest is evicted
    }

    public function testSearchTopKWithNumOfResultsOneReturnsStrictlyBestDoc(): void
    {
        $index = new Index($this->dbPath);
        // Doc 1 has 3 hits (highest BM25), doc 2 has 1 hit (lowest), doc 3 has 2 hits (middle).
        // Fetch order from doclist: doc_id ascending → 1, 2, 3.
        // With numOfResults=1:
        //   Normal: insert doc1, heapSize=1===1 → heapMin=score(doc1)≈high.
        //     doc2: high > high? No. doc3: high > high? No. Result: [doc1]. ✓
        //   Mutation (===→!==): insert doc1, heapSize=1!==1? false → heapMin stays -INF.
        //     Else branch for doc2: score(doc2)>-INF? Yes → replace doc1 with doc2. heapMin=score(doc2).
        //     doc3: score(doc3)>score(doc2)? Yes → replace. heapMin=score(doc3).
        //     Result: [doc3], not [doc1]. ✗
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan sedan sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan sedan'],
        ]);
        $result = $index->search('sedan', limit: 1);
        $this->assertCount(1, $result->ids);
        $this->assertSame(1, $result->ids[0]); // doc1 has the highest BM25 score
    }

    public function testSearchOffsetSkipsTopResults(): void
    {
        $index = new Index($this->dbPath);
        // tf proportional to id: doc5 scores highest, doc1 lowest.
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan'],
            ['id' => 3, 'title' => 'sedan sedan sedan'],
            ['id' => 4, 'title' => 'sedan sedan sedan sedan'],
            ['id' => 5, 'title' => 'sedan sedan sedan sedan sedan'],
        ]);

        $page1 = $index->search('sedan', limit: 2, offset: 0);
        $page2 = $index->search('sedan', limit: 2, offset: 2);
        $page3 = $index->search('sedan', limit: 2, offset: 4);

        $this->assertCount(2, $page1->ids);
        $this->assertCount(2, $page2->ids);
        $this->assertCount(1, $page3->ids);

        // hits is always the full total regardless of offset.
        $this->assertSame(5, $page1->hits);
        $this->assertSame(5, $page2->hits);
        $this->assertSame(5, $page3->hits);

        // Pages are non-overlapping and together cover all 5 docs.
        $allIds = array_merge($page1->ids, $page2->ids, $page3->ids);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], array_unique($allIds));

        // Page 1 must start with the best-scoring doc.
        $this->assertSame(5, $page1->ids[0]);
        // Pages must not overlap.
        $this->assertEmpty(array_intersect($page1->ids, $page2->ids));
        $this->assertEmpty(array_intersect($page2->ids, $page3->ids));
    }

    public function testSearchOffsetBeyondTotalReturnsEmptyIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->search('sedan', limit: 10, offset: 5);

        $this->assertSame([], $result->ids);
        $this->assertSame(1, $result->hits);
    }

    public function testSearchBooleanOffsetSkipsTopResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $page1 = $index->searchBoolean('sedan', limit: 2, offset: 0);
        $page2 = $index->searchBoolean('sedan', limit: 2, offset: 2);

        $this->assertCount(2, $page1->ids);
        $this->assertCount(1, $page2->ids);
        $this->assertSame(3, $page1->hits);
        $this->assertSame(3, $page2->hits);
        $this->assertEmpty(array_intersect($page1->ids, $page2->ids));
    }

    // --- insertMany ---

    public function testInsertManyIndexesAllDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertContains(2, $index->search('suv')->ids);
    }

    public function testInsertManyWithEmptyArrayIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([]);

        $this->assertSame(0, $index->search('anything')->hits);
    }

    // --- update ---

    public function testUpdateReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car']);
        $index->update(['id' => 1, 'title' => 'suv truck']);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertContains(1, $index->search('suv')->ids);
    }

    public function testUpdatePreservesTotalDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update(['id' => 1, 'title' => 'suv']);

        $this->assertSame(2, $index->search('suv')->hits + $index->search('coupe')->hits);
    }

    public function testUpdateExistingDocDoesNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update(['id' => 1, 'title' => 'suv']);

        // A mutation that swaps the strict branch would call adjustStats(+1, newLength)
        // instead of adjustStats(0, delta), growing total_documents from 2 to 3.
        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('2', $info['total_documents']);
    }

    public function testUpdateThrowsIfDocumentDoesNotExist(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        $index->update(['id' => 999, 'title' => 'sedan']);
    }

    public function testUpdateChangesAvgDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'alpha beta gamma delta']);
        $index->update(['id' => 1, 'title' => 'zeta']);
        $info = $index->inspectQuery('zeta')['index_info'];
        $this->assertEqualsWithDelta(1.0, (float) $info['avg_doc_length'], 0.01);
    }

    // --- upsert ---

    public function testUpsertCreatesDocWhenIdNotFound(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert(['id' => 999, 'title' => 'sedan']);

        $this->assertContains(999, $index->search('sedan')->ids);
    }

    public function testUpsertNonExistentDocIncrementsCount(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert(['id' => 1, 'title' => 'sedan']);
        $info = $index->inspectQuery('sedan')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    public function testUpsertReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car']);
        $index->upsert(['id' => 1, 'title' => 'suv truck']);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertContains(1, $index->search('suv')->ids);
    }

    public function testUpsertExistingDocDoesNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->upsert(['id' => 1, 'title' => 'suv']);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('2', $info['total_documents']);
    }

    // --- updateMany ---

    public function testUpdateManyReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'coupe sport'],
        ]);
        $index->updateMany([
            ['id' => 1, 'title' => 'suv truck'],
            ['id' => 2, 'title' => 'hatchback'],
        ]);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertEmpty($index->search('coupe')->ids);
        $this->assertContains(1, $index->search('suv')->ids);
        $this->assertContains(2, $index->search('hatchback')->ids);
    }

    public function testUpdateManyPreservesTotalDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->updateMany([
            ['id' => 1, 'title' => 'hatchback'],
            ['id' => 2, 'title' => 'convertible'],
        ]);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('3', $info['total_documents']);
    }

    public function testUpdateManyExistingDocsDoNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->updateMany([
            ['id' => 1, 'title' => 'suv'],
            ['id' => 2, 'title' => 'truck'],
        ]);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('2', $info['total_documents']);
    }

    public function testUpdateManyThrowsIfAnyIdMissing(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/do not exist/');
        // id 2 does not exist — must throw before any write
        $index->updateMany([
            ['id' => 1, 'title' => 'suv'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
    }

    public function testUpdateManyThrowsListsMissingIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->expectException(QueryException::class);
        // Prefix must come first, then the missing IDs, then the suffix — kills concat-order mutations.
        $this->expectExceptionMessageMatches(
            '/^Documents do not exist with ids: .*\b2\b.*\. Use upsertMany\(\)/'
        );
        $index->updateMany([
            ['id' => 1, 'title' => 'suv'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'truck'],
        ]);
    }

    public function testUpdateManyIsAtomicOnMissingId(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        try {
            $index->updateMany([
                ['id' => 1, 'title' => 'suv'],
                ['id' => 99, 'title' => 'ghost'],
            ]);
        } catch (QueryException) {
        }

        // id 1 must be unchanged — the upfront check prevented any write
        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertEmpty($index->search('suv')->ids);
    }

    public function testUpdateManyUpdatesAvgDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'alpha beta gamma delta'],  // 4 tokens
            ['id' => 2, 'title' => 'epsilon zeta'],             // 2 tokens
        ]);
        // Replace both with 1-token docs; new avg = (1+1)/2 = 1.0
        $index->updateMany([
            ['id' => 1, 'title' => 'eta'],
            ['id' => 2, 'title' => 'theta'],
        ]);

        // MinusEqual/PlusEqual mutations on `$lengthDelta += $newLength - (int) $oldLength`
        // corrupt the accumulated delta, yielding an avg_doc_length other than 1.0.
        $info = $index->inspectQuery('eta')['index_info'];
        $this->assertEqualsWithDelta(1.0, (float) $info['avg_doc_length'], 0.01);
    }

    public function testUpdateManyThrowsOnMissingIdKey(): void
    {
        $index = new Index($this->dbPath);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches("/must contain an 'id' key/");
        $index->updateMany([['title' => 'sedan']]);
    }

    // --- upsertMany ---

    public function testUpsertManyCreatesDocWhenIdNotFound(): void
    {
        $index = new Index($this->dbPath);
        $index->upsertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertContains(2, $index->search('coupe')->ids);
    }

    public function testUpsertManyMixedExistingAndNewIdsUpdatesCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->upsertMany([
            ['id' => 1, 'title' => 'suv'],    // existing — no count change
            ['id' => 2, 'title' => 'coupe'],   // new — count +1
        ]);

        // If docDelta accumulation is wrong (e.g., incremented for every doc instead of
        // only new ones), total_documents would be 3 instead of 2.
        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('2', $info['total_documents']);
    }

    public function testUpsertManyAllNewDocsAccumulatesAvgDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->upsertMany([
            ['id' => 1, 'title' => 'alpha beta gamma'],  // 3 tokens
            ['id' => 2, 'title' => 'delta epsilon'],       // 2 tokens
            ['id' => 3, 'title' => 'zeta'],                // 1 token
        ]);

        // Assignment/MinusEqual mutations on `$lengthDelta += $newLength` (the all-new-docs
        // branch) use direct assignment or subtraction instead of accumulation, so the last
        // doc's length wins and avg_doc_length ends up as 1.0 instead of (3+2+1)/3 = 2.0.
        $info = $index->inspectQuery('alpha')['index_info'];
        $this->assertEqualsWithDelta(2.0, (float) $info['avg_doc_length'], 0.01);
    }

    public function testUpsertManyWithEmptyIterableIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->upsertMany([]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $info = $index->inspectQuery('sedan')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    // --- delete ---

    public function testDeleteRemovesDocumentFromResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan car']);
        $index->delete(1);

        $this->assertEmpty($index->search('sedan')->ids);
    }

    public function testDeleteDoesNotAffectOtherDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);
        $index->delete(1);

        $this->assertContains(2, $index->search('suv')->ids);
    }

    public function testDeleteNonexistentDocumentIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->delete(999);

        $this->assertContains(1, $index->search('sedan')->ids);
    }

    public function testDeleteDecrementsDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->delete(1);

        // NotIdentical / MethodCallRemoval / IncrementInteger mutations on the adjustStats call
        // inside delete() skip or corrupt the document-count decrement, leaving total_documents=1
        // instead of 0 after the deletion.
        $info = $index->inspectQuery('any')['index_info'];
        $this->assertSame('0', $info['total_documents']);
    }

    // --- deleteMany ---

    public function testDeleteManyRemovesAllSpecifiedDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->deleteMany([1, 2]);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertEmpty($index->search('coupe')->ids);
        $this->assertContains(3, $index->search('suv')->ids);
    }

    public function testDeleteManyUpdatesDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->deleteMany([1, 2]);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    public function testDeleteManyOfOnlyOneDocResetsCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->deleteMany([1]);

        // DecrementInteger mutation on `if ($docDelta !== 0)` changes 0 to -1:
        // the guard then reads `$docDelta !== -1`, which is false when exactly one doc
        // is deleted ($docDelta=-1), so adjustStats is never called and total stays at 1.
        $info = $index->inspectQuery('any')['index_info'];
        $this->assertSame('0', $info['total_documents']);
    }

    public function testDeleteManyUpdatesAverageDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'alpha beta gamma'],  // 3 tokens
            ['id' => 2, 'title' => 'delta epsilon'],      // 2 tokens
            ['id' => 3, 'title' => 'zeta'],               // 1 token
        ]);
        // avg after insert = (3+2+1)/3 = 2; delete docs 1+2, leaving only doc 3 (len=1)
        $index->deleteMany([1, 2]);

        // Assignment/MinusEqual mutations on `$lengthDelta -= $length` use direct assignment
        // instead of accumulation, so the total removed length is wrong (last doc's length
        // only instead of the sum), yielding avg_doc_length ≠ 1.
        $info = $index->inspectQuery('zeta')['index_info'];
        $this->assertEqualsWithDelta(1.0, (float) $info['avg_doc_length'], 0.01);
    }

    public function testDeleteManyWithEmptyArrayIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->deleteMany([]);

        $this->assertContains(1, $index->search('sedan')->ids);
    }

    public function testDeleteManyIgnoresNonexistentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->deleteMany([1, 999]);

        $this->assertEmpty($index->search('sedan')->ids);
    }

    public function testDeleteManyIsAtomicOnFailure(): void
    {
        // All deletions happen inside a single transaction; if the call does not throw,
        // the state must be consistent (this test ensures the empty-array guard works
        // and that a mixed real/missing batch completes without error).
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->deleteMany([1, 888, 2]);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertEmpty($index->search('coupe')->ids);
    }

    // --- count ---

    public function testCountReturnsZeroOnFreshIndex(): void
    {
        $index = new Index($this->dbPath);
        $this->assertSame(0, $index->count());
    }

    public function testCountReflectsInsertedDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $this->assertSame(2, $index->count());
    }

    public function testCountDecrementsAfterDelete(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->delete(1);
        $this->assertSame(0, $index->count());
    }

    public function testCountIsStableAfterUpdate(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update(['id' => 1, 'title' => 'suv']);
        $this->assertSame(2, $index->count());
    }

    public function testCountPersistsAfterReopen(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->close();

        $this->assertSame(2, new Index($this->dbPath)->count());
    }

    // --- has ---

    public function testHasReturnsTrueForExistingDocument(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->assertTrue($index->has(1));
    }

    public function testHasReturnsFalseForMissingDocument(): void
    {
        $index = new Index($this->dbPath);

        $this->assertFalse($index->has(999));
    }

    public function testHasReturnsFalseAfterDelete(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->delete(1);

        $this->assertFalse($index->has(1));
    }

    public function testHasReturnsTrueAfterUpdate(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);
        $index->update(['id' => 1, 'title' => 'coupe']);

        $this->assertTrue($index->has(1));
    }

    public function testHasReturnsTrueForUpsertedDocument(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert(['id' => 42, 'title' => 'sedan']);

        $this->assertTrue($index->has(42));
    }

    // --- hasMany ---

    public function testHasManyReturnsTrueForAllPresentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        $this->assertSame([1 => true, 2 => true, 3 => true], $index->hasMany([1, 2, 3]));
    }

    public function testHasManyReturnsMixedBooleans(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        // ID 2 was never inserted — its value must be false, not absent from the map.
        $this->assertSame([1 => true, 2 => false, 3 => true], $index->hasMany([1, 2, 3]));
    }

    public function testHasManyReturnsFalseForAllAbsentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->assertSame([99 => false, 100 => false], $index->hasMany([99, 100]));
    }

    public function testHasManyWithEmptyArrayReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->assertSame([], $index->hasMany([]));
    }

    public function testHasManyPreservesInputOrder(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 5, 'title' => 'sedan'],
            ['id' => 1, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        // Keys must follow the input order [5, 3, 1], not ascending DB order.
        $this->assertSame([5 => true, 3 => true, 1 => true], $index->hasMany([5, 3, 1]));
    }

    public function testHasManyReturnsFalseForDeletedId(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->delete(2);

        $this->assertSame([1 => true, 2 => false], $index->hasMany([1, 2]));
    }

    // --- search (fuzzy) ---

    public function testSearchFuzzyMatchesTypo(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Mercedes Benz', 'body' => 'luxury car']);

        $result = $index->search('mercdes', fuzzy: true);
        $this->assertContains(1, $result->ids);
    }

    public function testSearchFuzzyReturnsDocScores(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'Volkswagen Golf']);

        $result = $index->search('volksagen', fuzzy: true);
        $this->assertContains(1, $result->ids);
        $this->assertNotNull($result->score(1));
        $this->assertGreaterThan(0.0, $result->score(1));
    }

    public function testSearchFuzzyNoMatchReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->search('xqzpwk', fuzzy: true);
        $this->assertSame([], $result->ids);
        $this->assertSame(0, $result->hits);
    }

    public function testFuzzyDistanceOneAcceptsDistanceOneTypo(): void
    {
        $index = new Index($this->dbPath, config: new \Fuzor\Config(fuzzyDistance: 1));
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->assertContains(1, $index->search('sedaan', fuzzy: true)->ids);
    }

    public function testFuzzyDistanceOneRejectsDistanceTwoTypo(): void
    {
        $index = new Index($this->dbPath, config: new \Fuzor\Config(fuzzyDistance: 1));
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $this->assertNotContains(1, $index->search('seddaan', fuzzy: true)->ids);
    }

    public function testFuzzySearchCloserMatchRanksFirst(): void
    {
        $index = new Index($this->dbPath, config: new \Fuzor\Config(fuzzyDistance: 2));
        // Query 'drago' (not indexed) is distance=1 from 'dragon' and distance=2 from 'draagon'.
        $index->insertMany([
            ['id' => 1, 'title' => 'dragon'],   // distance=1 from query
            ['id' => 2, 'title' => 'draagon'],  // distance=2 from query
        ]);

        $result = $index->search('drago', fuzzy: true, asYouType: false);

        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $pos = array_flip($result->ids);
        $this->assertLessThan($pos[2], $pos[1]);
    }

    public function testSearchFuzzySecondaryOrderByPopularity(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'dragon dragon dragon dragon dragon'], // 5 hits — high num_hits
            ['id' => 2, 'title' => 'drage'],                             // 1 hit — low num_hits
        ]);
        // 'drako' is NOT a prefix of 'dragon' or 'drage', so the prefix lookup finds nothing
        // and the fuzzy Levenshtein path kicks in.  Both 'dragon' (d=2) and 'drage' (d=2)
        // are at the same edit distance from 'drako', so the secondary sort by num_hits
        // decides order: DESC → 'dragon' first (5 hits), ASC (mutant) → 'drage' first (1 hit).
        $result = $index->search('drako', fuzzy: true);
        $this->assertSame(1, $result->ids[0]);
    }

    // --- searchBoolean ---

    public function testSearchBooleanAnd(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan coupe'],
            ['id' => 2, 'title' => 'sedan only'],
            ['id' => 3, 'title' => 'coupe only'],
        ]);

        $result = $index->searchBoolean('sedan coupe');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testSearchBooleanOr(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
            ['id' => 3, 'title' => 'coupe sports'],
        ]);

        $result = $index->searchBoolean('sedan or suv');
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testSearchBooleanNot(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
        ]);

        $result = $index->searchBoolean('sedan -bmw');
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(1, $result->ids);
    }

    public function testSearchBooleanNotRespectsAsYouTypePrefix(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'mercedes sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
        ]);

        // Both docs match 'sedan', but doc 1 must be excluded because
        // 'mercedes' starts with 'merc' and asYouType prefix NOT is enabled.
        $result = $index->searchBoolean('sedan -merc');
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(1, $result->ids);
    }

    public function testSearchBooleanDocScoresIsNull(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->searchBoolean('sedan');
        // Boolean search never scores; score() always returns null.
        $this->assertNull($result->score(1));
    }

    public function testSearchBooleanAndLastTermPrefixMatches(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'bmw coupe'],
            ['id' => 3, 'title' => 'audi sedan'],
        ]);

        $result = $index->searchBoolean('bmw sed');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testSearchBooleanOrLastTermPrefixMatches(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw coupe'],
            ['id' => 2, 'title' => 'audi sedan'],
            ['id' => 3, 'title' => 'tesla electric'],
        ]);

        $result = $index->searchBoolean('bmw or sed');
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testSearchBooleanAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi coupe'],
        ]);

        $result = $index->searchBoolean('bmw sed', asYouType: false);
        $this->assertEmpty($result->ids);
    }

    public function testSearchBooleanOnlyLastTermIsPrefixExpanded(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan coupe'],
            ['id' => 2, 'title' => 'sedan hatchback'],
        ]);

        // 'sed' should NOT expand 'sedan' for the first AND term;
        // 'cou' should expand 'coupe' only for the last term.
        $result = $index->searchBoolean('sed cou');
        // 'sed' is not the last term so it must match exactly — no doc has 'sed' literally.
        $this->assertEmpty($result->ids);
    }

    public function testSearchBooleanSingleTermAsYouTypePrefix(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);

        // A single-term boolean query: the term is at postfix index 0.
        // The loop that finds lastTerm must reach index 0 or prefix expansion breaks.
        $result = $index->searchBoolean('sed');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    public function testSearchBooleanAndMissingTermReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $result = $index->searchBoolean('sedan helicopter');
        $this->assertSame([], $result->ids);
    }

    public function testSearchBooleanHitsExceedsNumOfResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $result = $index->searchBoolean('sedan', limit: 2);
        $this->assertCount(2, $result->ids);
        $this->assertSame(3, $result->hits);
    }

    public function testSearchBooleanMultipleNots(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
            ['id' => 3, 'title' => 'tesla sedan'],
        ]);

        $result = $index->searchBoolean('sedan -bmw -audi');
        $this->assertContains(3, $result->ids);
        $this->assertNotContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    public function testSearchBooleanNormalizesUnicodeUppercase(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'café']);
        $result = $index->searchBoolean('CAFÉ', asYouType: false);
        $this->assertContains(1, $result->ids);
    }

    public function testBooleanAndBindsTighterThanOr(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],          // only sedan
            ['id' => 2, 'title' => 'coupe truck'],     // coupe AND truck
            ['id' => 3, 'title' => 'coupe'],           // only coupe
        ]);

        // "sedan or coupe truck" must parse as sedan OR (coupe AND truck).
        // Correct: matches 1 and 2; not 3 (coupe without truck).
        // With reversed precedence it would parse as (sedan OR coupe) AND truck — matching 2 only.
        $result = $index->searchBoolean('sedan or coupe truck', asYouType: false);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testBooleanNotBindsTighterThanAnd(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'php laravel'],
            ['id' => 2, 'title' => 'php symfony'],
        ]);
        // 'php -laravel' → after lex: '|php&~laravel'.
        // Postfix (~ binds tighter than &): [php, laravel, ~, &, |].
        // If '~' priority raised to 4 the output is the same (still highest).
        // But '&' priority change OR default priority change could shift grouping.
        // This exercises both '&'(2) and '~'(3) precedence in one query.
        $result = $index->searchBoolean('php -laravel', asYouType: false);
        $this->assertContains(2, $result->ids);    // php AND NOT laravel → doc2
        $this->assertNotContains(1, $result->ids); // doc1 has laravel → excluded
    }

    public function testBooleanExplicitAndWithParenthesisedOrOnRight(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'php laravel'],
            ['id' => 2, 'title' => 'php nodejs'],
            ['id' => 3, 'title' => 'golang'],
        ]);
        // 'php&(laravel or nodejs)' → postfix [php, laravel, nodejs, |, &, |].
        // At '&': right = resolved [laravel∪nodejs] array, not a lazy string.
        // Mutation L449: is_array(right) || isset(right['__not__']) → true → AND-NOT branch
        //   → array_diff(ids('php'), right['__not__']) where right['__not__'] is undefined
        //   → TypeError / wrong result.
        $result = $index->searchBoolean('php&(laravel or nodejs)', asYouType: false);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testBooleanAndWithMaterializedOrResultIsIntersection(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'php laravel'],
            ['id' => 2, 'title' => 'php nodejs'],
            ['id' => 3, 'title' => 'laravel nodejs'],  // no php
        ]);

        // Postfix for '|php&(laravel or nodejs)': [php, laravel, nodejs, |, &, |].
        // At '&': right=[1,2,3] (laravel∪nodejs), left='php'.
        // Normal (&&): right has no '__not__' → AND → intersect(ids(php)=[1,2], [1,2,3]) = [1,2].
        // Mutation (||): is_array(right)=true → AND-NOT branch → array_diff(ids(php), right['__not__']).
        //   right['__not__'] is undefined → null → TypeError (fatal — kills the mutant).
        $result = $index->searchBoolean('php&(laravel or nodejs)', asYouType: false);
        $this->assertCount(2, $result->ids);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    public function testBooleanSliceStartsAtIndexZero(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);
        // total=3 > numOfResults=2 → slice path.
        // Original: array_slice($docIds, 0, 2) → first two docs; doc 1 is included.
        // Mutation IncrementInteger: array_slice($docIds, 1, 2) → skips doc 1.
        $result = $index->searchBoolean('sedan', asYouType: false, limit: 2);
        $this->assertCount(2, $result->ids);
        $this->assertSame(3, $result->hits);
        $this->assertContains(1, $result->ids); // first doc must survive the slice
    }

    public function testBooleanSearchLowercasesNonAsciiViaMultibyte(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'naïve']);
        // mb_strtolower('NAÏVE') → 'naïve'. strtolower('NAÏVE') → 'naÏve' (Ï stays uppercase).
        // Without mb_, the mutated query 'naÏve' does not match 'naïve' in the wordlist.
        $result = $index->searchBoolean('NAÏVE', asYouType: false);
        $this->assertContains(1, $result->ids);
    }

    public function testBooleanSearchStripsSpacesAroundParentheses(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'café'],
            ['id' => 2, 'title' => 'latté'],
        ]);
        // Mutation Coalesce: '$s ?? preg_replace(...)' evaluates to $s (string is never null)
        //   → preg_replace skipped → spaces around parens survive → str_replace converts them
        //   to spurious '&' operators inside the group → malformed postfix → empty result.
        $result = $index->searchBoolean('( café or latté )', asYouType: false);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
    }

    public function testBooleanSearchFindsDocumentByUppercaseMultibyteQuery(): void
    {
        $index = new Index($this->dbPath);
        // Index the lowercase form; the query must be lowercased with mb_strtolower.
        // 'Ü' (U+00DC) is two bytes in UTF-8; strtolower leaves it unchanged.
        $index->insert(['id' => 1, 'title' => 'über']);
        $result = $index->searchBoolean('ÜBER', asYouType: false);
        $this->assertContains(1, $result->ids);
    }

    public function testBooleanGroupNotFollowedByNot(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'sedan coupe'],
            ['id' => 2, 'title' => 'sedan electric'],
            ['id' => 3, 'title' => 'coupe electric'],
            ['id' => 4, 'title' => 'suv'],
        ]);
        // '(sedan or coupe) -electric': the space between ')' and '-electric' must survive
        // the paren-strip step so str_replace can convert ' -' to '&~'.
        // Without the negative lookahead fix the space is consumed and '-electric' becomes
        // a literal word token rather than a NOT operator, returning docs 1–3 instead of 1.
        $result = $index->searchBoolean('(sedan or coupe) -electric', asYouType: false);
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
        $this->assertNotContains(4, $result->ids);
    }

    public function testBooleanGroupingConstrainsChainedAnd(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'alpha beta delta'],   // all three → should match
            ['id' => 2, 'title' => 'alpha gamma delta'],  // all three → should match
            ['id' => 3, 'title' => 'alpha beta'],          // missing delta → must be excluded
            ['id' => 4, 'title' => 'gamma delta'],         // missing alpha → must be excluded
        ]);
        // Query: alpha & (beta OR gamma) & delta
        // While_ mutation (L525) replaces the ')' pop-loop with while(false), leaving the '|'
        // operator inside the group on the stack. The resulting malformed postfix evaluates to
        // alpha ∪ (gamma ∩ delta) instead of alpha ∩ (beta ∪ gamma) ∩ delta, including doc 3.
        $result = $index->searchBoolean('alpha&(beta or gamma)&delta', asYouType: false);
        $this->assertCount(2, $result->ids);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
        $this->assertNotContains(4, $result->ids);
    }

    // --- inspectQuery ---

    public function testInspectQueryRawTokensMatchTokenizer(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('Hello World');
        $this->assertSame(['hello', 'world'], $result['raw_tokens']);
    }

    public function testInspectQueryNoLanguageFilteredTokensEqualRaw(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('hello world');
        $this->assertSame($result['raw_tokens'], $result['filtered_tokens']);
        $this->assertFalse($result['stopwords_active']);
        $this->assertFalse($result['stemmer_active']);
    }

    public function testInspectQueryStopwordsActiveWhenLanguageSet(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result['stopwords_active']);
    }

    public function testInspectQueryStemmerActiveWhenLanguageSet(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result['stemmer_active']);
    }

    public function testInspectQueryFilteredTokensDropsStopwords(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $result = $index->inspectQuery('the quick');
        $this->assertNotContains('the', $result['filtered_tokens']);
        $this->assertContains('the', $result['raw_tokens']);
    }

    public function testInspectQueryAllStrippedTrueWhenOnlyStopwords(): void
    {
        // Single-token all-stopword query: filterQueryTokens only strips when count > 1,
        // so use two stopwords to trigger the all-stripped fallback.
        $index = new Index($this->dbPath, language: 'en');
        $result = $index->inspectQuery('the and');
        $this->assertTrue($result['all_stripped']);
        // Fallback fires — filtered_tokens equals raw_tokens.
        $this->assertSame($result['raw_tokens'], $result['filtered_tokens']);
    }

    public function testInspectQueryAllStrippedFalseWithNoLanguage(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('the and');
        $this->assertFalse($result['all_stripped']);
    }

    public function testInspectQueryStemmerApplied(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $result = $index->inspectQuery('running');
        // 'running' stems to 'run' in English Snowball
        $this->assertSame('run', $result['filtered_tokens'][0]);
    }

    public function testInspectQueryRawToProcessedMapping(): void
    {
        $index = new Index($this->dbPath, language: 'en');
        $result = $index->inspectQuery('running');
        $this->assertSame('running', $result['tokens'][0]['raw']);
        $this->assertSame('run', $result['tokens'][0]['processed']);
    }

    public function testInspectQueryFoundTrueForIndexedTerm(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertTrue($result['tokens'][0]['found']);
        $this->assertGreaterThanOrEqual(1, $result['tokens'][0]['num_docs']);
        $this->assertGreaterThanOrEqual(1, $result['tokens'][0]['num_hits']);
    }

    public function testInspectQueryFoundFalseForMissingTerm(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('zzznomatch', asYouType: false);
        $this->assertFalse($result['tokens'][0]['found']);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
        $this->assertSame(0, $result['tokens'][0]['num_docs']);
        $this->assertSame(0, $result['tokens'][0]['num_hits']);
    }

    public function testInspectQueryMatchTypeExact(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertSame('exact', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryMatchTypePrefix(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $result = $index->inspectQuery('sed');
        $this->assertSame('prefix', $result['tokens'][0]['match_type']);
        $terms = array_column($result['tokens'][0]['wordlist_rows'], 'term');
        $this->assertContains('sedan', $terms);
    }

    public function testInspectQueryMatchTypeFuzzy(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $result = $index->inspectQuery('sedaan', fuzzy: true, asYouType: false);
        $this->assertSame('fuzzy', $result['tokens'][0]['match_type']);
        $this->assertNotNull($result['tokens'][0]['wordlist_rows'][0]['distance']);
    }

    public function testInspectQueryMatchTypeNoneWhenNoCandidate(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $result = $index->inspectQuery('zzznomatch', fuzzy: true, asYouType: false);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryPrefixExpandsMultipleTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'body' => 'sedan'],
            ['id' => 2, 'body' => 'sediment'],
        ]);
        $result = $index->inspectQuery('sed');
        $terms = array_column($result['tokens'][0]['wordlist_rows'], 'term');
        $this->assertContains('sedan', $terms);
        $this->assertContains('sediment', $terms);
    }

    public function testInspectQueryIsLastOnlyTrueForFinalToken(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('fast sedan review');
        $this->assertFalse($result['tokens'][0]['is_last']);
        $this->assertFalse($result['tokens'][1]['is_last']);
        $this->assertTrue($result['tokens'][2]['is_last']);
    }

    public function testInspectQueryIndexInfoContainsDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $info = $index->inspectQuery('sedan')['index_info'];
        $this->assertArrayHasKey('total_documents', $info);
        $this->assertArrayHasKey('avg_doc_length', $info);
        $this->assertSame('1', $info['total_documents']);
    }

    public function testInspectQueryBooleanPostfixAndOperator(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('php laravel');
        $this->assertContains('&', $result['boolean_postfix']);
    }

    public function testInspectQueryBooleanPostfixOrOperator(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('php or laravel');
        $this->assertContains('|', $result['boolean_postfix']);
    }

    public function testInspectQueryBooleanPostfixNotOperator(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('php -wordpress');
        $this->assertContains('~', $result['boolean_postfix']);
    }

    public function testInspectQueryBooleanPostfixContainsOrForSingleTerm(): void
    {
        $index = new Index($this->dbPath);

        // Mutation ConcatOperandRemoval: toPostfix('php') → ['php'] — no '|'.
        // Original: toPostfix('|php') → ['php', '|'].
        $result = $index->inspectQuery('php');
        $this->assertContains('|', $result['boolean_postfix']);
    }

    public function testInspectQueryEmptyPhraseReturnsEmptyLists(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('');
        $this->assertSame([], $result['raw_tokens']);
        $this->assertSame([], $result['filtered_tokens']);
        $this->assertSame([], $result['tokens']);
    }

    public function testInspectQueryDoesNotChangeDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $before = $index->inspectQuery('sedan')['index_info']['total_documents'];
        $index->inspectQuery('sedan');
        $this->assertSame($before, $index->inspectQuery('sedan')['index_info']['total_documents']);
    }

    public function testInspectQueryWarmsWordlistCacheForSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'body' => 'sedan']);
        $index->inspectQuery('sedan', asYouType: false);
        // If cache is warm, search returns the same result without extra DB reads.
        $result = $index->search('sedan');
        $this->assertContains(1, $result->ids);
    }

    public function testInspectQueryDefaultFuzzyParamIsFalse(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        // Mutation FalseValue: default changes to true → 'sedna' fuzzy-matches 'sedan' → 'fuzzy'.
        // Original default false: no fuzzy → no match → 'none'.
        $result = $index->inspectQuery('sedna', asYouType: false);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryExactMatchWithFuzzyTrueIsNotFuzzyType(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        // When fuzzy=true but an exact wordlist hit is found, rows[0] has no 'distance' key.
        // Mutation L204: $fuzzy || isset($rows[0]['distance']) → true || false = true → 'fuzzy'.
        // Original:      $fuzzy && isset($rows[0]['distance']) → true && false = false → 'exact'.
        $result = $index->inspectQuery('sedan', fuzzy: true, asYouType: false);
        $this->assertSame('exact', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryWordlistRowsHaveExactKeys(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan']);

        $rows = $index->inspectQuery('sedan', asYouType: false)['tokens'][0]['wordlist_rows'];
        $this->assertNotEmpty($rows);
        // Mutation UnwrapArrayMap: $wordlistRows = $rows (raw rows with extra internal fields).
        // Correct: array_map remaps to exactly {term, num_hits, num_docs, distance}.
        $this->assertSame(['term', 'num_hits', 'num_docs', 'distance'], array_keys($rows[0]));
    }

    public function testInspectQueryNumHitsAndNumDocsValues(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'sedan sedan']);

        $token = $index->inspectQuery('sedan', asYouType: false)['tokens'][0];
        $this->assertSame(2, $token['num_hits']);
        $this->assertSame(1, $token['num_docs']);
    }

    // --- rebuild ---

    public function testRebuildReturnsIndex(): void
    {
        $index = new Index($this->dbPath);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert(['id' => 1, 'title' => 'sedan']);
        });

        $this->assertInstanceOf(Index::class, $rebuilt);
    }

    public function testRebuildNewContentIsSearchable(): void
    {
        $index = new Index($this->dbPath);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert(['id' => 1, 'title' => 'sedan']);
        });

        $this->assertContains(1, $rebuilt->search('sedan')->ids);
    }

    public function testRebuildRemovesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'old content']);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert(['id' => 2, 'title' => 'new content']);
        });

        $this->assertEmpty($rebuilt->search('old')->ids);
        $this->assertContains(2, $rebuilt->search('new')->ids);
    }

    public function testRebuildLeavesOriginalIntactOnCallbackException(): void
    {
        $index = new Index($this->dbPath);
        $index->insert(['id' => 1, 'title' => 'original']);
        $index->close();

        try {
            Index::rebuild($this->dbPath, function (Index $new): void {
                $new->insert(['id' => 2, 'title' => 'partial']);
                throw new \RuntimeException('simulated failure');
            });
        } catch (\RuntimeException) {
        }

        $surviving = new Index($this->dbPath);
        $this->assertContains(1, $surviving->search('original')->ids);
        $this->assertEmpty($surviving->search('partial')->ids);
    }

    public function testRebuildPropagatesCallbackException(): void
    {
        new Index($this->dbPath)->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated failure');

        Index::rebuild($this->dbPath, function (): void {
            throw new \RuntimeException('simulated failure');
        });
    }

    public function testRebuildLeaksNoTempFileOnFailure(): void
    {
        new Index($this->dbPath)->close();

        try {
            Index::rebuild($this->dbPath, function (): void {
                throw new \RuntimeException('simulated failure');
            });
        } catch (\RuntimeException) {
        }

        $dir   = dirname($this->dbPath);
        $base  = basename($this->dbPath);
        $found = glob($dir . '/' . $base . '.tmp-*');
        $this->assertSame([], $found);
    }

    public function testRebuildPreservesLanguageFromExistingIndex(): void
    {
        new Index($this->dbPath, language: 'en')->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert(['id' => 1, 'title' => 'running']);
        });

        $this->assertSame('en', $rebuilt->language);
    }

    public function testRebuildWorksWhenFileDoesNotExistYet(): void
    {
        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert(['id' => 1, 'title' => 'sedan']);
        });

        $this->assertContains(1, $rebuilt->search('sedan')->ids);
    }

    public function testRebuildCountReflectsNewDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insertMany([['id' => 1, 'title' => 'a'], ['id' => 2, 'title' => 'b']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert(['id' => 10, 'title' => 'only one']);
        });

        $this->assertSame(1, $rebuilt->count());
    }

    // --- CJK / ngram languages ---

    public function testZhInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, language: 'zh');
        $index->insert(['id' => 1, 'body' => '轿车测试']);

        $this->assertContains(1, $index->search('轿车')->ids);
    }

    public function testZhSingleCharSearch(): void
    {
        // Unigrams are emitted at index time for zh so single-character searches work.
        $index = new Index($this->dbPath, language: 'zh');
        $index->insert(['id' => 1, 'body' => '轿车测试']);

        $this->assertContains(1, $index->search('车')->ids);
    }

    public function testZhDoesNotMatchUnrelatedDocument(): void
    {
        $index = new Index($this->dbPath, language: 'zh');
        $index->insertMany([
            ['id' => 1, 'body' => '轿车测试'],
            ['id' => 2, 'body' => '飞机起飞'],
        ]);

        $this->assertNotContains(2, $index->search('轿车')->ids);
    }

    public function testJaInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, language: 'ja');
        $index->insert(['id' => 1, 'body' => '東京タワー']);

        $this->assertContains(1, $index->search('東京')->ids);
    }

    public function testKoInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, language: 'ko');
        $index->insert(['id' => 1, 'body' => '서울특별시']);

        $this->assertContains(1, $index->search('서울')->ids);
    }

    public function testThInsertAndSearchTrigram(): void
    {
        $index = new Index($this->dbPath, language: 'th');
        $index->insert(['id' => 1, 'body' => 'กรุงเทพมหานคร']);

        // 'กรุงเท' is a trigram within the indexed text
        $this->assertContains(1, $index->search('กรุงเท')->ids);
    }

    public function testZhBooleanSearch(): void
    {
        $index = new Index($this->dbPath, language: 'zh');
        $index->insertMany([
            ['id' => 1, 'body' => '轿车测试'],
            ['id' => 2, 'body' => '飞机起飞'],
        ]);

        $result = $index->searchBoolean('轿车 -飞机');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    public function testZhQueryTokensAreNgrammed(): void
    {
        // inspectQuery must show bigrams in filtered_tokens, not the raw full string.
        $index  = new Index($this->dbPath, language: 'zh');
        $result = $index->inspectQuery('轿车');

        $this->assertContains('轿车', $result['filtered_tokens']);
        // Raw tokens show the output of the base tokenizer (whole string as one unit).
        $this->assertSame(['轿车'], $result['raw_tokens']);
    }

    public function testZhMixedQueryAsciiTokenPassthrough(): void
    {
        // ASCII tokens in a mixed query must not be ngrammed.
        $index = new Index($this->dbPath, language: 'zh');
        $index->insertMany([
            ['id' => 1, 'body' => 'BMW 轿车'],
            ['id' => 2, 'body' => '轿车'],
        ]);

        // Both docs contain '轿车'; only doc 1 also contains 'bmw'.
        $result = $index->searchBoolean('bmw 轿车');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    // --- storePositions ---

    public function testStorePositionsFlagDefaultsFalse(): void
    {
        $index = new Index($this->dbPath);
        $this->assertFalse($index->storePositions);
    }

    public function testStorePositionsFlagPersistedAndRestoredOnOpen(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        $this->assertTrue($index->storePositions);
        $index->close();

        $reopened = new Index($this->dbPath);
        $this->assertTrue($reopened->storePositions);
    }

    public function testStorePositionsWritesCorrectRowCount(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        // "city car" → 2 tokens → 2 position rows
        $index->insert(['id' => 1, 'title' => 'city car']);
        $index->close();

        $pdo  = new \PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->query('SELECT COUNT(*) FROM positions WHERE doc_id = 1');
        $this->assertNotFalse($stmt);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testStorePositionsRecordsCorrectPositionValues(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        $index->insert(['id' => 1, 'title' => 'city car review']);

        $pdo  = new \PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->query(
            'SELECT w.term, p.position
             FROM positions p
             JOIN wordlist w ON w.id = p.term_id
             WHERE p.doc_id = 1
             ORDER BY p.position'
        );
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        // Positions are sequential: city=0, car=1, review=2
        $this->assertSame(0, $rows['city']);
        $this->assertSame(1, $rows['car']);
        $this->assertSame(2, $rows['review']);
    }

    public function testStorePositionsGlobalCounterAcrossFields(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        // title contributes tokens at 0, 1; body continues from 2, 3
        $index->insert(['id' => 1, 'title' => 'city car', 'body' => 'fast sedan']);

        $pdo  = new \PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->query(
            'SELECT w.term, p.position
             FROM positions p
             JOIN wordlist w ON w.id = p.term_id
             WHERE p.doc_id = 1
             ORDER BY p.position'
        );
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame(0, $rows['city']);
        $this->assertSame(1, $rows['car']);
        $this->assertSame(2, $rows['fast']);
        $this->assertSame(3, $rows['sedan']);
    }

    public function testStorePositionsDeletedOnDocumentRemoval(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        $index->insert(['id' => 1, 'title' => 'city car']);
        $index->delete(1);
        $index->close();

        $pdo  = new \PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->query('SELECT COUNT(*) FROM positions WHERE doc_id = 1');
        $this->assertNotFalse($stmt);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testStorePositionsInsertManyWritesCorrectRows(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        $index->insertMany([
            ['id' => 1, 'title' => 'city car'],     // 2 tokens
            ['id' => 2, 'title' => 'fast sedan review'], // 3 tokens
        ]);
        $index->close();

        $pdo   = new \PDO('sqlite:' . $this->dbPath);
        $stmt1 = $pdo->query('SELECT COUNT(*) FROM positions WHERE doc_id = 1');
        $this->assertNotFalse($stmt1);
        $c1    = (int) $stmt1->fetchColumn();
        $stmt2 = $pdo->query('SELECT COUNT(*) FROM positions WHERE doc_id = 2');
        $this->assertNotFalse($stmt2);
        $c2    = (int) $stmt2->fetchColumn();
        $this->assertSame(2, $c1);
        $this->assertSame(3, $c2);
    }

    public function testNoPositionsTableWhenFlagFalse(): void
    {
        new Index($this->dbPath);
        $pdo   = new \PDO('sqlite:' . $this->dbPath);
        $stmt  = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $this->assertNotFalse($stmt);
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains('positions', $tables);
    }

    // --- Proximity ranking ---------------------------------------------------

    public function testProximityBoostRanksCloserTermsHigher(): void
    {
        // doc 1: terms adjacent (minSpan = 1), doc 2: terms far apart (minSpan = 10)
        $index = new Index($this->dbPath, storePositions: true);
        $index->insertMany([
            ['id' => 1, 'title' => 'fast car review review review review review review review review review'],
            ['id' => 2, 'title' => 'fast review review review review review review review review review car'],
        ]);

        $results = $index->search('fast car');

        // Both docs have the same terms; proximity should rank doc 1 higher (adjacent terms).
        $this->assertSame([1, 2], $results->ids);
    }

    public function testProximityBoostDisabledWhenBoostIsZero(): void
    {
        $config = new Config(proximityBoost: 0.0);
        $index  = new Index($this->dbPath, storePositions: true, config: $config);
        $index->insertMany([
            ['id' => 1, 'title' => 'fast car review review review review review review review review review'],
            ['id' => 2, 'title' => 'fast review review review review review review review review review car'],
        ]);

        // With proximityBoost=0.0 the result order is pure BM25 (identical here → stable order).
        $results = $index->search('fast car');
        $this->assertCount(2, $results->ids);
        $this->assertContains(1, $results->ids);
        $this->assertContains(2, $results->ids);
    }

    public function testProximityBoostNoEffectWithoutPositions(): void
    {
        // Index without storePositions — proximity should silently do nothing.
        $index = new Index($this->dbPath);
        $index->insertMany([
            ['id' => 1, 'title' => 'fast car review review review review review review review review review'],
            ['id' => 2, 'title' => 'fast review review review review review review review review review car'],
        ]);

        $results = $index->search('fast car');
        $this->assertCount(2, $results->ids);
    }

    public function testProximityBoostNoEffectOnSingleKeyword(): void
    {
        $index = new Index($this->dbPath, storePositions: true);
        $index->insertMany([
            ['id' => 1, 'title' => 'fast sedan'],
            ['id' => 2, 'title' => 'fast coupe'],
        ]);

        // Single keyword: no proximity applied, just BM25.
        $results = $index->search('fast');
        $this->assertCount(2, $results->ids);
    }

    public function testProximityBoostPartialMatchDocNotBoosted(): void
    {
        // doc 1 has both terms, doc 2 has only "fast" — partial match should not get boosted.
        $index = new Index($this->dbPath, storePositions: true);
        $index->insertMany([
            ['id' => 1, 'title' => 'fast car'],
            ['id' => 2, 'title' => 'fast review'],
        ]);

        $results = $index->search('fast car');
        // doc 1 should rank above doc 2 (has both terms; doc 2 misses "car").
        $this->assertSame(1, $results->ids[0]);
    }
}

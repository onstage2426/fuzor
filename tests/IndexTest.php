<?php

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetRange;
use Fuzor\Index;
use Fuzor\SchemaConfig;
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
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->close();

        $reopened = new Index($this->dbPath);
        $this->assertContains(1, $reopened->search('sedan')->ids);
    }

    public function testStatsPersistAfterReopen(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        new Index('/nonexistent/dir/index.db', schema: new SchemaConfig(language: 'xx'));
    }

    public function testCreateWithForceOverwritesExistingFile(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
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
        $index->insert([['title' => 'no id here']]);
    }

    public function testInsertDuplicateIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        $index->insert([['id' => 1, 'title' => 'duplicate']]);
    }

    public function testInsertManyWithMissingIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['title' => 'no id'],
        ]);
    }

    public function testInsertManyWithDuplicateInputIdsThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 1, 'title' => 'duplicate in same batch'],
        ]);
    }

    public function testInsertManyWithExistingIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        // Require the full structure: prefix, then the conflicting ID, then the suffix.
        // This kills concat-order mutations (ID moved to front/end) and partial-removal
        // mutations (missing ID or missing "Use update()" suffix).
        $this->expectExceptionMessageMatches('#^Document 1 already exists\. Use update\(\)#');
        $index->insert([['id' => 1, 'title' => 'already exists']]);
    }

    public function testUpdateWithoutIdThrows(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(QueryException::class);
        $index->update([['title' => 'no id here']]);
    }

    public function testInsertAndSearchReturnsMatchingId(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'fast sedan', 'body' => 'comfortable city car']]);

        $result = $index->search('sedan');
        $this->assertContains(1, $result->ids);
    }

    public function testStoredOnlyFieldIsNotIndexed(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(searchableFields: ['title']));
        $index->insert([[
            'id'        => 1,
            'title'     => 'sedan',
            'permalink' => '/cars/sedan',
            'unique'    => 'shouldnotbeindexed',
        ]]);

        $this->assertSame([1], $index->search('sedan')->ids);
        $this->assertSame([], $index->search('shouldnotbeindexed')->ids);
        $this->assertSame([], $index->search('permalink')->ids);
    }

    public function testStoredOnlyFieldIsPreservedInDocumentStore(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'permalink' => '/cars/sedan', 'published' => '2026-05-14'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true, searchableFields: ['title']));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->search('sedan')->document(1));
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);

        $result = $index->search('helicopter');
        $this->assertSame([], $result->ids);
        $this->assertSame(0, $result->hits);
    }

    public function testSearchReturnsBm25Scores(): void
    {
        $index = new Index($this->dbPath);
        // Two docs so the term is not universal; smoothed IDF is always > 0 anyway.
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);

        $result = $index->search('sedan');
        $this->assertNotNull($result->score(1));
        $this->assertGreaterThan(0.0, $result->score(1));
    }

    public function testHasScoresTrueForBm25Search(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertTrue($index->search('sedan')->hasScores());
    }

    public function testHasScoresFalseForBooleanSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertFalse($index->searchBoolean('sedan')->hasScores());
    }

    public function testScoresReturnsFullMap(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'sedan coupe'],
        ]);

        $result = $index->search('sedan');
        $scores = $result->scores();
        $this->assertArrayHasKey(1, $scores);
        $this->assertArrayHasKey(2, $scores);
        $this->assertSame($result->score(1), $scores[1]);
        $this->assertSame($result->score(2), $scores[2]);
    }

    public function testScoresEmptyForBooleanSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([], $index->searchBoolean('sedan')->scores());
    }

    public function testSearchHitsCountsAllMatchingDocs(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'sedan']]); // cache miss: INSERT path, termIdCache seeded
        $index->insert([['id' => 2, 'title' => 'sedan']]); // cache hit: UPDATE path in upsertWordlist()
        $index->delete(1);

        // If the cache-hit UPDATE was a no-op, wordlist num_hits for 'sedan' would still be 1
        // after the second insert. Deleting doc 1 (1 hit) would zero it and prune the term.
        $this->assertContains(2, $index->search('sedan')->ids);
    }

    public function testSearchRespectsNumOfResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert(array_map(fn($i): array => ['id' => $i, 'title' => 'sedan'], range(1, 101)));

        $result = $index->search('sedan');
        $this->assertCount(100, $result->ids);
        $this->assertSame(101, $result->hits);

        $resultBool = $index->searchBoolean('sedan');
        $this->assertCount(100, $resultBool->ids);
        $this->assertSame(101, $resultBool->hits);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'SEDAN']]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertContains(1, $index->search('SEDAN')->ids);
    }

    public function testSearchLowercasesMultibyteQueryViaSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'über']]);

        // MBString mutation in getWordlistByKeyword replaces mb_strtolower with strtolower.
        // 'Ü' (U+00DC) is two UTF-8 bytes; strtolower leaves it unchanged, so 'ÜBER' stays
        // uppercase and doesn't match the lowercase-stored term 'über'.
        // searchBoolean already lowercases in lexExpression, so this test must use search().
        $this->assertContains(1, $index->search('ÜBER', asYouType: false)->ids);
    }

    public function testShortWordsSkipFuzzyGate(): void
    {
        // 'helo' is 4 codepoints — below the default fuzzyMinWordLength of 5.
        // It must not trigger the Levenshtein fallback and must return no results.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'hello']]);

        $this->assertEmpty($index->search('helo', asYouType: false)->ids);
    }

    public function testLongWordTypoFuzzyFires(): void
    {
        // 'hellow' is 6 codepoints — above the default fuzzyMinWordLength of 5.
        // The Levenshtein fallback should fire and match 'hello'.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'hello']]);

        $this->assertContains(1, $index->search('hellow', asYouType: false)->ids);
    }

    public function testSearchOrdersByRelevance(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([
            ['id' => 1, 'title' => 'car sedan'],           // both terms, dl=2
            ['id' => 2, 'title' => str_repeat('sedan ', 10)],  // sedan only, tf=10, dl=10
        ]);
        // Doc 1 earns score for both 'car' (rare term, high IDF) and 'sedan'.
        // Doc 2 earns only a sedan score (high TF but heavily length-penalised).
        // Without accumulation the coalesce bug resets doc1 to sedan-only, so doc2 wins.
        $result = $index->search('car sedan', asYouType: false);
        $this->assertSame(1, $result->ids[0]);
    }

    public function testMatchCountTierRanksFullMatchAbovePartialMatch(): void
    {
        // Corpus: 40 filler docs containing 'common', driving IDF(common) very low (~0.035).
        // Doc 1 matches only 'rare' with high TF → raw BM25 ≈ 4.5.
        // Doc 2 matches both 'rare' and 'common' with TF=1 → raw BM25 ≈ 3.1.
        // Without the match-count tier, doc 1 wins on raw BM25. With the tier,
        // doc 2 (matchCount=2) must rank above doc 1 (matchCount=1).
        $index = new Index($this->dbPath);
        $fillers = array_map(
            fn(int $i): array => ['id' => $i, 'title' => "common filler{$i}"],
            range(10, 49),
        );
        $index->insert($fillers);
        $index->insert([
            ['id' => 1, 'title' => str_repeat('rare ', 20)], // high TF for 'rare', no 'common'
            ['id' => 2, 'title' => 'rare common'],            // both terms, low TF
        ]);

        $result = $index->search('rare common', asYouType: false);

        $this->assertSame(2, $result->ids[0], 'Doc matching both query terms must rank first');
    }

    public function testSearchReturnsEmptyIdsWhenNumOfResultsIsZero(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $result = $index->search('sedan', limit: 0);
        $this->assertSame([], $result->ids);
        $this->assertSame(1, $result->hits);
    }

    // --- as-you-type prefix ---

    public function testAsYouTypePrefixMatchesPartialWord(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);

        $result = $index->search('merc');
        $this->assertContains(1, $result->ids);
    }

    public function testAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);

        $result = $index->search('merc', asYouType: false);
        $this->assertNotContains(1, $result->ids);
    }

    public function testPrefixSearchExpandsAllMatchingTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $counter = count($result->ids);
        // Results must be in descending score order.
        for ($i = 1; $i < $counter; $i++) {
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->search('sedan', limit: 10, offset: 5);

        $this->assertSame([], $result->ids);
        $this->assertSame(1, $result->hits);
    }

    public function testSearchBooleanOffsetSkipsTopResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertContains(2, $index->search('suv')->ids);
    }

    public function testInsertManyWithEmptyArrayIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([]);

        $this->assertSame(0, $index->search('anything')->hits);
    }

    // --- update ---

    public function testUpdateReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);
        $index->update([['id' => 1, 'title' => 'suv truck']]);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertContains(1, $index->search('suv')->ids);
    }

    public function testUpdatePreservesTotalDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update([['id' => 1, 'title' => 'suv']]);

        $this->assertSame(2, $index->search('suv')->hits + $index->search('coupe')->hits);
    }

    public function testUpdateExistingDocDoesNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update([['id' => 1, 'title' => 'suv']]);

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
        $index->update([['id' => 999, 'title' => 'sedan']]);
    }

    public function testUpdateChangesAvgDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'alpha beta gamma delta']]);
        $index->update([['id' => 1, 'title' => 'zeta']]);
        $info = $index->inspectQuery('zeta')['index_info'];
        $this->assertEqualsWithDelta(1.0, (float) $info['avg_doc_length'], 0.01);
    }

    // --- upsert ---

    public function testUpsertCreatesDocWhenIdNotFound(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert([['id' => 999, 'title' => 'sedan']]);

        $this->assertContains(999, $index->search('sedan')->ids);
    }

    public function testUpsertNonExistentDocIncrementsCount(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert([['id' => 1, 'title' => 'sedan']]);
        $info = $index->inspectQuery('sedan')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    public function testUpsertReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);
        $index->upsert([['id' => 1, 'title' => 'suv truck']]);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertContains(1, $index->search('suv')->ids);
    }

    public function testUpsertExistingDocDoesNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->upsert([['id' => 1, 'title' => 'suv']]);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('2', $info['total_documents']);
    }

    // --- updateMany ---

    public function testUpdateManyReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'coupe sport'],
        ]);
        $index->update([
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
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->update([
            ['id' => 1, 'title' => 'hatchback'],
            ['id' => 2, 'title' => 'convertible'],
        ]);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('3', $info['total_documents']);
    }

    public function testUpdateManyExistingDocsDoNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update([
            ['id' => 1, 'title' => 'suv'],
            ['id' => 2, 'title' => 'truck'],
        ]);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('2', $info['total_documents']);
    }

    public function testUpdateManyThrowsIfAnyIdMissing(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/do not exist/');
        // id 2 does not exist — must throw before any write
        $index->update([
            ['id' => 1, 'title' => 'suv'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
    }

    public function testUpdateManyThrowsListsMissingIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        // Prefix must come first, then the missing IDs, then the suffix — kills concat-order mutations.
        $this->expectExceptionMessageMatches(
            '/^Documents do not exist with ids: .*\b2\b.*\. Use upsert\(\)/'
        );
        $index->update([
            ['id' => 1, 'title' => 'suv'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'truck'],
        ]);
    }

    public function testUpdateManyIsAtomicOnMissingId(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        try {
            $index->update([
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
        $index->insert([
            ['id' => 1, 'title' => 'alpha beta gamma delta'],  // 4 tokens
            ['id' => 2, 'title' => 'epsilon zeta'],             // 2 tokens
        ]);
        // Replace both with 1-token docs; new avg = (1+1)/2 = 1.0
        $index->update([
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
        $index->update([['title' => 'sedan']]);
    }

    // --- upsertMany ---

    public function testUpsertManyCreatesDocWhenIdNotFound(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $this->assertContains(2, $index->search('coupe')->ids);
    }

    public function testUpsertManyMixedExistingAndNewIdsUpdatesCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->upsert([
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
        $index->upsert([
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
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->upsert([]);

        $this->assertContains(1, $index->search('sedan')->ids);
        $info = $index->inspectQuery('sedan')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    // --- delete ---

    public function testDeleteRemovesDocumentFromResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);
        $index->delete(1);

        $this->assertEmpty($index->search('sedan')->ids);
    }

    public function testDeleteDoesNotAffectOtherDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);
        $index->delete(1);

        $this->assertContains(2, $index->search('suv')->ids);
    }

    public function testDeleteNonexistentDocumentIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(999);

        $this->assertContains(1, $index->search('sedan')->ids);
    }

    public function testDeleteDecrementsDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
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
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->delete(1, 2);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertEmpty($index->search('coupe')->ids);
        $this->assertContains(3, $index->search('suv')->ids);
    }

    public function testDeleteManyUpdatesDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->delete(1, 2);

        $info = $index->inspectQuery('suv')['index_info'];
        $this->assertSame('1', $info['total_documents']);
    }

    public function testDeleteManyOfOnlyOneDocResetsCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1);

        // DecrementInteger mutation on `if ($docDelta !== 0)` changes 0 to -1:
        // the guard then reads `$docDelta !== -1`, which is false when exactly one doc
        // is deleted ($docDelta=-1), so adjustStats is never called and total stays at 1.
        $info = $index->inspectQuery('any')['index_info'];
        $this->assertSame('0', $info['total_documents']);
    }

    public function testDeleteManyUpdatesAverageDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'alpha beta gamma'],  // 3 tokens
            ['id' => 2, 'title' => 'delta epsilon'],      // 2 tokens
            ['id' => 3, 'title' => 'zeta'],               // 1 token
        ]);
        // avg after insert = (3+2+1)/3 = 2; delete docs 1+2, leaving only doc 3 (len=1)
        $index->delete(1, 2);

        // Assignment/MinusEqual mutations on `$lengthDelta -= $length` use direct assignment
        // instead of accumulation, so the total removed length is wrong (last doc's length
        // only instead of the sum), yielding avg_doc_length ≠ 1.
        $info = $index->inspectQuery('zeta')['index_info'];
        $this->assertEqualsWithDelta(1.0, (float) $info['avg_doc_length'], 0.01);
    }

    public function testDeleteManyWithEmptyArrayIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete();

        $this->assertContains(1, $index->search('sedan')->ids);
    }

    public function testDeleteManyIgnoresNonexistentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1, 999);

        $this->assertEmpty($index->search('sedan')->ids);
    }

    public function testDeleteManyIsAtomicOnFailure(): void
    {
        // All deletions happen inside a single transaction; if the call does not throw,
        // the state must be consistent (this test ensures the empty-array guard works
        // and that a mixed real/missing batch completes without error).
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->delete(1, 888, 2);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertEmpty($index->search('coupe')->ids);
    }

    // --- clear ---

    public function testClearRemovesAllDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);
        $index->clear();

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertEmpty($index->search('coupe')->ids);
        $this->assertEmpty($index->search('suv')->ids);
    }

    public function testClearResetsDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->clear();

        $this->assertSame(0, $index->count());
    }

    public function testClearResetsAverageDocLength(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'alpha beta gamma'],
            ['id' => 2, 'title' => 'delta epsilon'],
        ]);
        $index->clear();

        $info = $index->inspectQuery('any')['index_info'];
        $this->assertEqualsWithDelta(0.0, (float) $info['avg_doc_length'], 0.001);
    }

    public function testClearAllowsReinsertionAfterwards(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->clear();
        $index->insert([['id' => 1, 'title' => 'coupe']]);

        $this->assertEmpty($index->search('sedan')->ids);
        $this->assertContains(1, $index->search('coupe')->ids);
        $this->assertSame(1, $index->count());
    }

    public function testClearOnEmptyIndexIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->clear();

        $this->assertSame(0, $index->count());
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
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $this->assertSame(2, $index->count());
    }

    public function testCountDecrementsAfterDelete(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1);
        $this->assertSame(0, $index->count());
    }

    public function testCountIsStableAfterUpdate(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update([['id' => 1, 'title' => 'suv']]);
        $this->assertSame(2, $index->count());
    }

    public function testCountPersistsAfterReopen(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->close();

        $this->assertSame(2, new Index($this->dbPath)->count());
    }

    // --- lastModified ---

    public function testLastModifiedReturnsNonZeroAfterCreate(): void
    {
        new Index($this->dbPath);
        $this->assertGreaterThan(0, Index::lastModified($this->dbPath));
    }

    public function testLastModifiedIsWithinReasonableRange(): void
    {
        $before = time();
        new Index($this->dbPath);
        $after  = time();

        $mtime = Index::lastModified($this->dbPath);
        $this->assertGreaterThanOrEqual($before, $mtime);
        $this->assertLessThanOrEqual($after + 1, $mtime);
    }

    public function testLastModifiedReturnsZeroForMissingFile(): void
    {
        $this->assertSame(0, Index::lastModified('/nonexistent/path.db'));
    }

    public function testLastModifiedAdvancesAfterWriteAndClose(): void
    {
        // Backdate the file so any checkpoint write is detectable regardless of clock granularity.
        $index = new Index($this->dbPath);
        $index->close();
        touch($this->dbPath, time() - 10);
        clearstatcache();

        $before = Index::lastModified($this->dbPath);

        // A write followed by close triggers a WAL checkpoint, which writes pages back to the
        // main DB file and updates its mtime.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->close();
        clearstatcache();

        $this->assertGreaterThan($before, Index::lastModified($this->dbPath));
    }

    // --- has ---

    public function testHasReturnsTrueForExistingDocument(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

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
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1);

        $this->assertFalse($index->has(1));
    }

    public function testHasReturnsTrueAfterUpdate(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->update([['id' => 1, 'title' => 'coupe']]);

        $this->assertTrue($index->has(1));
    }

    public function testHasReturnsTrueForUpsertedDocument(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert([['id' => 42, 'title' => 'sedan']]);

        $this->assertTrue($index->has(42));
    }

    // --- hasMany ---

    public function testHasManyReturnsTrueForAllPresentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        $this->assertSame([1 => true, 2 => true, 3 => true], $index->has(1, 2, 3));
    }

    public function testHasManyReturnsMixedBooleans(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        // ID 2 was never inserted — its value must be false, not absent from the map.
        $this->assertSame([1 => true, 2 => false, 3 => true], $index->has(1, 2, 3));
    }

    public function testHasManyReturnsFalseForAllAbsentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([99 => false, 100 => false], $index->has(99, 100));
    }

    public function testHasManyWithEmptyArrayReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([], $index->has());
    }

    public function testHasManyPreservesInputOrder(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 5, 'title' => 'sedan'],
            ['id' => 1, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        // Keys must follow the input order [5, 3, 1], not ascending DB order.
        $this->assertSame([5 => true, 3 => true, 1 => true], $index->has(5, 3, 1));
    }

    public function testHasManyReturnsFalseForDeletedId(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->delete(2);

        $this->assertSame([1 => true, 2 => false], $index->has(1, 2));
    }

    // --- search (fuzzy) ---

    public function testSearchFuzzyMatchesTypo(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz', 'body' => 'luxury car']]);

        $result = $index->search('mercdes');
        $this->assertContains(1, $result->ids);
    }

    public function testSearchFuzzyReturnsDocScores(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Volkswagen Golf']]);

        $result = $index->search('volksagen');
        $this->assertContains(1, $result->ids);
        $this->assertNotNull($result->score(1));
        $this->assertGreaterThan(0.0, $result->score(1));
    }

    public function testSearchFuzzyNoMatchReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->search('xqzpwk');
        $this->assertSame([], $result->ids);
        $this->assertSame(0, $result->hits);
    }

    public function testFuzzyMinWordLengthGateBlocksShortWords(): void
    {
        // Default fuzzyMinWordLength = 5. 'sedn' is 4 codepoints — gate blocks the fallback.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertEmpty($index->search('sedn', asYouType: false)->ids);
    }

    public function testFuzzyMinWordLengthGateAllowsLongWords(): void
    {
        // 'sedaan' is 6 codepoints — above the default gate; Levenshtein fires and matches.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertContains(1, $index->search('sedaan', asYouType: false)->ids);
    }

    public function testFuzzyMinWordLengthConfigurable(): void
    {
        // With minWordLength=3, even 'sedn' (4 chars) triggers the fuzzy fallback.
        // 'sedn' shares the 'sed' prefix with 'sedan' (required by fuzzyPrefixLength=3)
        // and is at Levenshtein distance=1 → matched by the 5–8 char tier (1 typo allowed).
        $index = new Index($this->dbPath, config: new \Fuzor\Config(fuzzyMinWordLength: 3));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertContains(1, $index->search('sedn', asYouType: false)->ids);
    }

    public function testFuzzyAutoTierShortWordCappedAtOneTypo(): void
    {
        // 'seddaan' (7 chars) is distance=2 from 'sedan'. Words < 9 codepoints are capped at
        // 1 typo, so this must NOT match.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertNotContains(1, $index->search('seddaan')->ids);
    }

    public function testFuzzyAutoTierLongWordAllowsTwoTypos(): void
    {
        // 'volkswaagen' (11 chars) is distance=2 from 'volkswagen'. Words ≥ 9 codepoints allow
        // 2 typos, so this MUST match. 'volkswaagen' starts with 'vol' (prefix ✓).
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'volkswaagen']]);

        $this->assertContains(1, $index->search('volkswagen')->ids);
    }

    public function testFuzzySearchCloserMatchRanksFirst(): void
    {
        $index = new Index($this->dbPath);
        // Query 'volkswage' (9 chars) → effective distance=2; 'volkswagen' is d=1, 'volkswaagen' is d=2.
        // Both share the 'vol' prefix required by fuzzyPrefixLength=3.
        $index->insert([
            ['id' => 1, 'title' => 'volkswagen'],   // distance=1 from query
            ['id' => 2, 'title' => 'volkswaagen'],  // distance=2 from query
        ]);

        $result = $index->search('volkswage', asYouType: false);

        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $pos = array_flip($result->ids);
        $this->assertLessThan($pos[2], $pos[1]);
    }

    public function testSearchFuzzySecondaryOrderByPopularity(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'drake drake drake drake drake'], // 5 hits — high num_hits
            ['id' => 2, 'title' => 'draka'],                        // 1 hit — low num_hits
        ]);
        // 'drako' is NOT a prefix of 'drake' or 'draka', so the prefix lookup finds nothing
        // and the fuzzy Levenshtein path kicks in. Both 'drake' (d=1) and 'draka' (d=1)
        // are at the same edit distance from 'drako', so the secondary sort by num_hits
        // decides order: DESC → 'drake' first (5 hits), ASC (mutant) → 'draka' first (1 hit).
        $result = $index->search('drako');
        $this->assertSame(1, $result->ids[0]);
    }

    // --- searchBoolean ---

    public function testSearchBooleanAnd(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->searchBoolean('sedan');
        // Boolean search never scores; score() always returns null.
        $this->assertNull($result->score(1));
    }

    public function testSearchBooleanAndLastTermPrefixMatches(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi coupe'],
        ]);

        $result = $index->searchBoolean('bmw sed', asYouType: false);
        $this->assertEmpty($result->ids);
    }

    public function testSearchBooleanOnlyLastTermIsPrefixExpanded(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->searchBoolean('sedan helicopter');
        $this->assertSame([], $result->ids);
    }

    public function testSearchBooleanHitsExceedsNumOfResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'café']]);
        $result = $index->searchBoolean('CAFÉ', asYouType: false);
        $this->assertContains(1, $result->ids);
    }

    public function testBooleanAndBindsTighterThanOr(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'naïve']]);
        // mb_strtolower('NAÏVE') → 'naïve'. strtolower('NAÏVE') → 'naÏve' (Ï stays uppercase).
        // Without mb_, the mutated query 'naÏve' does not match 'naïve' in the wordlist.
        $result = $index->searchBoolean('NAÏVE', asYouType: false);
        $this->assertContains(1, $result->ids);
    }

    public function testBooleanSearchStripsSpacesAroundParentheses(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([['id' => 1, 'title' => 'über']]);
        $result = $index->searchBoolean('ÜBER', asYouType: false);
        $this->assertContains(1, $result->ids);
    }

    public function testBooleanGroupNotFollowedByNot(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([
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
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result['stopwords_active']);
    }

    public function testInspectQueryStemmerActiveWhenLanguageSet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result['stemmer_active']);
    }

    public function testInspectQueryFilteredTokensDropsStopwords(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('the quick');
        $this->assertNotContains('the', $result['filtered_tokens']);
        $this->assertContains('the', $result['raw_tokens']);
    }

    public function testInspectQueryAllStrippedTrueWhenOnlyStopwords(): void
    {
        // Single-token all-stopword query: filterQueryTokens only strips when count > 1,
        // so use two stopwords to trigger the all-stripped fallback.
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
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
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('running');
        // 'running' stems to 'run' in English Snowball
        $this->assertSame('run', $result['filtered_tokens'][0]);
    }

    public function testInspectQueryRawToProcessedMapping(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('running');
        $this->assertSame('running', $result['tokens'][0]['raw']);
        $this->assertSame('run', $result['tokens'][0]['processed']);
    }

    public function testInspectQueryFoundTrueForIndexedTerm(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
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
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertSame('exact', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryMatchTypePrefix(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sed');
        $this->assertSame('prefix', $result['tokens'][0]['match_type']);
        $terms = array_column($result['tokens'][0]['wordlist_rows'], 'term');
        $this->assertContains('sedan', $terms);
    }

    public function testInspectQueryMatchTypeFuzzy(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sedaan', asYouType: false);
        $this->assertSame('fuzzy', $result['tokens'][0]['match_type']);
        $this->assertNotNull($result['tokens'][0]['wordlist_rows'][0]['distance']);
    }

    public function testInspectQueryMatchTypeNoneWhenNoCandidate(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('zzznomatch', asYouType: false);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryPrefixExpandsMultipleTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index->insert([['id' => 1, 'body' => 'sedan']]);
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
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $before = $index->inspectQuery('sedan')['index_info']['total_documents'];
        $index->inspectQuery('sedan');
        $this->assertSame($before, $index->inspectQuery('sedan')['index_info']['total_documents']);
    }

    public function testInspectQueryWarmsWordlistCacheForSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $index->inspectQuery('sedan', asYouType: false);
        // If cache is warm, search returns the same result without extra DB reads.
        $result = $index->search('sedan');
        $this->assertContains(1, $result->ids);
    }

    public function testInspectQueryShortWordSkipsFuzzy(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        // 'sedn' is 4 codepoints — below fuzzyMinWordLength=5.
        // Mutation TrueValue: gate removed → fuzzy fires → 'sedn' matches 'sedan' → 'fuzzy'.
        // Original: gate blocks → no match → 'none'.
        $result = $index->inspectQuery('sedn', asYouType: false);
        $this->assertSame('none', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryExactMatchIsNotFuzzyType(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        // Exact wordlist hit has no 'distance' key → match_type must be 'exact', not 'fuzzy'.
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertSame('exact', $result['tokens'][0]['match_type']);
    }

    public function testInspectQueryWordlistRowsHaveExactKeys(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $rows = $index->inspectQuery('sedan', asYouType: false)['tokens'][0]['wordlist_rows'];
        $this->assertNotEmpty($rows);
        // Mutation UnwrapArrayMap: $wordlistRows = $rows (raw rows with extra internal fields).
        // Correct: array_map remaps to exactly {term, num_hits, num_docs, distance}.
        $this->assertSame(['term', 'num_hits', 'num_docs', 'distance'], array_keys($rows[0]));
    }

    public function testInspectQueryNumHitsAndNumDocsValues(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan sedan']]);

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
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        });

        $this->assertInstanceOf(Index::class, $rebuilt);
    }

    public function testRebuildNewContentIsSearchable(): void
    {
        $index = new Index($this->dbPath);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        });

        $this->assertContains(1, $rebuilt->search('sedan')->ids);
    }

    public function testRebuildRemovesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'old content']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 2, 'title' => 'new content']]);
        });

        $this->assertEmpty($rebuilt->search('old')->ids);
        $this->assertContains(2, $rebuilt->search('new')->ids);
    }

    public function testRebuildLeavesOriginalIntactOnCallbackException(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'original']]);
        $index->close();

        try {
            Index::rebuild($this->dbPath, function (Index $new): void {
                $new->insert([['id' => 2, 'title' => 'partial']]);
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
        new Index($this->dbPath, schema: new SchemaConfig(language: 'en'))->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'running']]);
        });

        $this->assertSame('en', $rebuilt->language);
    }

    public function testRebuildWorksWhenFileDoesNotExistYet(): void
    {
        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        });

        $this->assertContains(1, $rebuilt->search('sedan')->ids);
    }

    public function testRebuildCountReflectsNewDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'a'], ['id' => 2, 'title' => 'b']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 10, 'title' => 'only one']]);
        });

        $this->assertSame(1, $rebuilt->count());
    }

    // --- CJK / ngram languages ---

    public function testZhInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([['id' => 1, 'body' => '轿车测试']]);

        $this->assertContains(1, $index->search('轿车')->ids);
    }

    public function testZhSingleCharSearch(): void
    {
        // Unigrams are emitted at index time for zh so single-character searches work.
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([['id' => 1, 'body' => '轿车测试']]);

        $this->assertContains(1, $index->search('车')->ids);
    }

    public function testZhDoesNotMatchUnrelatedDocument(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([
            ['id' => 1, 'body' => '轿车测试'],
            ['id' => 2, 'body' => '飞机起飞'],
        ]);

        $this->assertNotContains(2, $index->search('轿车')->ids);
    }

    public function testJaInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'ja'));
        $index->insert([['id' => 1, 'body' => '東京タワー']]);

        $this->assertContains(1, $index->search('東京')->ids);
    }

    public function testKoInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'ko'));
        $index->insert([['id' => 1, 'body' => '서울특별시']]);

        $this->assertContains(1, $index->search('서울')->ids);
    }

    public function testThInsertAndSearchTrigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'th'));
        $index->insert([['id' => 1, 'body' => 'กรุงเทพมหานคร']]);

        // 'กรุงเท' is a trigram within the indexed text
        $this->assertContains(1, $index->search('กรุงเท')->ids);
    }

    public function testZhBooleanSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([
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
        $index  = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $result = $index->inspectQuery('轿车');

        $this->assertContains('轿车', $result['filtered_tokens']);
        // Raw tokens show the output of the base tokenizer (whole string as one unit).
        $this->assertSame(['轿车'], $result['raw_tokens']);
    }

    public function testZhMixedQueryAsciiTokenPassthrough(): void
    {
        // ASCII tokens in a mixed query must not be ngrammed.
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([
            ['id' => 1, 'body' => 'BMW 轿车'],
            ['id' => 2, 'body' => '轿车'],
        ]);

        // Both docs contain '轿车'; only doc 1 also contains 'bmw'.
        $result = $index->searchBoolean('bmw 轿车');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    // --- positions ---

    public function testPositionsWritesCorrectRowCount(): void
    {
        $index = new Index($this->dbPath);
        // "city car" → 2 tokens → 2 position rows
        $index->insert([['id' => 1, 'title' => 'city car']]);
        $index->close();

        $pdo  = new \PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->query('SELECT COUNT(*) FROM positions WHERE doc_id = 1');
        $this->assertNotFalse($stmt);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testStorePositionsRecordsCorrectPositionValues(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'city car review']]);

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
        $index = new Index($this->dbPath);
        // title contributes tokens at 0, 1; body continues from 2, 3
        $index->insert([['id' => 1, 'title' => 'city car', 'body' => 'fast sedan']]);

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
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'city car']]);
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
        $index = new Index($this->dbPath);
        $index->insert([
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

    public function testPositionsTableAlwaysCreated(): void
    {
        new Index($this->dbPath);
        $pdo   = new \PDO('sqlite:' . $this->dbPath);
        $stmt  = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $this->assertNotFalse($stmt);
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('positions', $tables);
    }

    // --- Proximity ranking ---------------------------------------------------

    public function testProximityBoostRanksCloserTermsHigher(): void
    {
        // doc 1: terms adjacent (minSpan = 1), doc 2: terms far apart (minSpan = 10)
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index  = new Index($this->dbPath, config: $config);
        $index->insert([
            ['id' => 1, 'title' => 'fast car review review review review review review review review review'],
            ['id' => 2, 'title' => 'fast review review review review review review review review review car'],
        ]);

        // With proximityBoost=0.0 the result order is pure BM25 (identical here → stable order).
        $results = $index->search('fast car');
        $this->assertCount(2, $results->ids);
        $this->assertContains(1, $results->ids);
        $this->assertContains(2, $results->ids);
    }

    public function testProximityBoostNoEffectOnSingleKeyword(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
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
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'fast car'],
            ['id' => 2, 'title' => 'fast review'],
        ]);

        $results = $index->search('fast car');
        // doc 1 should rank above doc 2 (has both terms; doc 2 misses "car").
        $this->assertSame(1, $results->ids[0]);
    }

    public function testProxWindowSizeDefaultIsZero(): void
    {
        $this->assertSame(0, (new Config())->proxWindowSize);
    }

    public function testProxWindowSizeZeroAppliesProximityToAllCandidates(): void
    {
        // Both docs have identical BM25 (same dl, same TF for both terms). With proxWindowSize=0
        // (unlimited), both receive the proximity pass and the adjacent-terms doc (id=1) wins.
        $config = new Config(proxWindowSize: 0);
        $index  = new Index($this->dbPath, config: $config);
        $index->insert([
            ['id' => 1, 'title' => 'fast car review review review review review review review review review'],
            ['id' => 2, 'title' => 'fast review review review review review review review review review car'],
        ]);

        $results = $index->search('fast car');

        $this->assertSame([1, 2], $results->ids);
    }

    public function testProxWindowSizePositiveCapsBoostedCandidates(): void
    {
        // proxWindowSize=1: only the top-1-by-BM25 doc enters the proximity pass.
        // Doc 1 (dl=2, "fast car") has the highest raw BM25 (shorter doc) → enters the window.
        // It is penalised: fast at pos 0, car at pos 1, minSpan=1 → score ×0.5.
        // Doc 2 (dl=11, far terms) is outside the window and retains its full raw BM25.
        // Doc 2's full score (~0.28) edges past doc 1's penalised score (~0.25) → doc 2 wins.
        // With proxWindowSize=0 the order reverses: both are penalised, and doc 2's large span
        // (minSpan=10, ÷11) drops it far below doc 1's mild penalty (×0.5) → doc 1 wins.
        // This demonstrates that the windowed path produces a different result from unlimited.
        $config = new Config(proxWindowSize: 1);
        $index  = new Index($this->dbPath, config: $config);
        $index->insert([
            ['id' => 1, 'title' => 'fast car'],
            ['id' => 2, 'title' => 'fast review review review review review review review review review car'],
        ]);

        $results = $index->search('fast car');

        // Doc 1 is proximity-penalised inside the window; doc 2 escapes it and wins on full BM25.
        $this->assertSame(2, $results->ids[0]);
    }

    // --- insertMany progress callback ---

    public function testInsertManyProgressCallbackFiresForEachDocument(): void
    {
        $index = new Index($this->dbPath);
        $docs  = [
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ];

        $calls = [];
        $index->insert($docs, progress: function (int $done, int $total) use (&$calls): void {
            $calls[] = [$done, $total];
        });

        $this->assertSame([[1, 3], [2, 3], [3, 3]], $calls);
    }

    public function testInsertManyProgressTotalIsConsistent(): void
    {
        $index  = new Index($this->dbPath);
        $docs   = array_map(fn(int $i): array => ['id' => $i, 'title' => "doc $i"], range(1, 10));
        $totals = [];

        $index->insert($docs, progress: function (int $done, int $total) use (&$totals): void {
            $totals[] = $total;
        });

        $this->assertSame(array_fill(0, 10, 10), $totals);
    }

    public function testInsertManyProgressNullCallbackIsDefault(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $this->assertContains(1, $index->search('sedan')->ids);
    }

    public function testInsertManyProgressEmptyBatchFiresZeroTimes(): void
    {
        $index = new Index($this->dbPath);
        $fired = false;

        $index->insert([], progress: function () use (&$fired): void {
            $fired = true;
        });

        $this->assertFalse($fired);
    }

    // --- readonly ---

    public function testReadonlyOpenSucceeds(): void
    {
        new Index($this->dbPath)->close();
        $index = new Index($this->dbPath, readonly: true);
        $this->assertInstanceOf(Index::class, $index);
    }

    public function testReadonlyWithForceTrowsQueryException(): void
    {
        $this->expectException(QueryException::class);
        new Index($this->dbPath, force: true, readonly: true);
    }

    public function testReadonlyWithNonExistentFileThrowsIOException(): void
    {
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true);
    }

    public function testReadonlySearchWorks(): void
    {
        $write = new Index($this->dbPath);
        $write->insert([['id' => 1, 'title' => 'electric sedan']]);
        $write->close();

        $read = new Index($this->dbPath, readonly: true);
        $this->assertContains(1, $read->search('sedan')->ids);
    }

    public function testReadonlyInsertThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->insert([['id' => 1, 'title' => 'x']]);
    }

    public function testReadonlyInsertManyThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->insert([['id' => 1, 'title' => 'x']]);
    }

    public function testReadonlyUpdateThrows(): void
    {
        $write = new Index($this->dbPath);
        $write->insert([['id' => 1, 'title' => 'x']]);
        $write->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->update([['id' => 1, 'title' => 'y']]);
    }

    public function testReadonlyUpsertThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->upsert([['id' => 1, 'title' => 'x']]);
    }

    public function testReadonlyUpdateManyThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->update([['id' => 1, 'title' => 'x']]);
    }

    public function testReadonlyUpsertManyThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->upsert([['id' => 1, 'title' => 'x']]);
    }

    public function testReadonlyDeleteThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->delete(1);
    }

    public function testReadonlyDeleteManyThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->delete(1);
    }

    public function testReadonlyClearThrows(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->clear();
    }

    // --- snapshotTo ---

    public function testSnapshotToCreatesFile(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        try {
            $write = new Index($this->dbPath);
            $write->insert([['id' => 1, 'title' => 'electric sedan']]);
            $write->snapshotTo($readPath);
            $this->assertFileExists($readPath);
        } finally {
            @unlink($readPath);
        }
    }

    public function testSnapshotToIsSearchable(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        try {
            $write = new Index($this->dbPath);
            $write->insert([
                ['id' => 1, 'title' => 'electric sedan'],
                ['id' => 2, 'title' => 'off-road suv'],
            ]);
            $write->snapshotTo($readPath);

            $read = new Index($readPath);
            $this->assertContains(1, $read->search('sedan')->ids);
            $this->assertContains(2, $read->search('suv')->ids);
            $read->close();
        } finally {
            foreach ([$readPath, $readPath . '-wal', $readPath . '-shm'] as $f) {
                @unlink($f);
            }
        }
    }

    public function testSnapshotToIsOpenableReadonly(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        try {
            $write = new Index($this->dbPath);
            $write->insert([['id' => 1, 'title' => 'sedan']]);
            $write->snapshotTo($readPath);

            $read = new Index($readPath, readonly: true);
            $this->assertContains(1, $read->search('sedan')->ids);
            $read->close();
        } finally {
            @unlink($readPath);
        }
    }

    public function testSnapshotToAtomicallyReplacesExistingFile(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        try {
            $write = new Index($this->dbPath);
            $write->insert([['id' => 1, 'title' => 'first']]);
            $write->snapshotTo($readPath);

            $write->insert([['id' => 2, 'title' => 'second']]);
            $write->snapshotTo($readPath);

            $read = new Index($readPath);
            $this->assertContains(2, $read->search('second')->ids);
            $read->close();
        } finally {
            @unlink($readPath);
        }
    }

    public function testSnapshotToCleansUpStaleTempFiles(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        $stale    = $readPath . '.tmp-deadbeef';
        try {
            file_put_contents($stale, 'leftover');

            $write = new Index($this->dbPath);
            $write->insert([['id' => 1, 'title' => 'sedan']]);
            $write->snapshotTo($readPath);

            $this->assertFileDoesNotExist($stale);
        } finally {
            @unlink($readPath);
            @unlink($stale);
        }
    }

    public function testSnapshotToPreservesLanguage(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        try {
            $write = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
            $write->insert([['id' => 1, 'title' => 'running fast']]);
            $write->snapshotTo($readPath);

            $read = new Index($readPath);
            $this->assertSame('en', $read->language);
            $read->close();
        } finally {
            @unlink($readPath);
        }
    }

    // --- Document store: construction ---

    public function testDocumentStoreEnabledByDefault(): void
    {
        $index = new Index($this->dbPath);
        $this->assertTrue($index->documentStoreEnabled);
    }

    public function testDocumentStorePersistedAfterReopen(): void
    {
        new Index($this->dbPath)->close();

        $index = new Index($this->dbPath);
        $this->assertTrue($index->documentStoreEnabled);
    }

    public function testDocumentStorePersistedWhenDisabled(): void
    {
        new Index($this->dbPath, schema: new SchemaConfig(store: false))->close();

        $index = new Index($this->dbPath);
        $this->assertFalse($index->documentStoreEnabled);
    }

    // --- Document store: get / getMany guards ---

    public function testGetThrowsWhenStoreNotEnabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        $index->get(1);
    }

    public function testGetManyThrowsWhenStoreNotEnabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        $index->get(1);
    }

    public function testGetReturnsNullForMissingId(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $this->assertNull($index->get(999));
    }

    public function testGetManyEmptyArrayReturnsEmpty(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $this->assertSame([], $index->get());
    }

    public function testGetManyOmitsMissingIds(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->get(1, 999);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(999, $result);
    }

    // --- Document store: insert ---

    public function testInsertStoresDocument(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'body' => 'city car'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->get(1));
    }

    public function testInsertDoesNotStoreWhenDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->expectException(QueryException::class);
        $index->get(1);
    }

    // --- Document store: insertMany ---

    public function testInsertManyStoresAllDocuments(): void
    {
        $docs = [
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
            ['id' => 3, 'title' => 'suv'],
        ];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert($docs);

        $this->assertSame($docs[0], $index->get(1));
        $this->assertSame($docs[1], $index->get(2));
        $this->assertSame($docs[2], $index->get(3));
    }

    // --- Document store: update ---

    public function testUpdateReplacesStoredDocument(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'old title']]);
        $index->update([['id' => 1, 'title' => 'new title']]);

        $this->assertSame(['id' => 1, 'title' => 'new title'], $index->get(1));
    }

    // --- Document store: upsert ---

    public function testUpsertStoresNewDocument(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->upsert([$doc]);

        $this->assertSame($doc, $index->get(1));
    }

    public function testUpsertReplacesStoredDocument(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'old']]);
        $index->upsert([['id' => 1, 'title' => 'new']]);

        $this->assertSame(['id' => 1, 'title' => 'new'], $index->get(1));
    }

    // --- Document store: updateMany ---

    public function testUpdateManyReplacesStoredDocuments(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([
            ['id' => 1, 'title' => 'old one'],
            ['id' => 2, 'title' => 'old two'],
        ]);
        $index->update([
            ['id' => 1, 'title' => 'new one'],
            ['id' => 2, 'title' => 'new two'],
        ]);

        $this->assertSame(['id' => 1, 'title' => 'new one'], $index->get(1));
        $this->assertSame(['id' => 2, 'title' => 'new two'], $index->get(2));
    }

    // --- Document store: upsertMany ---

    public function testUpsertManyStoresNewAndReplacesExisting(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'old']]);
        $index->upsert([
            ['id' => 1, 'title' => 'replaced'],
            ['id' => 2, 'title' => 'new'],
        ]);

        $this->assertSame(['id' => 1, 'title' => 'replaced'], $index->get(1));
        $this->assertSame(['id' => 2, 'title' => 'new'], $index->get(2));
    }

    // --- Document store: delete ---

    public function testDeleteRemovesDocumentFromStore(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1);

        $this->assertNull($index->get(1));
    }

    // --- Document store: deleteMany ---

    public function testDeleteManyRemovesDocumentsFromStore(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->delete(1, 2);

        $this->assertNull($index->get(1));
        $this->assertNull($index->get(2));
    }

    // --- Document store: clear ---

    public function testClearRemovesAllDocumentsFromStore(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->clear();

        $this->assertNull($index->get(1));
        $this->assertNull($index->get(2));
    }

    public function testClearOnStoreDisabledIndexDoesNotThrow(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->clear();

        $this->assertSame(0, $index->count());
    }

    // --- Document store: hasDocuments ---

    public function testHasDocumentsFalseWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertFalse($index->search('sedan')->hasDocuments());
    }

    public function testHasDocumentsTrueWhenStoreEnabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertTrue($index->search('sedan')->hasDocuments());
    }

    public function testHasDocumentsTrueEvenWhenNoResults(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertTrue($index->search('coupe')->hasDocuments());
    }

    public function testDocumentReturnsDocForIdInResult(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->search('sedan')->document(1));
    }

    public function testDocumentReturnsNullForIdNotInResult(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertNull($index->search('sedan')->document(99));
    }

    public function testDocumentReturnsNullWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertNull($index->search('sedan')->document(1));
    }

    public function testDocumentsReturnsNullWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertNull($index->search('sedan')->documents());
    }

    public function testDocumentsReturnsFullMap(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([
            ['id' => 10, 'title' => 'sedan'],
            ['id' => 20, 'title' => 'sedan coupe'],
        ]);

        $result = $index->search('sedan');
        $docs = $result->documents();
        $this->assertNotNull($docs);
        $this->assertArrayHasKey(10, $docs);
        $this->assertArrayHasKey(20, $docs);
        $this->assertSame($result->document(10), $docs[10]);
        $this->assertSame($result->document(20), $docs[20]);
    }

    // --- Document store: search hydration ---

    public function testSearchDocumentsIsNullWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->search('sedan');
        $this->assertFalse($result->hasDocuments());
        $this->assertNull($result->document(1));
    }

    public function testSearchDocumentsIsEmptyArrayWhenNoMatch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->search('coupe');
        $this->assertTrue($result->hasDocuments());
        $this->assertEmpty($result->ids);
        $this->assertNull($result->document(1));
    }

    public function testSearchHydratesDocuments(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'body' => 'city car'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $result = $index->search('sedan');
        $this->assertTrue($result->hasDocuments());
        $this->assertSame($doc, $result->document(1));
    }

    public function testSearchDocumentsKeyedByDocId(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([
            ['id' => 10, 'title' => 'fast sedan'],
            ['id' => 20, 'title' => 'sedan coupe'],
        ]);

        $result = $index->search('sedan');
        $this->assertTrue($result->hasDocuments());
        $doc10 = $result->document(10);
        $doc20 = $result->document(20);
        $this->assertNotNull($doc10);
        $this->assertNotNull($doc20);
        $this->assertSame(10, $doc10['id']);
        $this->assertSame(20, $doc20['id']);
    }

    public function testSearchBooleanDocumentsIsNullWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->searchBoolean('sedan');
        $this->assertFalse($result->hasDocuments());
        $this->assertNull($result->document(1));
    }

    public function testSearchBooleanHydratesDocuments(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'body' => 'city car'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $result = $index->searchBoolean('sedan');
        $this->assertTrue($result->hasDocuments());
        $this->assertSame($doc, $result->document(1));
    }

    // --- Document store: rebuild ---

    public function testRebuildInheritsStoreFromExistingIndex(): void
    {
        new Index($this->dbPath, schema: new SchemaConfig(store: false))->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        });

        $this->assertFalse($rebuilt->documentStoreEnabled);
    }

    public function testRebuildCanEnableStore(): void
    {
        new Index($this->dbPath, schema: new SchemaConfig(store: false))->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        }, schema: new SchemaConfig(store: true));

        $this->assertTrue($rebuilt->documentStoreEnabled);
    }

    public function testRebuildCanDisableStore(): void
    {
        new Index($this->dbPath)->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        }, schema: new SchemaConfig(store: false));

        $this->assertFalse($rebuilt->documentStoreEnabled);
    }

    public function testRebuildWithStoreStoresDocuments(): void
    {
        new Index($this->dbPath, schema: new SchemaConfig(store: false))->close();

        $doc     = ['id' => 1, 'title' => 'sedan'];
        $rebuilt = Index::rebuild($this->dbPath, function (Index $new) use ($doc): void {
            $new->insert([$doc]);
        }, schema: new SchemaConfig(store: true));

        $this->assertSame($doc, $rebuilt->get(1));
    }

    // --- Document store: snapshotTo ---

    public function testSnapshotPreservesDocumentStore(): void
    {
        $snapPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        try {
            $doc   = ['id' => 1, 'title' => 'sedan'];
            $write = new Index($this->dbPath, schema: new SchemaConfig(store: true));
            $write->insert([$doc]);
            $write->snapshotTo($snapPath);

            $snap = new Index($snapPath);
            $this->assertTrue($snap->documentStoreEnabled);
            $this->assertSame($doc, $snap->get(1));
            $snap->close();
        } finally {
            @unlink($snapPath);
        }
    }

    // --- Facets: construction ---

    public function testFacetsAlwaysEnabled(): void
    {
        $index = new Index($this->dbPath);
        $this->assertTrue($index->facetsEnabled);
    }

    public function testFacetsPersistedAfterReopen(): void
    {
        (new Index($this->dbPath))->close();
        $index = new Index($this->dbPath);
        $this->assertTrue($index->facetsEnabled);
    }

    // --- Facets: insert / delete isolation ---

    public function testFacetFieldNotIndexedAsText(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'hello', 'color' => 'red']]);

        // 'red' should NOT appear in search results (it's a facet value, not a text token)
        $result = $index->search('red');
        $this->assertNotContains(1, $result->ids);
    }

    public function testInsertSingleDocWithFacets(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);

        $result = $index->search('car', facets: ['color']);
        $this->assertContains(1, $result->ids);
        $this->assertTrue($result->hasFacets());
        $this->assertSame(['red' => 1], $result->facetCounts()['color']);
    }

    public function testDeleteRemovesFacetValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        $index->delete(1);

        $result = $index->search('car', facets: ['color']);
        $this->assertNotContains(1, $result->ids);
        $this->assertSame([], $result->facetCounts());
    }

    // --- Facets: bulk insert / delete ---

    public function testInsertManyStoresFacets(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'red'],
        ]);

        $result = $index->search('car', facets: ['color']);
        $this->assertSame(2, $result->facetCount('color', 'red'));
        $this->assertSame(1, $result->facetCount('color', 'blue'));
    }

    public function testDeleteManyRemovesFacetValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
        ]);
        $index->delete(1, 2);

        $result = $index->search('car', facets: ['color']);
        $this->assertSame([], $result->facetCounts());
    }

    // --- Facets: string filter ---

    public function testSearchWithStringSingleValueFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
        ]);

        $result = $index->search('car', filter: ['color' => 'red']);
        $this->assertSame([1], $result->ids);
        $this->assertSame(1, $result->hits);
    }

    public function testSearchWithStringMultiValueOrFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'green'],
        ]);

        $result = $index->search('car', filter: ['color' => ['red', 'blue']]);
        $this->assertCount(2, $result->ids);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    // --- Facets: numeric range filter ---

    public function testSearchWithNumericRangeFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'price' => 10000],
            ['id' => 2, 'title' => 'car', 'price' => 25000],
            ['id' => 3, 'title' => 'car', 'price' => 50000],
        ]);

        $result = $index->search('car', filter: ['price' => FacetRange::between(10000, 30000)]);
        $this->assertCount(2, $result->ids);
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
        $this->assertNotContains(3, $result->ids);
    }

    // --- Facets: counts ---

    public function testSearchFacetCountsStringFacet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'red'],
            ['id' => 3, 'title' => 'car', 'color' => 'blue'],
        ]);

        $result = $index->search('car', facets: ['color']);
        $this->assertSame(2, $result->facetCount('color', 'red'));
        $this->assertSame(1, $result->facetCount('color', 'blue'));
        $this->assertNull($result->facetCount('color', 'green'));
    }

    public function testSearchFacetCountsNumericFacet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'price' => 10000.0],
            ['id' => 2, 'title' => 'car', 'price' => 20000.0],
            ['id' => 3, 'title' => 'car', 'price' => 30000.0],
        ]);

        $result = $index->search('car', facets: ['price']);
        $this->assertSame(
            ['price' => ['min' => 10000.0, 'max' => 30000.0, 'count' => 3]],
            $result->facetCounts(),
        );
        $this->assertNull($result->facetCount('price', 'anything'));
    }

    // --- Facets: disjunctive counts ---

    public function testDisjunctiveFacetCountsShowAllValuesWhenFiltered(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'red'],
        ]);

        // Filter by 'red' but count against the full result set for the 'color' key
        $result = $index->search('car', filter: ['color' => 'red'], facets: ['color']);
        // Disjunctive: both 'red' (2) and 'blue' (1) should appear even though filter is active
        $this->assertSame(2, $result->facetCount('color', 'red'));
        $this->assertSame(1, $result->facetCount('color', 'blue'));
    }

    // --- Facets: multi-value per document ---

    public function testMultiValueFacetOnSingleDocument(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => ['red', 'blue']]]);

        $result = $index->search('car', facets: ['color']);
        $this->assertSame(1, $result->facetCount('color', 'red'));
        $this->assertSame(1, $result->facetCount('color', 'blue'));
    }

    // --- Facets: boolean search ---

    public function testSearchBooleanWithFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car sedan', 'color' => 'red'],
            ['id' => 2, 'title' => 'car coupe', 'color' => 'blue'],
        ]);

        $result = $index->searchBoolean('car', filter: ['color' => 'red']);
        $this->assertSame([1], $result->ids);
        $this->assertSame(1, $result->hits);
    }

    public function testSearchBooleanWithFacetCounts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
        ]);

        $result = $index->searchBoolean('car', facets: ['color']);
        $this->assertSame(1, $result->facetCount('color', 'red'));
        $this->assertSame(1, $result->facetCount('color', 'blue'));
    }

    // --- Facets: no data for requested key ---

    public function testFacetCountsEmptyWhenNoFacetData(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'car']]);

        $result = $index->search('car', facets: ['color']);
        $this->assertFalse($result->hasFacets());
        $this->assertSame([], $result->facetCounts());
    }

    // --- facetFields / searchableFields schema persistence ---

    public function testFacetFieldsPersistedAcrossReopen(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color', 'brand'])))->close();

        $index = new Index($this->dbPath);
        $this->assertSame(['color', 'brand'], $index->facetFields);
    }

    public function testSearchableFieldsPersistedAcrossReopen(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(searchableFields: ['title', 'description'])))->close();

        $index = new Index($this->dbPath);
        $this->assertSame(['title', 'description'], $index->searchableFields);
    }

    public function testNullSearchableFieldsRoundTrips(): void
    {
        (new Index($this->dbPath))->close();

        $index = new Index($this->dbPath);
        $this->assertNull($index->searchableFields);
    }

    public function testEmptySearchableFieldsMeansNothingTokenized(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(searchableFields: []));
        $index->insert([['id' => 1, 'title' => 'car']]);

        $this->assertSame([], $index->search('car')->ids);
    }

    public function testFacetFieldNotReturnedByFts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'scarlet']]);

        $this->assertSame([], $index->search('scarlet')->ids);
        $this->assertSame([1], $index->search('car')->ids);
    }

    public function testSearchableFieldsRestrictsTokenization(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(searchableFields: ['title']));
        $index->insert([['id' => 1, 'title' => 'car', 'sku' => 'ABC-123']]);

        $this->assertSame([1], $index->search('car')->ids);
        $this->assertSame([], $index->search('ABC')->ids);
    }

    public function testFieldInBothFacetAndSearchableIsIndexedAndFaceted(): void
    {
        $schema = new SchemaConfig(facetFields: ['brand'], searchableFields: ['title', 'brand']);
        $index  = new Index($this->dbPath, schema: $schema);
        $index->insert([['id' => 1, 'title' => 'watch', 'brand' => 'Casio']]);

        // brand is searchable
        $this->assertSame([1], $index->search('casio')->ids);
        // brand is also faceted
        $result = $index->search('casio', facets: ['brand']);
        $this->assertSame(1, $result->facetCount('brand', 'Casio'));
    }

    public function testRebuildInheritsFacetAndSearchableFields(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color'], searchableFields: ['title'])))->close(); // phpcs:ignore

        Index::rebuild($this->dbPath, function (Index $idx): void {
            $idx->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        });

        $index = new Index($this->dbPath);
        $this->assertSame(['color'], $index->facetFields);
        $this->assertSame(['title'], $index->searchableFields);
    }

    public function testStoredOnlyFieldAppearsInDocumentStore(): void
    {
        $doc   = ['id' => 1, 'title' => 'car', 'image_url' => 'https://example.com/car.jpg'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true, searchableFields: ['title']));
        $index->insert([$doc]);

        // image_url is stored but not indexed
        $this->assertSame([], $index->search('example')->ids);
        $this->assertSame($doc, $index->get(1));
    }

    // --- Facets: rebuild ---

    public function testRebuildPreservesFacets(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color'])))->close();

        Index::rebuild($this->dbPath, function (Index $idx): void {
            $idx->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        });

        $index = new Index($this->dbPath);
        $this->assertTrue($index->facetsEnabled);
        $this->assertSame(['color'], $index->facetFields);
    }

    // --- Facets: clear ---

    public function testClearRemovesFacetValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        $index->clear();

        $result = $index->search('car', facets: ['color']);
        $this->assertSame([], $result->facetCounts());
    }

    // --- Sort ---

    public function testSortByNumericFacetAsc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame([2, 3, 1], $index->search('product', sort: ['price:asc'])->ids);
    }

    public function testSortByNumericFacetDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame([1, 3, 2], $index->search('product', sort: ['price:desc'])->ids);
    }

    public function testSortByStringFacetAsc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['brand']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Adidas'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Puma'],
        ]);
        $this->assertSame([2, 1, 3], $index->search('product', sort: ['brand:asc'])->ids);
    }

    public function testSortByStringFacetDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['brand']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Adidas'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Puma'],
        ]);
        $this->assertSame([3, 1, 2], $index->search('product', sort: ['brand:desc'])->ids);
    }

    public function testSortDirectionCaseInsensitive(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
        ]);
        $this->assertSame([2, 1], $index->search('product', sort: ['price:ASC'])->ids);
    }

    public function testSortNullsLastAsc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'price' => 5],
        ]);
        $this->assertSame([3, 1, 2], $index->search('product', sort: ['price:asc'])->ids);
    }

    public function testSortNullsLastDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'price' => 5],
        ]);
        $this->assertSame([1, 3, 2], $index->search('product', sort: ['price:desc'])->ids);
    }

    public function testSortMultiKey(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['category', 'price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'category' => 'b', 'price' => 20],
            ['id' => 2, 'title' => 'product', 'category' => 'a', 'price' => 30],
            ['id' => 3, 'title' => 'product', 'category' => 'a', 'price' => 10],
            ['id' => 4, 'title' => 'product', 'category' => 'b', 'price' => 5],
        ]);
        $result = $index->search('product', sort: ['category:asc', 'price:asc']);
        $this->assertSame([3, 2, 4, 1], $result->ids);
    }

    public function testSortWithFacetFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['category', 'price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'category' => 'a', 'price' => 20],
            ['id' => 2, 'title' => 'product', 'category' => 'b', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'category' => 'a', 'price' => 5],
        ]);
        $result = $index->search('product', filter: ['category' => 'a'], sort: ['price:asc']);
        $this->assertSame([3, 1], $result->ids);
        $this->assertSame(2, $result->hits);
    }

    public function testSortDoesNotAffectHitsCount(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame(
            $index->search('product')->hits,
            $index->search('product', sort: ['price:asc'])->hits,
        );
    }

    public function testSortDoesNotAffectFacetCounts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price', 'color']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30, 'color' => 'red'],
            ['id' => 2, 'title' => 'product', 'price' => 10, 'color' => 'blue'],
            ['id' => 3, 'title' => 'product', 'price' => 20, 'color' => 'red'],
        ]);
        $this->assertSame(
            $index->search('product', facets: ['color'])->facetCounts(),
            $index->search('product', facets: ['color'], sort: ['price:asc'])->facetCounts(),
        );
    }

    public function testSortWithPagination(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 40],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 30],
            ['id' => 4, 'title' => 'product', 'price' => 20],
        ]);
        $page1 = $index->search('product', limit: 2, offset: 0, sort: ['price:asc']);
        $page2 = $index->search('product', limit: 2, offset: 2, sort: ['price:asc']);
        $this->assertSame([2, 4], $page1->ids);
        $this->assertSame([3, 1], $page2->ids);
    }

    public function testSortUnknownFieldAllNull(): void
    {
        // Field not in facetFields → no facet_values rows → all docs treated as null.
        // Falls through to doc_id as final tiebreaker.
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product', 'price' => 20],
            ['id' => 3, 'title' => 'product', 'price' => 30],
        ]);
        $sorted   = $index->search('product', sort: ['weight:asc']);
        $unsorted = $index->search('product', sort: ['weight:desc']);
        // Both produce the same IDs (all null → doc_id tiebreaker either way)
        $this->assertSame($sorted->ids, $unsorted->ids);
    }

    public function testSortOnBooleanSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame([2, 3, 1], $index->searchBoolean('product', sort: ['price:asc'])->ids);
    }

    public function testSortBooleanNullsLast(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'price' => 5],
        ]);
        $this->assertSame([3, 1, 2], $index->searchBoolean('product', sort: ['price:asc'])->ids);
    }

    public function testSortInvalidSpecThrowsOnSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->expectException(\InvalidArgumentException::class);
        $index->search('product', sort: ['price_asc']);
    }

    public function testSortInvalidDirectionThrows(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->expectException(\InvalidArgumentException::class);
        $index->search('product', sort: ['price:up']);
    }

    public function testSortInvalidSpecThrowsOnBoolean(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->expectException(\InvalidArgumentException::class);
        $index->searchBoolean('product', sort: ['price:up']);
    }

    public function testEmptySortIsNoop(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(facetFields: ['price']));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->assertContains(1, $index->search('product', sort: [])->ids);
    }

    // --- Field boosts ---

    public function testFieldBoostPromotesTitleMatchOverBodyMatch(): void
    {
        // Doc 1 has "turbo" only in title (1 hit); doc 2 has "turbo" 3 times in body.
        // Without boosts, doc 2 would win (higher raw TF).
        // With title boost 5x vs body boost 1x, weighted_tf: doc1 = 5*1 = 5, doc2 = 1*3 = 3 → doc 1 wins.
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 5.0, 'body' => 1.0]));
        $index->insert([
            ['id' => 1, 'title' => 'turbo engine', 'body' => 'A standard engine with no special features.'],
            ['id' => 2, 'title' => 'engine overview', 'body' => 'turbo turbo turbo details here'],
        ]);
        $result = $index->search('turbo');
        $this->assertSame([1, 2], $result->ids);
    }

    public function testFieldBoostOnOldIndexThrowsQueryException(): void
    {
        // Simulate a pre-feature index by dropping the field_hits table after creation.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'test document', 'body' => 'content here']]);
        $index->close();

        // Drop field_hits to simulate an index built before field boost support was added.
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('DROP TABLE IF EXISTS field_hits; DROP TABLE IF EXISTS field_names;');
        unset($pdo);

        // Re-open with boosts configured — must throw QueryException.
        $index2 = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 2.0]));
        $this->expectException(QueryException::class);
        $index2->search('test');
    }

    public function testFieldBoostEmptyArrayUsesNormalBM25(): void
    {
        // Empty fieldBoosts = uniform path; search should still return results normally.
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: []));
        $index->insert([
            ['id' => 1, 'title' => 'alpha beta', 'body' => 'gamma delta'],
            ['id' => 2, 'title' => 'gamma delta', 'body' => 'alpha beta'],
        ]);
        $result = $index->search('alpha');
        $this->assertContains(1, $result->ids);
        $this->assertContains(2, $result->ids);
    }

    public function testFieldBoostWithUnknownFieldNameIsIgnored(): void
    {
        // A boost for a field that no document has should not crash; scoring degrades to 0 tf,
        // but the query still returns the docs (they have the term in another field).
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['nonexistent' => 10.0]));
        $index->insert([
            ['id' => 1, 'title' => 'widget', 'body' => 'some content'],
        ]);
        $result = $index->search('widget');
        $this->assertContains(1, $result->ids);
    }

    public function testFieldBoostOnReopenedIndex(): void
    {
        // Build the index (field_hits present), close, reopen with a config that has boosts.
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'turbo car', 'body' => 'generic content'],
            ['id' => 2, 'title' => 'generic car', 'body' => str_repeat('turbo ', 10)],
        ]);
        $index->close();

        $index2 = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 5.0, 'body' => 1.0]));
        $result = $index2->search('turbo');
        $this->assertSame([1, 2], $result->ids);
    }

    public function testFieldBoostUpsertUpdatesFieldHits(): void
    {
        // Upsert should replace old field_hits rows, not accumulate them.
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 3.0, 'body' => 1.0]));
        $index->insert([['id' => 1, 'title' => 'widget', 'body' => 'generic content']]);
        // Now replace: "widget" moved to body only; title no longer has it.
        $index->upsert([['id' => 1, 'title' => 'product overview', 'body' => 'widget listed here']]);

        $result = $index->search('widget');
        $this->assertContains(1, $result->ids);
        // Score should reflect body-only placement (title boost no longer applies).
        $this->assertGreaterThan(0.0, $result->score(1));
    }

    public function testFieldBoostDeleteClearsFieldHits(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'target document', 'body' => 'content']]);
        $index->delete(1);
        // After delete, index should be empty.
        $result = $index->search('target');
        $this->assertSame([], $result->ids);
    }

    public function testFieldBoostClearClearsFieldHits(): void
    {
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 2.0]));
        $index->insert([['id' => 1, 'title' => 'alpha', 'body' => 'content']]);
        $index->clear();
        $this->assertSame(0, $index->count());
        $result = $index->search('alpha');
        $this->assertSame([], $result->ids);
    }

    public function testFieldBoostBulkInsertPreservesScoreOrder(): void
    {
        // Bulk insert (>1 doc) should produce the same boost-ordering as single inserts.
        // Title boost 5x: weighted_tf for doc1 = 5*1=5, doc2 body 3 hits = 1*3=3 → doc1 ranks first.
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 5.0, 'body' => 1.0]));
        $index->insert([
            ['id' => 1, 'title' => 'turbo engine', 'body' => 'a plain description'],
            ['id' => 2, 'title' => 'engine specs', 'body' => 'turbo turbo turbo here'],
            ['id' => 3, 'title' => 'car guide', 'body' => 'another plain description'],
        ]);
        $result = $index->search('turbo');
        $this->assertSame(1, $result->ids[0], 'Title match should rank first with high title boost');
    }

    // --- Synonyms: API management ---

    public function testGetSynonymsEmptyOnFreshIndex(): void
    {
        $index = new Index($this->dbPath);
        $this->assertSame([], $index->getSynonyms());
    }

    public function testSetEquivalencesStoresBothDirections(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $synonyms = $index->getSynonyms();
        $this->assertContains('automobile', $synonyms['car']);
        $this->assertContains('car', $synonyms['automobile']);
    }

    public function testSetOneWayStoresOnlySourceToTarget(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone', 'mobile']]);
        $synonyms = $index->getSynonyms();
        $this->assertContains('smartphone', $synonyms['phone']);
        $this->assertContains('mobile', $synonyms['phone']);
        $this->assertArrayNotHasKey('smartphone', $synonyms);
        $this->assertArrayNotHasKey('mobile', $synonyms);
    }

    public function testSetSynonymsReplacesExisting(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->setSynonyms(equivalences: [['sedan', 'coupe']]);
        $synonyms = $index->getSynonyms();
        $this->assertArrayNotHasKey('car', $synonyms);
        $this->assertArrayHasKey('sedan', $synonyms);
    }

    public function testClearSynonymsEmptiesAll(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->clearSynonyms();
        $this->assertSame([], $index->getSynonyms());
    }

    public function testSetSynonymsNormalizesCase(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['CAR', 'Automobile']]);
        $synonyms = $index->getSynonyms();
        $this->assertArrayHasKey('car', $synonyms);
        $this->assertContains('automobile', $synonyms['car']);
    }

    public function testSetSynonymsSkipsMultiWordTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(oneWay: ['mobile phone' => ['smartphone']]);
        $this->assertSame([], $index->getSynonyms());
    }

    public function testSetSynonymsSkipsMultiWordTargets(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(oneWay: ['phone' => ['mobile device', 'smartphone']]);
        $synonyms = $index->getSynonyms();
        $this->assertArrayHasKey('phone', $synonyms);
        $this->assertNotContains('mobile device', $synonyms['phone']);
        $this->assertContains('smartphone', $synonyms['phone']);
    }

    // --- Synonyms: search expansion ---

    public function testEquivalenceSynonymExpandsSearchForOriginalTerm(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'automobile show'],
            ['id' => 2, 'title' => 'bike race'],
        ]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $this->assertContains(1, $index->search('car')->ids);
    }

    public function testEquivalenceSynonymExpandsSearchForOtherTerm(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'car show'],
            ['id' => 2, 'title' => 'bike race'],
        ]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $this->assertContains(1, $index->search('automobile')->ids);
    }

    public function testOneWaySynonymExpandsSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'smartphone review'],
            ['id' => 2, 'title' => 'laptop review'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone']]);
        $this->assertContains(1, $index->search('phone')->ids);
        $this->assertNotContains(2, $index->search('phone')->ids);
    }

    public function testOneWaySynonymDoesNotExpandReverse(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'phone review'],
            ['id' => 2, 'title' => 'laptop review'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone']]);
        $this->assertNotContains(1, $index->search('smartphone')->ids);
    }

    public function testSynonymMatchProducesNonZeroScore(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'automobile']]);
        $index->setSynonyms(oneWay: ['car' => ['automobile']]);
        $result = $index->search('car');
        $this->assertContains(1, $result->ids);
        $this->assertGreaterThan(0.0, $result->score(1));
    }

    public function testMultipleSynonymTargetsAllExpand(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'smartphone model'],
            ['id' => 2, 'title' => 'mobile model'],
            ['id' => 3, 'title' => 'laptop model'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone', 'mobile']]);
        $ids = $index->search('phone')->ids;
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    public function testNoSynonymExpansionWithoutSynonymsSet(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'automobile'],
            ['id' => 2, 'title' => 'car'],
        ]);
        $ids = $index->search('car', asYouType: false)->ids;
        $this->assertContains(2, $ids);
        $this->assertNotContains(1, $ids);
    }

    // --- Synonyms: boolean search expansion ---

    public function testSynonymExpandsBooleanSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'automobile show'],
            ['id' => 2, 'title' => 'bike race'],
        ]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $this->assertContains(1, $index->searchBoolean('car')->ids);
    }

    public function testOneWaySynonymInBooleanDoesNotExpandReverse(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'phone review'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone']]);
        $this->assertNotContains(1, $index->searchBoolean('smartphone')->ids);
    }

    // --- Synonyms: phrase exclusion ---

    public function testSynonymNotAppliedInsideQuotedPhrase(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'automobile show'],
            ['id' => 2, 'title' => 'car show'],
        ]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $result = $index->search('"automobile"');
        $this->assertContains(1, $result->ids);
        $this->assertNotContains(2, $result->ids);
    }

    // --- Synonyms: persistence ---

    public function testSynonymsPersistAfterReopenAndSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'automobile']]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->close();

        $index = new Index($this->dbPath);
        $this->assertContains(1, $index->search('car')->ids);
    }

    // --- Synonyms: stemmer normalization ---

    public function testSetSynonymsNormalizesWithStemmer(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        // 'cars' stems to 'car'; synonym target 'sedan' is unchanged.
        // Searching 'car' should find the sedan doc via synonym.
        $index->setSynonyms(oneWay: ['cars' => ['sedan']]);
        $this->assertContains(1, $index->search('car', asYouType: false)->ids);
    }

    // --- Synonyms: rebuild ---

    public function testRebuildPreservesSynonymsMap(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'car']]);
        });

        $synonyms = $rebuilt->getSynonyms();
        $this->assertArrayHasKey('car', $synonyms);
        $this->assertContains('automobile', $synonyms['car']);
    }

    public function testRebuildPreservedSynonymsWorkInSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'automobile']]);
        });

        $this->assertContains(1, $rebuilt->search('car')->ids);
    }

    public function testRebuildCallbackCanClearSynonyms(): void
    {
        $index = new Index($this->dbPath);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->clearSynonyms();
            $new->insert([['id' => 1, 'title' => 'automobile']]);
        });

        $this->assertSame([], $rebuilt->getSynonyms());
        $this->assertNotContains(1, $rebuilt->search('car')->ids);
    }

    public function testRebuildWithNoExistingSynonymsIsOk(): void
    {
        new Index($this->dbPath)->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        });

        $this->assertSame([], $rebuilt->getSynonyms());
        $this->assertContains(1, $rebuilt->search('sedan')->ids);
    }
}

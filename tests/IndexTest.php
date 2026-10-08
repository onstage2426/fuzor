<?php

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetExclude;
use Fuzor\FacetOrder;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\Index;
use Fuzor\MatchingStrategy;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\SearchResult;
use Fuzor\Tokenizer;
use Fuzor\TypoTolerance;
use Fuzor\Exceptions\IOException;
use Fuzor\Exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::removeIndexFiles($this->dbPath);
    }

    /** Remove an index path and everything published or built next to it (versions, sidecars, temp files). */
    private static function removeIndexFiles(string $path): void
    {
        foreach ([$path, ...(glob($path . '[.-]*') ?: [])] as $f) {
            if (is_link($f) || file_exists($f)) {
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
        $this->assertContains(1, $reopened->search('sedan')->getIds());
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
        $info = $index->inspectQuery('coupe');
        $this->assertSame(1, $info->totalDocuments);
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

    public function testSchemaOnExistingIndexThrowsQueryException(): void
    {
        (new Index($this->dbPath))->close();

        $this->expectException(QueryException::class);
        new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
    }

    public function testSchemaWithForceDoesNotThrow(): void
    {
        (new Index($this->dbPath))->close();

        // force: true means "recreate", so schema is applied to the new index — no throw.
        $index = new Index($this->dbPath, force: true, schema: new SchemaConfig(language: 'en'));
        $this->assertSame('en', $index->language);
    }

    public function testCreateWithForceOverwritesExistingFile(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->close();

        $fresh = new Index($this->dbPath, force: true);
        $this->assertSame([], $fresh->search('sedan')->getIds());
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
        $this->assertContains(1, $result->getIds());
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

        $this->assertSame([1], $index->search('sedan')->getIds());
        $this->assertSame([], $index->search('shouldnotbeindexed')->getIds());
        $this->assertSame([], $index->search('permalink')->getIds());
    }

    public function testStoredOnlyFieldIsPreservedInDocumentStore(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'permalink' => '/cars/sedan', 'published' => '2026-05-14'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true, searchableFields: ['title']));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->search('sedan')->getHit(0));
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);

        $result = $index->search('helicopter');
        $this->assertSame([], $result->getIds());
        $this->assertSame(0, $result->totalHits);
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
        $this->assertSame(2, $result->totalHits);
    }

    public function testInsertSharedTermAcrossCallsPreservesTermAfterDelete(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]); // cache miss: INSERT path, termIdCache seeded
        $index->insert([['id' => 2, 'title' => 'sedan']]); // cache hit: UPDATE path in upsertWordlist()
        $index->delete(1);

        // If the cache-hit UPDATE was a no-op, wordlist num_hits for 'sedan' would still be 1
        // after the second insert. Deleting doc 1 (1 hit) would zero it and prune the term.
        $this->assertContains(2, $index->search('sedan')->getIds());
    }

    public function testSearchRespectsNumOfResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $result = $index->search('sedan', new SearchOptions(limit: 2));
        $this->assertCount(2, $result->getIds());
        $this->assertSame(3, $result->totalHits); // total untruncated
    }

    public function testSearchDefaultNumOfResultsIsTwenty(): void
    {
        // Insert 21 docs so the parameterless call must cap at exactly 20 — not 19 or 21.
        $index = new Index($this->dbPath);
        $index->insert(array_map(fn($i): array => ['id' => $i, 'title' => 'sedan'], range(1, 21)));

        $result = $index->search('sedan');
        $this->assertCount(20, $result->getIds());
        $this->assertSame(21, $result->totalHits);

        $resultBool = $index->searchBoolean('sedan');
        $this->assertCount(20, $resultBool->getIds());
        $this->assertSame(21, $resultBool->totalHits);

        $this->assertCount(20, $index->search('')->getIds());
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'SEDAN']]);

        $this->assertContains(1, $index->search('sedan')->getIds());
        $this->assertContains(1, $index->search('SEDAN')->getIds());
    }

    public function testSearchLowercasesMultibyteQueryViaSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'über']]);

        // MBString mutation in getWordlistByKeyword replaces mb_strtolower with strtolower.
        // 'Ü' (U+00DC) is two UTF-8 bytes; strtolower leaves it unchanged, so 'ÜBER' stays
        // uppercase and doesn't match the lowercase-stored term 'über'.
        // searchBoolean already lowercases in lexExpression, so this test must use search().
        $this->assertContains(1, $index->search('ÜBER', new SearchOptions(asYouType: false))->getIds());
    }

    public function testShortWordsSkipFuzzyGate(): void
    {
        // 'helo' is 4 codepoints — below the default minWordSizeForOneTypo of 5.
        // It must not trigger the Levenshtein fallback and must return no results.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'hello']]);

        $this->assertEmpty($index->search('helo', new SearchOptions(asYouType: false))->getIds());
    }

    public function testLongWordTypoFuzzyFires(): void
    {
        // 'hellow' is 6 codepoints — above the default minWordSizeForOneTypo of 5.
        // The Levenshtein fallback should fire and match 'hello'.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'hello']]);

        $this->assertContains(1, $index->search('hellow', new SearchOptions(asYouType: false))->getIds());
    }

    public function testSearchOrdersByRelevance(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan sedan sedan'],
        ]);

        $result = $index->search('sedan');
        $this->assertSame(2, $result->getIds()[0]);
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
        $this->assertSame(1, $result->getIds()[0]);
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
        $result = $index->search('car sedan', new SearchOptions(asYouType: false));
        $this->assertSame(1, $result->getIds()[0]);
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

        $result = $index->search('rare common', new SearchOptions(asYouType: false));

        $this->assertSame(2, $result->getIds()[0], 'Doc matching both query terms must rank first');
    }

    public function testSearchReturnsEmptyIdsWhenNumOfResultsIsZero(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $result = $index->search('sedan', new SearchOptions(limit: 0));
        $this->assertSame([], $result->getIds());
        $this->assertSame(1, $result->totalHits);
    }

    // --- as-you-type prefix ---

    public function testAsYouTypePrefixMatchesPartialWord(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);

        $result = $index->search('merc');
        $this->assertContains(1, $result->getIds());
    }

    public function testAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);

        $result = $index->search('merc', new SearchOptions(asYouType: false));
        $this->assertNotContains(1, $result->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
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
        $this->assertCount(2, $result->getIds());
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
        $result = $index->search('sedan', new SearchOptions(limit: 3));

        $this->assertCount(3, $result->getIds());
        // Doc 5 has the highest TF so it must be ranked first.
        $this->assertSame(5, $result->getIds()[0]);
        // IDs must be in descending relevance order (5 > 4 > 3 by TF).
        $this->assertSame([5, 4, 3], $result->getIds());
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
        $result = $index->search('sedan', new SearchOptions(limit: 2));
        $this->assertContains(3, $result->getIds());
        $this->assertContains(4, $result->getIds());
        $this->assertNotContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
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

        $result = $index->search('sedan', new SearchOptions(limit: 2));

        $this->assertCount(2, $result->getIds());
        $this->assertContains(1, $result->getIds());     // always in top-2
        $this->assertContains(3, $result->getIds());     // middle replaces weakest (correct heapMin)
        $this->assertNotContains(2, $result->getIds()); // weakest is evicted
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
        $result = $index->search('sedan', new SearchOptions(limit: 1));
        $this->assertCount(1, $result->getIds());
        $this->assertSame(1, $result->getIds()[0]); // doc1 has the highest BM25 score
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

        $page1 = $index->search('sedan', new SearchOptions(limit: 2, offset: 0));
        $page2 = $index->search('sedan', new SearchOptions(limit: 2, offset: 2));
        $page3 = $index->search('sedan', new SearchOptions(limit: 2, offset: 4));

        $this->assertCount(2, $page1->getIds());
        $this->assertCount(2, $page2->getIds());
        $this->assertCount(1, $page3->getIds());

        // hits is always the full total regardless of offset.
        $this->assertSame(5, $page1->totalHits);
        $this->assertSame(5, $page2->totalHits);
        $this->assertSame(5, $page3->totalHits);

        // Pages are non-overlapping and together cover all 5 docs.
        $allIds = array_merge($page1->getIds(), $page2->getIds(), $page3->getIds());
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], array_unique($allIds));

        // Page 1 must start with the best-scoring doc.
        $this->assertSame(5, $page1->getIds()[0]);
        // Pages must not overlap.
        $this->assertEmpty(array_intersect($page1->getIds(), $page2->getIds()));
        $this->assertEmpty(array_intersect($page2->getIds(), $page3->getIds()));
    }

    public function testSearchOffsetBeyondTotalReturnsEmptyIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->search('sedan', new SearchOptions(limit: 10, offset: 5));

        $this->assertSame([], $result->getIds());
        $this->assertSame(1, $result->totalHits);
    }

    public function testSearchBooleanOffsetSkipsTopResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $page1 = $index->searchBoolean('sedan', new SearchOptions(limit: 2, offset: 0));
        $page2 = $index->searchBoolean('sedan', new SearchOptions(limit: 2, offset: 2));

        $this->assertCount(2, $page1->getIds());
        $this->assertCount(1, $page2->getIds());
        $this->assertSame(3, $page1->totalHits);
        $this->assertSame(3, $page2->totalHits);
        $this->assertEmpty(array_intersect($page1->getIds(), $page2->getIds()));
    }

    // --- insertMany ---

    public function testInsertManyIndexesAllDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);

        $this->assertContains(1, $index->search('sedan')->getIds());
        $this->assertContains(2, $index->search('suv')->getIds());
    }

    public function testInsertManyWithEmptyArrayIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([]);

        $this->assertSame(0, $index->search('anything')->totalHits);
    }

    // --- update ---

    public function testUpdateReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);
        $index->update([['id' => 1, 'title' => 'suv truck']]);

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertContains(1, $index->search('suv')->getIds());
    }

    public function testUpdatePreservesTotalDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->update([['id' => 1, 'title' => 'suv']]);

        $this->assertSame(2, $index->search('suv')->totalHits + $index->search('coupe')->totalHits);
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
        $info = $index->inspectQuery('suv');
        $this->assertSame(2, $info->totalDocuments);
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
        $info = $index->inspectQuery('zeta');
        $this->assertEqualsWithDelta(1.0, $info->avgDocLength, 0.01);
    }

    // --- upsert ---

    public function testUpsertCreatesDocWhenIdNotFound(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert([['id' => 999, 'title' => 'sedan']]);

        $this->assertContains(999, $index->search('sedan')->getIds());
    }

    public function testUpsertNonExistentDocIncrementsCount(): void
    {
        $index = new Index($this->dbPath);
        $index->upsert([['id' => 1, 'title' => 'sedan']]);
        $info = $index->inspectQuery('sedan');
        $this->assertSame(1, $info->totalDocuments);
    }

    public function testUpsertReplacesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);
        $index->upsert([['id' => 1, 'title' => 'suv truck']]);

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertContains(1, $index->search('suv')->getIds());
    }

    public function testUpsertExistingDocDoesNotIncrementTotalDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->upsert([['id' => 1, 'title' => 'suv']]);

        $info = $index->inspectQuery('suv');
        $this->assertSame(2, $info->totalDocuments);
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

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertEmpty($index->search('coupe')->getIds());
        $this->assertContains(1, $index->search('suv')->getIds());
        $this->assertContains(2, $index->search('hatchback')->getIds());
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

        $info = $index->inspectQuery('suv');
        $this->assertSame(3, $info->totalDocuments);
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

        $info = $index->inspectQuery('suv');
        $this->assertSame(2, $info->totalDocuments);
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
        $this->assertContains(1, $index->search('sedan')->getIds());
        $this->assertEmpty($index->search('suv')->getIds());
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
        $info = $index->inspectQuery('eta');
        $this->assertEqualsWithDelta(1.0, $info->avgDocLength, 0.01);
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

        $this->assertContains(1, $index->search('sedan')->getIds());
        $this->assertContains(2, $index->search('coupe')->getIds());
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
        $info = $index->inspectQuery('suv');
        $this->assertSame(2, $info->totalDocuments);
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
        $info = $index->inspectQuery('alpha');
        $this->assertEqualsWithDelta(2.0, $info->avgDocLength, 0.01);
    }

    public function testUpsertManyWithEmptyIterableIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->upsert([]);

        $this->assertContains(1, $index->search('sedan')->getIds());
        $info = $index->inspectQuery('sedan');
        $this->assertSame(1, $info->totalDocuments);
    }

    // --- delete ---

    public function testDeleteRemovesDocumentFromResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan car']]);
        $index->delete(1);

        $this->assertEmpty($index->search('sedan')->getIds());
    }

    public function testDeleteDoesNotAffectOtherDocuments(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan car'],
            ['id' => 2, 'title' => 'suv truck'],
        ]);
        $index->delete(1);

        $this->assertContains(2, $index->search('suv')->getIds());
    }

    public function testDeleteNonexistentDocumentIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(999);

        $this->assertContains(1, $index->search('sedan')->getIds());
    }

    public function testDeleteDecrementsDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1);

        // NotIdentical / MethodCallRemoval / IncrementInteger mutations on the adjustStats call
        // inside delete() skip or corrupt the document-count decrement, leaving total_documents=1
        // instead of 0 after the deletion.
        $info = $index->inspectQuery('any');
        $this->assertSame(0, $info->totalDocuments);
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

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertEmpty($index->search('coupe')->getIds());
        $this->assertContains(3, $index->search('suv')->getIds());
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

        $info = $index->inspectQuery('suv');
        $this->assertSame(1, $info->totalDocuments);
    }

    public function testDeleteManyOfOnlyOneDocResetsCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1);

        // DecrementInteger mutation on `if ($docDelta !== 0)` changes 0 to -1:
        // the guard then reads `$docDelta !== -1`, which is false when exactly one doc
        // is deleted ($docDelta=-1), so adjustStats is never called and total stays at 1.
        $info = $index->inspectQuery('any');
        $this->assertSame(0, $info->totalDocuments);
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
        $info = $index->inspectQuery('zeta');
        $this->assertEqualsWithDelta(1.0, $info->avgDocLength, 0.01);
    }

    public function testDeleteManyWithEmptyArrayIsNoop(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete();

        $this->assertContains(1, $index->search('sedan')->getIds());
    }

    public function testDeleteManyIgnoresNonexistentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->delete(1, 999);

        $this->assertEmpty($index->search('sedan')->getIds());
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

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertEmpty($index->search('coupe')->getIds());
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

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertEmpty($index->search('coupe')->getIds());
        $this->assertEmpty($index->search('suv')->getIds());
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

        $info = $index->inspectQuery('any');
        $this->assertEqualsWithDelta(0.0, $info->avgDocLength, 0.001);
    }

    public function testClearAllowsReinsertionAfterwards(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->clear();
        $index->insert([['id' => 1, 'title' => 'coupe']]);

        $this->assertEmpty($index->search('sedan')->getIds());
        $this->assertContains(1, $index->search('coupe')->getIds());
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

        $this->assertSame([1 => true, 2 => true, 3 => true], $index->hasMany(1, 2, 3));
    }

    public function testHasManyReturnsMixedBooleans(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'suv'],
        ]);

        // ID 2 was never inserted — its value must be false, not absent from the map.
        $this->assertSame([1 => true, 2 => false, 3 => true], $index->hasMany(1, 2, 3));
    }

    public function testHasManyReturnsFalseForAllAbsentIds(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([99 => false, 100 => false], $index->hasMany(99, 100));
    }

    public function testHasManyWithNoArgumentsReturnsEmptyArray(): void
    {
        $index = new Index($this->dbPath);
        $this->assertSame([], $index->hasMany());
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
        $this->assertSame([5 => true, 3 => true, 1 => true], $index->hasMany(5, 3, 1));
    }

    public function testHasManyReturnsFalseForDeletedId(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'coupe'],
        ]);
        $index->delete(2);

        $this->assertSame([1 => true, 2 => false], $index->hasMany(1, 2));
    }

    // --- search (fuzzy) ---

    public function testSearchFuzzyMatchesTypo(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz', 'body' => 'luxury car']]);

        $result = $index->search('mercdes');
        $this->assertContains(1, $result->getIds());
    }

    public function testSearchFuzzyReturnsMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Volkswagen Golf']]);

        $this->assertContains(1, $index->search('volksagen')->getIds());
    }

    public function testSearchFuzzyNoMatchReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->search('xqzpwk');
        $this->assertSame([], $result->getIds());
        $this->assertSame(0, $result->totalHits);
    }

    public function testFuzzyMinWordLengthGateBlocksShortWords(): void
    {
        // Default minWordSizeForOneTypo = 5. 'sedn' is 4 codepoints — gate blocks the fallback.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertEmpty($index->search('sedn', new SearchOptions(asYouType: false))->getIds());
    }

    public function testFuzzyMinWordLengthGateAllowsLongWords(): void
    {
        // 'sedaan' is 6 codepoints — above the default gate; Levenshtein fires and matches.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertContains(1, $index->search('sedaan', new SearchOptions(asYouType: false))->getIds());
    }

    public function testMinWordSizeForOneTypoConfigurable(): void
    {
        // With minWordSizeForOneTypo=3, even 'sedn' (4 chars) triggers the fuzzy fallback.
        // 'sedn' shares the 'sed' prefix with 'sedan' (required by fuzzyPrefixLength=3)
        // and is at Levenshtein distance=1 → matched by the 5–8 char tier (1 typo allowed).
        $config = new Config(typoTolerance: new TypoTolerance(minWordSizeForOneTypo: 3));
        $index  = new Index($this->dbPath, config: $config);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertContains(1, $index->search('sedn', new SearchOptions(asYouType: false))->getIds());
    }

    public function testSwappedLettersCountAsOneTypo(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'casual shirt'], ['id' => 2, 'title' => 'formal shirt']]);

        $this->assertSame([1], $index->search('casaul', new SearchOptions(asYouType: false))->getIds());
    }

    /** @param list<int> $expected */
    private function assertTypoMatch(
        TypoTolerance $typo,
        string $query,
        array $expected,
        string $title = 'sedan',
    ): void {
        $index = new Index($this->dbPath, force: true, config: new Config(typoTolerance: $typo));
        $index->insert([['id' => 1, 'title' => $title]]);
        $result = $index->search($query, new SearchOptions(asYouType: false));

        $this->assertSame($expected, $result->getIds(), $query);
    }

    public function testTypoToleranceWordSizes(): void
    {
        $this->assertTypoMatch(new TypoTolerance(), 'sedn', []);
        $this->assertTypoMatch(new TypoTolerance(minWordSizeForOneTypo: 4), 'sedn', [1]);
        // 'seddaan' is two typos away: only allowed from minWordSizeForTwoTypos (7 codepoints here).
        $this->assertTypoMatch(new TypoTolerance(), 'seddaan', []);
        $this->assertTypoMatch(new TypoTolerance(minWordSizeForTwoTypos: 7), 'seddaan', [1]);
        $this->assertTypoMatch(new TypoTolerance(minWordSizeForTwoTypos: 8), 'seddaan', []);
    }

    public function testTypoToleranceCanBeDisabled(): void
    {
        $this->assertTypoMatch(new TypoTolerance(), 'sedaan', [1]);
        $this->assertTypoMatch(new TypoTolerance(enabled: false), 'sedaan', []);
        // Exact and prefix matching are unaffected.
        $this->assertTypoMatch(new TypoTolerance(enabled: false), 'sedan', [1]);
    }

    public function testTypoToleranceDisableOnNumbers(): void
    {
        $this->assertTypoMatch(new TypoTolerance(), 'fz04218', [1], 'fz04217');
        $this->assertTypoMatch(new TypoTolerance(disableOnNumbers: true), 'fz04218', [], 'fz04217');
        $this->assertTypoMatch(new TypoTolerance(disableOnNumbers: true), 'fz04217', [1], 'fz04217');
        $this->assertTypoMatch(new TypoTolerance(disableOnNumbers: true), 'sedaan', [1]);
    }

    public function testTypoToleranceDisableOnWords(): void
    {
        $this->assertTypoMatch(new TypoTolerance(disableOnWords: ['Sedaan']), 'sedaan', []);
        $this->assertTypoMatch(new TypoTolerance(disableOnWords: ['Sedaan']), 'seddan', [1]);
    }

    public function testTypoToleranceDisableOnWordsIsStemmed(): void
    {
        $config = new Config(typoTolerance: new TypoTolerance(disableOnWords: ['Shirtz']));
        $index  = new Index($this->dbPath, config: $config, schema: new SchemaConfig(language: 'en'));
        $index->insert([['id' => 1, 'title' => 'shirts']]);

        // 'shirtzs' stems to the listed 'shirtz', so it gets no typo either.
        $this->assertSame([], $index->search('shirtz', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([], $index->search('shirtzs', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([1], $index->search('shirst', new SearchOptions(asYouType: false))->getIds());
    }

    public function testTypoMatchIsFoundBehindMoreFrequentTermsWithTheSamePrefix(): void
    {
        // The candidates sharing the prefix used to be cut to fuzzyMaxExpansions by hit count
        // before the distance check, so frequent but distant terms crowded out the right one.
        $index = new Index($this->dbPath, config: new Config(fuzzyMaxExpansions: 2));
        $index->insert([
            ['id' => 1, 'title' => 'sedxx sedxx sedxx'],
            ['id' => 2, 'title' => 'sedyy sedyy sedyy'],
            ['id' => 3, 'title' => 'sedzz sedzz'],
            ['id' => 4, 'title' => 'sedan'],
        ]);

        $this->assertSame([4], $index->search('sedaan', new SearchOptions(asYouType: false))->getIds());
    }

    public function testTypoMatchesAreCappedAfterRankingByDistance(): void
    {
        $index = new Index($this->dbPath, config: new Config(fuzzyMaxExpansions: 1));
        $index->insert([
            ['id' => 1, 'title' => 'sedans sedans sedans'],
            ['id' => 2, 'title' => 'sedan'],
        ]);

        // 'sedam' is one typo from 'sedan' and two from the more frequent 'sedans'.
        $this->assertSame([2], $index->search('sedam', new SearchOptions(asYouType: false))->getIds());
    }

    /** @return iterable<string, array{0: int, 1: int}> */
    public static function invalidTypoWordSizes(): iterable
    {
        yield 'negative' => [-1, 9];
        yield 'one above two' => [6, 5];
        yield 'above 255' => [5, 256];
    }

    #[DataProvider('invalidTypoWordSizes')]
    public function testTypoToleranceRejectsInvalidWordSizes(int $one, int $two): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TypoTolerance(minWordSizeForOneTypo: $one, minWordSizeForTwoTypos: $two);
    }

    public function testFuzzyAutoTierShortWordCappedAtOneTypo(): void
    {
        // 'seddaan' (7 chars) is distance=2 from 'sedan'. Words < 9 codepoints are capped at
        // 1 typo, so this must NOT match.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertNotContains(1, $index->search('seddaan')->getIds());
    }

    public function testFuzzyAutoTierLongWordAllowsTwoTypos(): void
    {
        // 'volkswaagen' (11 chars) is distance=2 from 'volkswagen'. Words ≥ 9 codepoints allow
        // 2 typos, so this MUST match. 'volkswaagen' starts with 'vol' (prefix ✓).
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'volkswaagen']]);

        $this->assertContains(1, $index->search('volkswagen')->getIds());
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

        $result = $index->search('volkswage', new SearchOptions(asYouType: false));

        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $pos = array_flip($result->getIds());
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
        $this->assertSame(1, $result->getIds()[0]);
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
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
    }

    public function testSearchBooleanNot(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi sedan'],
        ]);

        $result = $index->searchBoolean('sedan -bmw');
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(1, $result->getIds());
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
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(1, $result->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
    }

    public function testSearchBooleanAsYouTypeDisabledNoPartialMatch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'bmw sedan'],
            ['id' => 2, 'title' => 'audi coupe'],
        ]);

        $result = $index->searchBoolean('bmw sed', new SearchOptions(asYouType: false));
        $this->assertEmpty($result->getIds());
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
        $this->assertEmpty($result->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
    }

    public function testSearchBooleanAndMissingTermReturnsEmpty(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->searchBoolean('sedan helicopter');
        $this->assertSame([], $result->getIds());
    }

    public function testSearchBooleanHitsExceedsNumOfResults(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'sedan'],
            ['id' => 2, 'title' => 'sedan'],
            ['id' => 3, 'title' => 'sedan'],
        ]);

        $result = $index->searchBoolean('sedan', new SearchOptions(limit: 2));
        $this->assertCount(2, $result->getIds());
        $this->assertSame(3, $result->totalHits);
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
        $this->assertContains(3, $result->getIds());
        $this->assertNotContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
    }

    public function testSearchBooleanNormalizesUnicodeUppercase(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'café']]);
        $result = $index->searchBoolean('CAFÉ', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
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
        $result = $index->searchBoolean('sedan or coupe truck', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
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
        $result = $index->searchBoolean('php -laravel', new SearchOptions(asYouType: false));
        $this->assertContains(2, $result->getIds());    // php AND NOT laravel → doc2
        $this->assertNotContains(1, $result->getIds()); // doc1 has laravel → excluded
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
        $result = $index->searchBoolean('php&(laravel or nodejs)', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
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
        $result = $index->searchBoolean('php&(laravel or nodejs)', new SearchOptions(asYouType: false));
        $this->assertCount(2, $result->getIds());
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
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
        $result = $index->searchBoolean('sedan', new SearchOptions(asYouType: false, limit: 2));
        $this->assertCount(2, $result->getIds());
        $this->assertSame(3, $result->totalHits);
        $this->assertContains(1, $result->getIds()); // first doc must survive the slice
    }

    public function testBooleanSearchLowercasesNonAsciiViaMultibyte(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'naïve']]);
        // mb_strtolower('NAÏVE') → 'naïve'. strtolower('NAÏVE') → 'naÏve' (Ï stays uppercase).
        // Without mb_, the mutated query 'naÏve' does not match 'naïve' in the wordlist.
        $result = $index->searchBoolean('NAÏVE', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
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
        $result = $index->searchBoolean('( café or latté )', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
    }

    public function testBooleanSearchFindsDocumentByUppercaseMultibyteQuery(): void
    {
        $index = new Index($this->dbPath);
        // Index the lowercase form; the query must be lowercased with mb_strtolower.
        // 'Ü' (U+00DC) is two bytes in UTF-8; strtolower leaves it unchanged.
        $index->insert([['id' => 1, 'title' => 'über']]);
        $result = $index->searchBoolean('ÜBER', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
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
        $result = $index->searchBoolean('(sedan or coupe) -electric', new SearchOptions(asYouType: false));
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
        $this->assertNotContains(4, $result->getIds());
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
        $result = $index->searchBoolean('alpha&(beta or gamma)&delta', new SearchOptions(asYouType: false));
        $this->assertCount(2, $result->getIds());
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
        $this->assertNotContains(4, $result->getIds());
    }

    /** @return list<int> */
    private function booleanIds(Index $index, string $query): array
    {
        $ids = $index->searchBoolean($query, new SearchOptions(asYouType: false))->getIds();
        sort($ids);
        return $ids;
    }

    private function booleanFixture(): Index
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'shirt'],
            ['id' => 2, 'title' => 'jeans'],
            ['id' => 3, 'title' => 'shirt jeans'],
            ['id' => 4, 'title' => 'shirt blue'],
        ]);
        return $index;
    }

    public function testBooleanOperatorsWithSpacesWork(): void
    {
        $index = $this->booleanFixture();

        // Spaces around '|' and '&' used to become implicit ANDs, returning no results at all.
        $this->assertSame([1, 2, 3, 4], $this->booleanIds($index, 'shirt | jeans'));
        $this->assertSame([3], $this->booleanIds($index, 'shirt & jeans'));
        $this->assertSame([3], $this->booleanIds($index, 'shirt  jeans'));
        $this->assertSame([1, 4], $this->booleanIds($index, 'shirt ~ jeans'));
    }

    public function testBooleanIgnoresTrailingSpaceAndDanglingOperators(): void
    {
        $index = $this->booleanFixture();

        foreach (['shirt ', 'shirt |', 'shirt or', 'or shirt', '(shirt'] as $query) {
            $this->assertSame([1, 3, 4], $this->booleanIds($index, $query), $query);
        }
    }

    public function testBooleanNegationFirstSubtractsFromTheRest(): void
    {
        $index = $this->booleanFixture();

        $this->assertSame([1, 4], $this->booleanIds($index, '~jeans shirt'));
        $this->assertSame([1], $this->booleanIds($index, '-jeans -blue shirt'));
        $this->assertSame([1], $this->booleanIds($index, 'shirt ~(jeans | blue)'));
    }

    public function testBooleanBareNegationReturnsNothingInsteadOfFailing(): void
    {
        $index = $this->booleanFixture();

        // There is no "all documents" set to subtract from, so a lone negation matches nothing.
        $this->assertSame([], $this->booleanIds($index, '~jeans'));
        $this->assertSame([], $this->booleanIds($index, '~(shirt | jeans)'));
        $this->assertSame([1, 3, 4], $this->booleanIds($index, 'shirt | ~jeans'));
    }

    public function testBooleanPunctuationRunsDoNotEmptyTheResult(): void
    {
        $index = $this->booleanFixture();

        $this->assertSame([3], $this->booleanIds($index, 'shirt + jeans'));
        $this->assertSame([3], $this->booleanIds($index, 'shirt - jeans'));
    }

    public function testBooleanWordFollowedByGroupIsAnd(): void
    {
        $index = $this->booleanFixture();

        $this->assertSame([3, 4], $this->booleanIds($index, 'shirt (jeans | blue)'));
    }

    // --- inspectQuery ---

    public function testInspectQueryRawTokensMatchTokenizer(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('Hello World');
        $this->assertSame(['hello', 'world'], $result->rawTokens);
    }

    public function testInspectQueryNoLanguageFilteredTokensEqualRaw(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('hello world');
        $this->assertSame($result->rawTokens, $result->filteredTokens);
        $this->assertFalse($result->stopwordsActive);
        $this->assertFalse($result->stemmerActive);
    }

    public function testInspectQueryStopwordsActiveWhenLanguageSet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result->stopwordsActive);
    }

    public function testInspectQueryStemmerActiveWhenLanguageSet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('hello world');
        $this->assertTrue($result->stemmerActive);
    }

    public function testInspectQueryFilteredTokensDropsStopwords(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('the quick');
        $this->assertNotContains('the', $result->filteredTokens);
        $this->assertContains('the', $result->rawTokens);
    }

    public function testInspectQueryAllStrippedTrueWhenOnlyStopwords(): void
    {
        // Single-token all-stopword query: filterQueryTokens only strips when count > 1,
        // so use two stopwords to trigger the all-stripped fallback.
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('the and');
        $this->assertTrue($result->allStripped);
        // Fallback fires — filtered_tokens equals raw_tokens.
        $this->assertSame($result->rawTokens, $result->filteredTokens);
    }

    public function testInspectQueryAllStrippedFalseWithNoLanguage(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('the and');
        $this->assertFalse($result->allStripped);
    }

    public function testInspectQueryStemmerApplied(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('running');
        // 'running' stems to 'run' in English Snowball
        $this->assertSame('run', $result->filteredTokens[0]);
    }

    public function testInspectQueryRawToProcessedMapping(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $result = $index->inspectQuery('running');
        $this->assertSame('running', $result->tokens[0]->raw);
        $this->assertSame('run', $result->tokens[0]->processed);
    }

    public function testInspectQueryFoundTrueForIndexedTerm(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertTrue($result->tokens[0]->found);
        $this->assertGreaterThanOrEqual(1, $result->tokens[0]->numDocs);
        $this->assertGreaterThanOrEqual(1, $result->tokens[0]->numHits);
    }

    public function testInspectQueryFoundFalseForMissingTerm(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('zzznomatch', asYouType: false);
        $this->assertFalse($result->tokens[0]->found);
        $this->assertSame('none', $result->tokens[0]->matchType);
        $this->assertSame(0, $result->tokens[0]->numDocs);
        $this->assertSame(0, $result->tokens[0]->numHits);
    }

    public function testInspectQueryMatchTypeExact(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertSame('exact', $result->tokens[0]->matchType);
    }

    public function testInspectQueryMatchTypePrefix(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sed');
        $this->assertSame('prefix', $result->tokens[0]->matchType);
        $terms = array_column($result->tokens[0]->wordlistRows, 'term');
        $this->assertContains('sedan', $terms);
    }

    public function testInspectQueryMatchTypeFuzzy(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('sedaan', asYouType: false);
        $this->assertSame('fuzzy', $result->tokens[0]->matchType);
        $this->assertNotNull($result->tokens[0]->wordlistRows[0]['distance']);
    }

    public function testInspectQueryMatchTypeNoneWhenNoCandidate(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $result = $index->inspectQuery('zzznomatch', asYouType: false);
        $this->assertSame('none', $result->tokens[0]->matchType);
    }

    public function testInspectQueryPrefixExpandsMultipleTerms(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'body' => 'sedan'],
            ['id' => 2, 'body' => 'sediment'],
        ]);
        $result = $index->inspectQuery('sed');
        $terms = array_column($result->tokens[0]->wordlistRows, 'term');
        $this->assertContains('sedan', $terms);
        $this->assertContains('sediment', $terms);
    }

    public function testInspectQueryIsLastOnlyTrueForFinalToken(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('fast sedan review');
        $this->assertFalse($result->tokens[0]->isLast);
        $this->assertFalse($result->tokens[1]->isLast);
        $this->assertTrue($result->tokens[2]->isLast);
    }

    public function testInspectQueryIndexInfoContainsDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $info = $index->inspectQuery('sedan');
        $this->assertSame(1, $info->totalDocuments);
        $this->assertGreaterThanOrEqual(0.0, $info->avgDocLength);
    }

    public function testInspectQueryBooleanPostfixAndOperator(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('php laravel');
        $this->assertContains('&', $result->booleanPostfix);
    }

    public function testInspectQueryBooleanPostfixOrOperator(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('php or laravel');
        $this->assertContains('|', $result->booleanPostfix);
    }

    public function testInspectQueryBooleanPostfixNotOperator(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('php -wordpress');
        $this->assertContains('~', $result->booleanPostfix);
    }

    public function testInspectQueryBooleanPostfixContainsOrForSingleTerm(): void
    {
        $index = new Index($this->dbPath);

        // Mutation ConcatOperandRemoval: toPostfix('php') → ['php'] — no '|'.
        // Original: toPostfix('|php') → ['php', '|'].
        $result = $index->inspectQuery('php');
        $this->assertContains('|', $result->booleanPostfix);
    }

    public function testInspectQueryEmptyPhraseReturnsEmptyLists(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->inspectQuery('');
        $this->assertSame([], $result->rawTokens);
        $this->assertSame([], $result->filteredTokens);
        $this->assertSame([], $result->tokens);
    }

    public function testInspectQueryDoesNotChangeDocumentCount(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $before = $index->inspectQuery('sedan')->totalDocuments;
        $index->inspectQuery('sedan');
        $this->assertSame($before, $index->inspectQuery('sedan')->totalDocuments);
    }

    public function testInspectQueryWarmsWordlistCacheForSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'body' => 'sedan']]);
        $index->inspectQuery('sedan', asYouType: false);
        // If cache is warm, search returns the same result without extra DB reads.
        $result = $index->search('sedan');
        $this->assertContains(1, $result->getIds());
    }

    public function testInspectQueryShortWordSkipsFuzzy(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        // 'sedn' is 4 codepoints — below minWordSizeForOneTypo=5.
        // Mutation TrueValue: gate removed → fuzzy fires → 'sedn' matches 'sedan' → 'fuzzy'.
        // Original: gate blocks → no match → 'none'.
        $result = $index->inspectQuery('sedn', asYouType: false);
        $this->assertSame('none', $result->tokens[0]->matchType);
    }

    public function testInspectQueryExactMatchIsNotFuzzyType(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        // Exact wordlist hit has no 'distance' key → match_type must be 'exact', not 'fuzzy'.
        $result = $index->inspectQuery('sedan', asYouType: false);
        $this->assertSame('exact', $result->tokens[0]->matchType);
    }

    public function testInspectQueryWordlistRowsHaveExactKeys(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $rows = $index->inspectQuery('sedan', asYouType: false)->tokens[0]->wordlistRows;
        $this->assertNotEmpty($rows);
        $this->assertSame('sedan', $rows[0]['term']);
    }

    public function testInspectQueryNumHitsAndNumDocsValues(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan sedan']]);

        $token = $index->inspectQuery('sedan', asYouType: false)->tokens[0];
        $this->assertSame(2, $token->numHits);
        $this->assertSame(1, $token->numDocs);
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

        $this->assertContains(1, $rebuilt->search('sedan')->getIds());
    }

    public function testRebuildRemovesOldContent(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'old content']]);
        $index->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 2, 'title' => 'new content']]);
        });

        $this->assertEmpty($rebuilt->search('old')->getIds());
        $this->assertContains(2, $rebuilt->search('new')->getIds());
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
        $this->assertContains(1, $surviving->search('original')->getIds());
        $this->assertEmpty($surviving->search('partial')->getIds());
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

        $this->assertContains(1, $rebuilt->search('sedan')->getIds());
    }

    /**
     * Start a PHP child process holding a read-write Index on $path. Each line written to the
     * returned stdin pipe is one JSON document the child inserts; it answers "ok" per insert.
     *
     * It has to be another process: SQLite's file locks are per process, so a second
     * connection in this process would see itself as the only user of a -shm file and reset it.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startWriterProcess(string $path): array
    {
        if (!function_exists('proc_open') || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('needs proc_open and POSIX file locks');
        }
        $script = sprintf(
            'require %s; $i = new Fuzor\Index(%s);'
            . ' while (($l = fgets(STDIN)) !== false) { $i->insert([json_decode($l, true)]); echo "ok\n"; }',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($path, true),
        );
        $child = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($child);
        return [$child, $pipes];
    }

    /**
     * @param array<int, resource>    $pipes
     * @param array<string, mixed>    $doc
     */
    private function insertInWriterProcess(array $pipes, array $doc): void
    {
        fwrite($pipes[0], json_encode($doc) . "\n");
        $this->assertSame("ok\n", fgets($pipes[1]));
    }

    /**
     * @param resource             $child
     * @param array<int, resource> $pipes
     */
    private function stopWriterProcess($child, array $pipes): void
    {
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($child);
    }

    public function testRebuildIgnoresTheReplacedFilesWal(): void
    {
        new Index($this->dbPath)->close();
        // Another process's open writer keeps its commits in the -wal (no checkpoint before 1000 pages).
        [$child, $pipes] = $this->startWriterProcess($this->dbPath);
        try {
            $this->insertInWriterProcess($pipes, ['id' => 1, 'title' => 'old sedan']);
            $this->insertInWriterProcess($pipes, ['id' => 2, 'title' => 'old coupe']);

            Index::rebuild($this->dbPath, function (Index $new): void {
                $new->insert([['id' => 10, 'title' => 'new wagon']]);
            })->close();

            $fresh = new Index($this->dbPath, readonly: true);
            $this->assertSame(1, $fresh->count());
            $this->assertSame([10], $fresh->search('wagon')->getIds());
            $this->assertSame([], $fresh->search('sedan')->getIds());

            // A commit through the connection still open on the old file must not reach the new one.
            $this->insertInWriterProcess($pipes, ['id' => 3, 'title' => 'late hatchback']);
            $late = new Index($this->dbPath, readonly: true);
            $this->assertSame([], $late->search('hatchback')->getIds());
            $this->assertSame([10], $late->search('wagon')->getIds());
            $late->close();
            $fresh->close();
        } finally {
            $this->stopWriterProcess($child, $pipes);
        }
    }

    public function testSnapshotToIgnoresTheReplacedFilesWal(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        new Index($readPath)->close();
        // Another process with a read-write connection on the snapshot path and commits in its -wal.
        [$child, $pipes] = $this->startWriterProcess($readPath);
        try {
            $this->insertInWriterProcess($pipes, ['id' => 1, 'title' => 'old sedan']);

            $write = new Index($this->dbPath);
            $write->insert([['id' => 10, 'title' => 'new wagon']]);
            $write->snapshotTo($readPath);
            $write->close();

            // Commits through the old connection after the swap must not reach the snapshot.
            $this->insertInWriterProcess($pipes, ['id' => 2, 'title' => 'late coupe']);

            $read = new Index($readPath, readonly: true);
            $this->assertSame(1, $read->count());
            $this->assertSame([10], $read->search('wagon')->getIds());
            $this->assertSame([], $read->search('coupe')->getIds());
            $read->close();
        } finally {
            $this->stopWriterProcess($child, $pipes);
            self::removeIndexFiles($readPath);
        }
    }

    public function testRebuildPublishesAVersionBehindASymlink(): void
    {
        new Index($this->dbPath)->close();

        Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        })->close();

        $this->assertTrue(is_link($this->dbPath));
        $target = (string) readlink($this->dbPath);
        $pattern = '/^' . preg_quote(basename($this->dbPath), '/') . '\.v-[0-9a-f]{8}$/';
        $this->assertMatchesRegularExpression($pattern, $target);

        // SQLite names the sidecars after the version, never after the link.
        $index = new Index($this->dbPath);
        $index->insert([['id' => 2, 'title' => 'coupe']]);
        $this->assertFileExists(dirname($this->dbPath) . '/' . $target . '-wal');
        $this->assertFileDoesNotExist($this->dbPath . '-wal');
        $index->close();
    }

    public function testPublishKeepsOnlyTheCurrentAndPreviousVersion(): void
    {
        $build = function (int $id): void {
            Index::rebuild($this->dbPath, function (Index $new) use ($id): void {
                $new->insert([['id' => $id, 'title' => 'sedan']]);
            })->close();
        };

        $build(1);
        $first = dirname($this->dbPath) . '/' . readlink($this->dbPath);
        $build(2);
        $second = dirname($this->dbPath) . '/' . readlink($this->dbPath);
        $this->assertFileExists($first, 'the previous version stays for connections still opening it');
        $build(3);

        $this->assertFileDoesNotExist($first);
        $this->assertFileExists($second);
        $this->assertCount(2, glob($this->dbPath . '.v-*[0-9a-f]') ?: []);
        $this->assertSame([3], new Index($this->dbPath)->search('sedan')->getIds());
    }

    public function testPublishRemovesSidecarsOfAPlainFileOnePublishLater(): void
    {
        $plain = new Index($this->dbPath);
        $plain->insert([['id' => 1, 'title' => 'sedan']]);

        Index::rebuild($this->dbPath, fn (Index $new) => $new->insert([['id' => 2, 'title' => 'sedan']]))->close();
        $this->assertFileExists($this->dbPath . '-wal', 'still in use by the open plain-file connection');

        $plain->close();
        Index::rebuild($this->dbPath, fn (Index $new) => $new->insert([['id' => 3, 'title' => 'sedan']]))->close();
        $this->assertFileDoesNotExist($this->dbPath . '-wal');
        $this->assertFileDoesNotExist($this->dbPath . '-shm');
    }

    public function testPublishNeverRemovesAVersionOfALiveBuild(): void
    {
        $other = $this->dbPath . '.v-0badc0de';
        $lock  = fopen($this->dbPath . '.tmp-0badc0de.lock', 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX);
        try {
            file_put_contents($other, 'another rebuild, between its rename and its symlink swap');
            Index::rebuild($this->dbPath, fn (Index $new) => $new->insert([['id' => 1, 'title' => 'a']]))->close();
            Index::rebuild($this->dbPath, fn (Index $new) => $new->insert([['id' => 2, 'title' => 'b']]))->close();
            $this->assertFileExists($other);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testForceCreatePublishesAnEmptyVersion(): void
    {
        $plain = new Index($this->dbPath);
        $plain->insert([['id' => 1, 'title' => 'sedan']]);
        $plain->close();

        $forced = new Index($this->dbPath, force: true, schema: new SchemaConfig(language: 'en'));
        $this->assertTrue(is_link($this->dbPath));
        $this->assertSame(0, $forced->count());
        $this->assertSame('en', $forced->language);
        $forced->insert([['id' => 2, 'title' => 'coupe']]);
        $forced->close();

        $rebuilt = Index::rebuild($this->dbPath);
        $this->assertSame([2], $rebuilt->search('coupe')->getIds());
        $this->assertSame([], $rebuilt->search('sedan')->getIds());
        $rebuilt->close();
    }

    public function testForceCreateIgnoresWritesToTheReplacedFile(): void
    {
        new Index($this->dbPath)->close();
        [$child, $pipes] = $this->startWriterProcess($this->dbPath);
        try {
            $this->insertInWriterProcess($pipes, ['id' => 1, 'title' => 'old sedan']);

            $forced = new Index($this->dbPath, force: true);
            $forced->insert([['id' => 10, 'title' => 'new wagon']]);

            // A commit through the connection still open on the old file must not reach the new one.
            $this->insertInWriterProcess($pipes, ['id' => 2, 'title' => 'late coupe']);

            $fresh = new Index($this->dbPath, readonly: true);
            $this->assertSame(1, $fresh->count());
            $this->assertSame([10], $fresh->search('wagon')->getIds());
            $this->assertSame([], $fresh->search('coupe')->getIds());
            $fresh->close();
            $forced->close();
        } finally {
            $this->stopWriterProcess($child, $pipes);
        }
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

        $this->assertContains(1, $index->search('轿车')->getIds());
    }

    public function testZhSingleCharSearch(): void
    {
        // Unigrams are emitted at index time for zh so single-character searches work.
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([['id' => 1, 'body' => '轿车测试']]);

        $this->assertContains(1, $index->search('车')->getIds());
    }

    public function testZhDoesNotMatchUnrelatedDocument(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([
            ['id' => 1, 'body' => '轿车测试'],
            ['id' => 2, 'body' => '飞机起飞'],
        ]);

        $this->assertNotContains(2, $index->search('轿车')->getIds());
    }

    public function testJaInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'ja'));
        $index->insert([['id' => 1, 'body' => '東京タワー']]);

        $this->assertContains(1, $index->search('東京')->getIds());
    }

    public function testKoInsertAndSearchBigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'ko'));
        $index->insert([['id' => 1, 'body' => '서울특별시']]);

        $this->assertContains(1, $index->search('서울')->getIds());
    }

    public function testThInsertAndSearchTrigram(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'th'));
        $index->insert([['id' => 1, 'body' => 'กรุงเทพมหานคร']]);

        // 'กรุงเท' is a trigram within the indexed text
        $this->assertContains(1, $index->search('กรุงเท')->getIds());
    }

    public function testZhBooleanSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $index->insert([
            ['id' => 1, 'body' => '轿车测试'],
            ['id' => 2, 'body' => '飞机起飞'],
        ]);

        $result = $index->searchBoolean('轿车 -飞机');
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
    }

    public function testZhQueryTokensAreNgrammed(): void
    {
        // inspectQuery must show bigrams in filtered_tokens, not the raw full string.
        $index  = new Index($this->dbPath, schema: new SchemaConfig(language: 'zh'));
        $result = $index->inspectQuery('轿车');

        $this->assertContains('轿车', $result->filteredTokens);
        // Raw tokens show the output of the base tokenizer (whole string as one unit).
        $this->assertSame(['轿车'], $result->rawTokens);
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
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
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
        $this->assertSame([1, 2], $results->getIds());
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
        $this->assertCount(2, $results->getIds());
        $this->assertContains(1, $results->getIds());
        $this->assertContains(2, $results->getIds());
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
        $this->assertCount(2, $results->getIds());
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
        $this->assertSame(1, $results->getIds()[0]);
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

        $this->assertSame([1, 2], $results->getIds());
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
        $this->assertSame(2, $results->getIds()[0]);
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
        $this->assertContains(1, $index->search('sedan')->getIds());
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
        $this->assertContains(1, $read->search('sedan')->getIds());
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
            self::removeIndexFiles($readPath);
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
            $this->assertContains(1, $read->search('sedan')->getIds());
            $this->assertContains(2, $read->search('suv')->getIds());
            $read->close();
        } finally {
            self::removeIndexFiles($readPath);
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
            $this->assertContains(1, $read->search('sedan')->getIds());
            $read->close();
        } finally {
            self::removeIndexFiles($readPath);
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
            $this->assertContains(2, $read->search('second')->getIds());
            $read->close();
        } finally {
            self::removeIndexFiles($readPath);
        }
    }

    public function testSnapshotToCleansUpStaleTempFiles(): void
    {
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        $stale    = $readPath . '.tmp-deadbeef';
        try {
            file_put_contents($stale, 'leftover');
            touch($stale, time() - 7200);

            $write = new Index($this->dbPath);
            $write->insert([['id' => 1, 'title' => 'sedan']]);
            $write->snapshotTo($readPath);

            $this->assertFileDoesNotExist($stale);
        } finally {
            self::removeIndexFiles($readPath);
            @unlink($stale);
        }
    }

    /**
     * Create files next to the test index and set their mtime $ageSeconds in the past.
     *
     * @param  list<string> $suffixes
     * @return list<string>
     */
    private function makeTempFiles(array $suffixes, int $ageSeconds): array
    {
        $paths = [];
        foreach ($suffixes as $suffix) {
            $path = $this->dbPath . $suffix;
            file_put_contents($path, 'x');
            touch($path, time() - $ageSeconds);
            $paths[] = $path;
        }
        return $paths;
    }

    /** @param list<string> $paths */
    private function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    public function testCleanupTempFilesRemovesStaleGroup(): void
    {
        $files = $this->makeTempFiles(['.tmp-aaaaaaaa', '.tmp-aaaaaaaa-wal', '.tmp-aaaaaaaa-shm'], 7200);
        try {
            $deleted = Index::cleanupTempFiles($this->dbPath);

            $expected = $files;
            sort($expected);
            $this->assertSame($expected, $deleted);
            foreach ($files as $file) {
                $this->assertFileDoesNotExist($file);
            }
        } finally {
            $this->removeFiles($files);
        }
    }

    public function testCleanupTempFilesUsesNewestFileOfGroup(): void
    {
        // During a long build only the -wal may be written to; the group is live if any file is fresh.
        $old   = $this->makeTempFiles(['.tmp-bbbbbbbb'], 7200);
        $fresh = $this->makeTempFiles(['.tmp-bbbbbbbb-wal'], 10);
        try {
            $this->assertSame([], Index::cleanupTempFiles($this->dbPath));
            $this->assertFileExists($old[0]);

            $this->assertCount(2, Index::cleanupTempFiles($this->dbPath, 0));
        } finally {
            $this->removeFiles([...$old, ...$fresh]);
        }
    }

    public function testCleanupTempFilesKeepsLockedGroupRegardlessOfAge(): void
    {
        $files = $this->makeTempFiles(['.tmp-cccccccc', '.tmp-cccccccc-wal', '.tmp-cccccccc.lock'], 7200);
        $lock  = fopen($this->dbPath . '.tmp-cccccccc.lock', 'r');
        $this->assertNotFalse($lock);
        try {
            $this->assertTrue(flock($lock, LOCK_EX));
            $this->assertSame([], Index::cleanupTempFiles($this->dbPath, 0));
            $this->assertFileExists($files[0]);

            // Once the owner is gone the lock is free and the whole group goes, lock file included.
            flock($lock, LOCK_UN);
            $this->assertCount(3, Index::cleanupTempFiles($this->dbPath));
        } finally {
            fclose($lock);
            $this->removeFiles($files);
        }
    }

    public function testCleanupTempFilesIgnoresUnrelatedFiles(): void
    {
        $files = $this->makeTempFiles([
            '.tmp-backup',
            '.tmp-1234567',
            '.tmp-ABCDEF12',
            '.tmp-deadbeef.bak',
            '.tmp-deadbeef-old',
            '-wal',
        ], 7200);
        $other = dirname($this->dbPath) . '/other_' . uniqid() . '.db.tmp-deadbeef';
        file_put_contents($other, 'x');
        touch($other, time() - 7200);
        try {
            $this->assertSame([], Index::cleanupTempFiles($this->dbPath, 0));
            foreach ([...$files, $other] as $file) {
                $this->assertFileExists($file);
            }
        } finally {
            $this->removeFiles([...$files, $other]);
        }
    }

    public function testCleanupTempFilesNeverTouchesALiveRebuild(): void
    {
        new Index($this->dbPath)->close();
        $seen = [];

        Index::rebuild($this->dbPath, function (Index $new) use (&$seen): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
            // Even with no age threshold, the build's lock protects its files.
            $seen['deleted'] = Index::cleanupTempFiles($this->dbPath, 0);
            $seen['files']   = glob($this->dbPath . '.tmp-*') ?: [];
        });

        $this->assertSame([], $seen['deleted']);
        $this->assertNotSame([], $seen['files']);
        $this->assertSame([], glob($this->dbPath . '.tmp-*'), 'no temp or lock file is left behind');
        $this->assertSame([1], new Index($this->dbPath)->search('sedan')->getIds());
    }

    public function testCleanupTempFilesRemovesLeftoversOfAKilledRebuild(): void
    {
        if (!function_exists('proc_open') || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('needs proc_open and POSIX signals');
        }
        new Index($this->dbPath)->close();
        $ready  = $this->dbPath . '.ready';
        $script = sprintf(
            'require %s; Fuzor\Index::rebuild(%s, function ($i) { $i->insert([["id" => 1, "title" => "x"]]);'
            . ' file_put_contents(%s, "1"); sleep(30); });',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($this->dbPath, true),
            var_export($ready, true),
        );
        $child = proc_open([PHP_BINARY, '-r', $script], [], $pipes);
        $this->assertIsResource($child);
        try {
            for ($i = 0; $i < 200 && !file_exists($ready); $i++) {
                usleep(25_000);
            }
            $this->assertFileExists($ready, 'child rebuild did not start');
            // While the child runs, its lock protects its files from another process's sweep.
            $this->assertSame([], Index::cleanupTempFiles($this->dbPath, 0));
            proc_terminate($child, 9); // SIGKILL: no catch, no finally, no cleanup.
            proc_close($child);

            $leftovers = glob($this->dbPath . '.tmp-*') ?: [];
            $this->assertNotSame([], $leftovers, 'the killed rebuild left its temp files behind');
            // Fresh files are kept by the default age threshold...
            $this->assertSame([], Index::cleanupTempFiles($this->dbPath));
            // ...but the dead process no longer holds the lock, so they are safe to remove.
            $this->assertSame($leftovers, Index::cleanupTempFiles($this->dbPath, 0));
            $this->assertSame([], glob($this->dbPath . '.tmp-*'));
        } finally {
            @unlink($ready);
            $this->removeFiles(glob($this->dbPath . '.tmp-*') ?: []);
        }
    }

    public function testRebuildSweepsStaleTempFiles(): void
    {
        new Index($this->dbPath)->close();
        $files = $this->makeTempFiles(['.tmp-dddddddd', '.tmp-dddddddd-shm'], 7200);
        try {
            Index::rebuild($this->dbPath, function (Index $new): void {
                $new->insert([['id' => 1, 'title' => 'sedan']]);
            });

            $this->assertSame([], glob($this->dbPath . '.tmp-*'));
        } finally {
            $this->removeFiles($files);
        }
    }

    public function testCleanupTempFilesRejectsMissingDirectory(): void
    {
        $this->expectException(IOException::class);
        Index::cleanupTempFiles('/nonexistent-dir-' . uniqid() . '/index.db');
    }

    public function testSnapshotToLeavesFreshTempFilesOfOtherRunsAlone(): void
    {
        // A temp file this young may belong to a snapshot or rebuild still running in another
        // process; deleting it would break that run's final rename.
        $readPath = sys_get_temp_dir() . '/fuzor_snap_' . uniqid() . '.db';
        $inFlight = $readPath . '.tmp-0123abcd';
        try {
            file_put_contents($inFlight, 'in progress');

            $write = new Index($this->dbPath);
            $write->insert([['id' => 1, 'title' => 'sedan']]);
            $write->snapshotTo($readPath);

            $this->assertFileExists($inFlight);
            $this->assertSame([], glob($readPath . '.tmp-*.lock'), 'the snapshot released its own lock');
        } finally {
            self::removeIndexFiles($readPath);
            @unlink($inFlight);
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
            self::removeIndexFiles($readPath);
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
        $index->getMany(1);
    }

    public function testGetReturnsNullForMissingId(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $this->assertNull($index->get(999));
    }

    public function testGetManyEmptyArgumentsReturnsEmpty(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $this->assertSame([], $index->getMany());
    }

    public function testGetManyOmitsMissingIds(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->getMany(1, 999);
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

    // --- Document store: hits ---

    public function testHitsContainIdStubsWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([['id' => 1]], $index->search('sedan')->hits);
    }

    public function testHitsContainFullDocWhenStoreEnabled(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->search('sedan')->getHit(0));
    }

    public function testHitsEmptyWhenNoResults(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([], $index->search('coupe')->hits);
    }

    // --- Document store: search hydration ---

    public function testSearchHitsStubsWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([['id' => 1]], $index->search('sedan')->hits);
    }

    public function testSearchHydratesDocuments(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'body' => 'city car'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->search('sedan')->getHit(0));
    }

    public function testSearchHitsKeyedByPosition(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([
            ['id' => 10, 'title' => 'fast sedan'],
            ['id' => 20, 'title' => 'sedan coupe'],
        ]);

        $result = $index->search('sedan');
        $byId   = array_column($result->hits, null, 'id');
        $this->assertArrayHasKey(10, $byId);
        $this->assertArrayHasKey(20, $byId);
        $this->assertSame(10, $byId[10]['id']);
        $this->assertSame(20, $byId[20]['id']);
    }

    public function testSearchBooleanHitsStubsWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertSame([['id' => 1]], $index->searchBoolean('sedan')->hits);
    }

    public function testSearchBooleanHydratesDocuments(): void
    {
        $doc   = ['id' => 1, 'title' => 'sedan', 'body' => 'city car'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true));
        $index->insert([$doc]);

        $this->assertSame($doc, $index->searchBoolean('sedan')->getHit(0));
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
            self::removeIndexFiles($snapPath);
        }
    }

    // --- _formatted (highlight / crop) ---

    /** The first hit's '_formatted' value for $field, asserted to be a string. */
    private function formattedField(SearchResult $result, string $field): string
    {
        $formatted = $result->getHit(0)['_formatted'] ?? null;
        $this->assertIsArray($formatted);
        $value = $formatted[$field] ?? null;
        $this->assertIsString($value);
        return $value;
    }

    public function testEscapeFormattedEscapesStoredText(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Fast & <Furious>']]);

        $hit = $index->search('fast', new SearchOptions(
            attributesToHighlight: ['title'],
            escapeFormatted:       true,
        ))->getHit(0);

        $this->assertSame(['title' => '<mark>Fast</mark> &amp; &lt;Furious&gt;'], $hit['_formatted']);
        $this->assertSame('Fast & <Furious>', $hit['title'], 'the original field stays raw');
    }

    public function testEscapeFormattedEscapesExactlyOnce(): void
    {
        $body  = str_repeat('filler ', 40) . 'Tom & Jerry <3 needle ' . str_repeat('filler ', 40);
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'doc', 'body' => $body, 'note' => $body]]);

        $result = $index->search('needle', new SearchOptions(
            attributesToHighlight: ['body'],
            attributesToCrop:      ['body', 'note'],
            cropLength:            40,
            escapeFormatted:       true,
        ));
        $body = $this->formattedField($result, 'body');
        $note = $this->formattedField($result, 'note');

        // Cropped and highlighted.
        $this->assertStringContainsString('Tom &amp; Jerry &lt;3 <mark>needle</mark>', $body);
        $this->assertStringStartsWith('… ', $body);
        // Cropped only: escaped by the formatter itself, not by the highlighter.
        $this->assertStringContainsString('Tom &amp; Jerry &lt;3 needle', $note);
        $this->assertStringNotContainsString('&amp;amp;', $body . $note);
        $this->assertStringNotContainsString('<mark>', $note);
    }

    public function testEscapeFormattedCropOnlyIsEscaped(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'A & B needle']]);

        $formatted = $index->search('needle', new SearchOptions(
            attributesToCrop: ['title'],
            escapeFormatted:  true,
        ))->getHit(0)['_formatted'];

        $this->assertSame(['title' => 'A &amp; B needle'], $formatted);
    }

    public function testEscapeFormattedOnStripHtmlIndexFormatsVisibleText(): void
    {
        $body  = '<p>fast</p><p>delivery &amp; returns</p><script>track()</script>';
        $index = new Index($this->dbPath, schema: new SchemaConfig(stripHtml: true));
        $index->insert([['id' => 1, 'body' => $body]]);

        $escaped = $index->search('fast', new SearchOptions(attributesToHighlight: ['body'], escapeFormatted: true));
        $raw     = $index->search('fast', new SearchOptions(attributesToHighlight: ['body']));

        $this->assertSame('<mark>fast</mark> delivery &amp; returns', $this->formattedField($escaped, 'body'));
        $this->assertSame($body, $escaped->getHit(0)['body']);
        // Without escapeFormatted the stored HTML is highlighted as before.
        $this->assertSame(
            '<p><mark>fast</mark></p><p>delivery &amp; returns</p><script>track()</script>',
            $this->formattedField($raw, 'body'),
        );
    }

    public function testIndexHighlighterAndSnippeterAcceptEscape(): void
    {
        $index = new Index($this->dbPath);

        $this->assertSame('<b>a</b> &amp;', $index->highlighter('<b>', '</b>', escape: true)->highlight('a', 'a &'));
        $this->assertSame('x &lt; y', $index->snippeter(escape: true)->snippet('x', 'x < y'));
    }

    public function testFormattedHighlightSingleField(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz', 'body' => 'Great car']]);
        $result    = $index->search('mercedes', new SearchOptions(attributesToHighlight: ['title']));
        $formatted = $result->getHit(0)['_formatted'];
        $this->assertIsArray($formatted);
        $this->assertSame('<mark>Mercedes</mark> Benz', $formatted['title']);
        $this->assertArrayNotHasKey('body', $formatted);
    }

    public function testFormattedHighlightAllFields(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes', 'body' => 'Mercedes is a brand']]);
        $result    = $index->search('mercedes', new SearchOptions(attributesToHighlight: ['*']));
        $formatted = $result->getHit(0)['_formatted'];
        $this->assertIsArray($formatted);
        $this->assertSame('<mark>Mercedes</mark>', $formatted['title']);
        $this->assertSame('<mark>Mercedes</mark> is a brand', $formatted['body']);
    }

    public function testFormattedHighlightCustomTags(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);
        $result = $index->search(
            'mercedes',
            new SearchOptions(
                attributesToHighlight: ['title'],
                highlightPreTag: '<mark>',
                highlightPostTag: '</mark>',
            ),
        );
        $formatted = $result->getHit(0)['_formatted'];
        $this->assertIsArray($formatted);
        $this->assertSame('<mark>Mercedes</mark> Benz', $formatted['title']);
    }

    public function testFormattedCropSingleField(): void
    {
        $index = new Index($this->dbPath);
        $long  = 'aaa bbb ccc ddd eee fff ggg hhh iii jjj mercedes kkk lll mmm nnn ooo ppp qqq rrr sss ttt';
        $index->insert([['id' => 1, 'title' => 'car', 'body' => $long]]);
        $result    = $index->search('mercedes', new SearchOptions(attributesToCrop: ['body'], cropLength: 80));
        $formatted = $result->getHit(0)['_formatted'];
        $this->assertIsArray($formatted);
        $this->assertArrayHasKey('body', $formatted);
        $this->assertIsString($formatted['body']);
        $this->assertStringContainsStringIgnoringCase('mercedes', $formatted['body']);
        $this->assertLessThan(mb_strlen($long), mb_strlen($formatted['body']));
        $this->assertArrayNotHasKey('title', $formatted);
    }

    public function testFormattedCropThenHighlight(): void
    {
        $index = new Index($this->dbPath);
        $pre   = str_repeat('aaa bbb ', 10);  // 80 chars before the term
        $post  = str_repeat('kkk lll ', 10);  // 80 chars after the term
        $long  = $pre . 'mercedes ' . $post;
        $index->insert([['id' => 1, 'body' => $long]]);
        $result = $index->search(
            'mercedes',
            new SearchOptions(
                attributesToCrop: ['body'],
                cropLength: 80,
                attributesToHighlight: ['body'],
            ),
        );
        $formatted = $result->getHit(0)['_formatted'];
        $this->assertIsArray($formatted);
        $this->assertArrayHasKey('body', $formatted);
        $this->assertIsString($formatted['body']);
        $this->assertStringContainsString('<mark>', $formatted['body']);
        $this->assertStringContainsStringIgnoringCase('mercedes', $formatted['body']);
        // Cropped text (without tags) must be shorter than the full body.
        $stripped = strip_tags($formatted['body']);
        $this->assertLessThan(mb_strlen($long), mb_strlen($stripped));
    }

    public function testFormattedAbsentWhenNoOptionsSet(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);
        $result = $index->search('mercedes');
        $this->assertArrayNotHasKey('_formatted', $result->getHit(0));
    }

    public function testFormattedAbsentWhenStoreDisabled(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: false));
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);
        $result = $index->search('mercedes', new SearchOptions(attributesToHighlight: ['title']));
        $this->assertSame(['id' => 1], $result->getHit(0));
    }

    public function testFormattedBooleanSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'Mercedes Benz']]);
        $result    = $index->searchBoolean('mercedes', new SearchOptions(attributesToHighlight: ['title']));
        $formatted = $result->getHit(0)['_formatted'];
        $this->assertIsArray($formatted);
        $this->assertSame('<mark>Mercedes</mark> Benz', $formatted['title']);
    }

    // --- Facets: construction ---

    // --- Facets: insert / delete isolation ---

    public function testFacetFieldNotIndexedAsText(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'hello', 'color' => 'red']]);

        // 'red' should NOT appear in search results (it's a facet value, not a text token)
        $result = $index->search('red');
        $this->assertNotContains(1, $result->getIds());
    }

    public function testInsertSingleDocWithFacets(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertContains(1, $result->getIds());
        $this->assertSame(['red' => 1], $result->facetDistribution['color']);
    }

    public function testDeleteRemovesFacetValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        $index->delete(1);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertNotContains(1, $result->getIds());
        $this->assertSame([], $result->facetDistribution);
    }

    // --- Facets: bulk insert / delete ---

    public function testInsertManyStoresFacets(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'red'],
        ]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertSame(2, $result->facetDistribution['color']['red']);
        $this->assertSame(1, $result->facetDistribution['color']['blue']);
    }

    public function testDeleteManyRemovesFacetValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
        ]);
        $index->delete(1, 2);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertSame([], $result->facetDistribution);
    }

    // --- Facets: string filter ---

    public function testSearchWithStringSingleValueFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
        ]);

        $result = $index->search('car', new SearchOptions(filter: ['color' => 'red']));
        $this->assertSame([1], $result->getIds());
        $this->assertSame(1, $result->totalHits);
    }

    public function testSearchWithStringMultiValueOrFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'green'],
        ]);

        $result = $index->search('car', new SearchOptions(filter: ['color' => ['red', 'blue']]));
        $this->assertCount(2, $result->getIds());
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
    }

    // --- Facets: numeric range filter ---

    public function testSearchWithNumericRangeFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'price' => 10000],
            ['id' => 2, 'title' => 'car', 'price' => 25000],
            ['id' => 3, 'title' => 'car', 'price' => 50000],
        ]);

        $result = $index->search('car', new SearchOptions(filter: ['price' => FacetRange::between(10000, 30000)]));
        $this->assertCount(2, $result->getIds());
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertNotContains(3, $result->getIds());
    }

    // --- Facets: exclusion filter ---

    /**
     * Six shoes with shop-style visibility terms. Docs 3 and 6 have no visibility at all; doc 6 has no price.
     */
    private function exclusionIndex(?Config $config = null): Index
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(
                filterableFields: ['visibility', 'brand', 'price', 'unused'],
                sortableFields: ['visibility', 'brand', 'price', 'unused'],
            ),
            config: $config,
        );
        $index->insert([
            ['id' => 1, 'title' => 'shoe', 'visibility' => ['exclude-from-search'], 'brand' => 'Nike', 'price' => 10],
            [
                'id'         => 2,
                'title'      => 'shoe',
                'visibility' => ['exclude-from-catalog', 'outofstock'],
                'brand'      => 'Adidas',
                'price'      => 20,
            ],
            ['id' => 3, 'title' => 'shoe', 'brand' => 'Nike', 'price' => 30],
            ['id' => 4, 'title' => 'shoe', 'visibility' => ['featured'], 'brand' => 'Puma', 'price' => 40],
            [
                'id'         => 5,
                'title'      => 'shoe',
                'visibility' => ['exclude-from-search', 'featured'],
                'brand'      => 'Adidas',
                'price'      => 50,
            ],
            ['id' => 6, 'title' => 'shoe', 'brand' => 'Puma'],
        ]);
        return $index;
    }

    /**
     * Run $options through search(), searchBoolean(), and a browse; each must match exactly $expectedIds.
     *
     * @param  list<int> $expectedIds
     * @return array<string, SearchResult>
     */
    private function assertFilterOnEveryPath(Index $index, SearchOptions $options, array $expectedIds): array
    {
        $results = [
            'search'        => $index->search('shoe', $options),
            'searchBoolean' => $index->searchBoolean('shoe', $options),
            'browse'        => $index->search('', $options),
        ];
        foreach ($results as $path => $result) {
            $this->assertEqualsCanonicalizing($expectedIds, $result->getIds(), $path);
            $this->assertSame(count($expectedIds), $result->totalHits, $path);
        }
        return $results;
    }

    public function testExcludeFilterRemovesDocsWithTheValueAndKeepsDocsWithoutTheField(): void
    {
        $index = $this->exclusionIndex();

        $results = $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: ['visibility' => new FacetExclude('exclude-from-search')]),
            [2, 3, 4, 6],
        );
        $this->assertSame([], $results['browse']->warnings);
    }

    public function testExcludeFilterWithValueListRemovesDocsWithAnyOfThem(): void
    {
        $index = $this->exclusionIndex();

        $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: ['visibility' => new FacetExclude(['outofstock', 'featured'])]),
            [1, 3, 6],
        );
    }

    public function testExcludeFilterWithRangeKeepsDocsWithoutANumericValue(): void
    {
        $index = $this->exclusionIndex();

        $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: ['price' => new FacetExclude(FacetRange::between(20, 40))]),
            [1, 5, 6],
        );
    }

    public function testExcludeFilterCombinesWithPositiveFiltersInAnyOrder(): void
    {
        $index = $this->exclusionIndex();
        $exclude = new FacetExclude('exclude-from-search');

        $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: ['brand' => ['Nike', 'Adidas'], 'visibility' => $exclude]),
            [2, 3],
        );
        $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: ['visibility' => $exclude, 'price' => FacetRange::min(30)]),
            [3, 4],
        );
    }

    public function testExcludeFiltersOnSeveralFieldsAllApply(): void
    {
        $index = $this->exclusionIndex();

        $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: [
                'visibility' => new FacetExclude('outofstock'),
                'brand'      => new FacetExclude('Nike'),
            ]),
            [4, 5, 6],
        );
    }

    public function testExcludeFilterAppliesWhenCountingItsOwnField(): void
    {
        $index = $this->exclusionIndex();
        $options = new SearchOptions(
            filter: ['visibility' => new FacetExclude('exclude-from-search')],
            facets: ['visibility', 'brand'],
        );

        foreach ($this->assertFilterOnEveryPath($index, $options, [2, 3, 4, 6]) as $path => $result) {
            $visibility = $result->facetDistribution['visibility'];
            $brand      = $result->facetDistribution['brand'];
            ksort($visibility);
            ksort($brand);
            $this->assertSame(['exclude-from-catalog' => 1, 'featured' => 1, 'outofstock' => 1], $visibility, $path);
            $this->assertSame(['Adidas' => 1, 'Nike' => 1, 'Puma' => 2], $brand, $path);
        }
    }

    public function testExcludeFilterStillAppliesToDisjunctiveCountsOfPositiveFilters(): void
    {
        $index = $this->exclusionIndex();
        $options = new SearchOptions(
            filter: ['brand' => 'Nike', 'visibility' => new FacetExclude('exclude-from-search')],
            facets: ['brand'],
        );

        foreach ($this->assertFilterOnEveryPath($index, $options, [3]) as $path => $result) {
            // Brand is counted without its own filter, but never over the excluded docs 1 and 5.
            $brand = $result->facetDistribution['brand'];
            ksort($brand);
            $this->assertSame(['Adidas' => 1, 'Nike' => 1, 'Puma' => 2], $brand, $path);
        }
    }

    public function testExcludeFilterThatExcludesNothingKeepsEveryDoc(): void
    {
        $index = $this->exclusionIndex();

        foreach (['unused' => new FacetExclude('x'), 'visibility' => new FacetExclude([])] as $field => $exclude) {
            $options = new SearchOptions(filter: [$field => $exclude], facets: ['brand']);
            foreach ($this->assertFilterOnEveryPath($index, $options, [1, 2, 3, 4, 5, 6]) as $path => $result) {
                $this->assertSame([], $result->warnings, "{$field} {$path}");
                $this->assertSame(6, array_sum($result->facetDistribution['brand']), "{$field} {$path}");
            }
            $facets = $index->facetSearch(new FacetSearchQuery(facetName: 'brand', filter: [$field => $exclude]));
            $this->assertSame(6, array_sum(array_column($facets->facetHits, 'count')), $field);
        }
    }

    public function testFacetSearchWithExcludeFilter(): void
    {
        $index = $this->exclusionIndex();
        $filter = ['visibility' => new FacetExclude('exclude-from-search')];

        foreach (['', 'shoe'] as $query) {
            $result = $index->facetSearch(new FacetSearchQuery(facetName: 'brand', query: $query, filter: $filter));
            $counts = array_column($result->facetHits, 'count', 'value');
            ksort($counts);
            $this->assertSame(['Adidas' => 1, 'Nike' => 1, 'Puma' => 2], $counts, "query '{$query}'");

            $own = $index->facetSearch(new FacetSearchQuery(facetName: 'visibility', query: $query, filter: $filter));
            $this->assertNotContains('exclude-from-search', array_column($own->facetHits, 'value'), "query '{$query}'");
        }
    }

    /**
     * Facet counts from every path, keys sorted, so the browse complement paths can be compared
     * with the search paths, which count the kept candidates directly.
     *
     * @param  array<string, SearchResult> $results
     * @return array<string, array{0: array<string, array<array-key, int>>, 1: array<string, mixed>}>
     */
    private function sortedFacets(array $results): array
    {
        return array_map(function (SearchResult $result): array {
            $distribution = $result->facetDistribution;
            foreach ($distribution as &$counts) {
                ksort($counts);
            }
            ksort($distribution);
            return [$distribution, $result->facetStats];
        }, $results);
    }

    public function testExclusionOnlyBrowseFacetCountsAreExactAtAnyCap(): void
    {
        // Two docs are excluded: cap 1 scans each key with NOT IN, caps 2 and 100 subtract the
        // excluded docs' counts from the whole-index counts. Both must match what the search
        // paths count over the kept candidates when nothing caps them (cap 100, run first).
        $reference = null;
        foreach ([100, 2, 1] as $cap) {
            $this->tearDown();
            $index   = $this->exclusionIndex(new Config(maxFacetCountDocs: $cap));
            $options = new SearchOptions(
                filter: ['brand' => new FacetExclude('Nike')],
                facets: ['brand', 'visibility', 'price'],
            );
            $results   = $this->assertFilterOnEveryPath($index, $options, [2, 4, 5, 6]);
            $facets    = $this->sortedFacets($results);
            $reference ??= $facets['search'];

            $this->assertSame([], $results['browse']->approximateFacets, "cap {$cap}");
            $this->assertSame($reference, $facets['browse'], "cap {$cap}");
            // Price 10 (doc 1) is gone, so the minimum moves up to 20.
            $this->assertSame(['min' => 20.0, 'max' => 50.0], $results['browse']->facetStats['price'], "cap {$cap}");
            $this->assertSame(['Adidas' => 2, 'Puma' => 2], $facets['browse'][0]['brand'], "cap {$cap}");
        }
    }

    public function testExclusionOnlyBrowseStatsAppearWhenOnlyNumbersRemain(): void
    {
        $reference = null;
        foreach ([100, 1] as $cap) {
            $this->tearDown();
            $index = new Index(
                $this->dbPath,
                schema: new SchemaConfig(filterableFields: ['size', 'group'], sortableFields: ['size', 'group']),
                config: new Config(maxFacetCountDocs: $cap),
            );
            $index->insert([
                ['id' => 1, 'title' => 'shoe', 'size' => 'L', 'group' => 'a'],
                ['id' => 2, 'title' => 'shoe', 'size' => 10, 'group' => 'b'],
                ['id' => 3, 'title' => 'shoe', 'size' => 20, 'group' => 'b'],
                ['id' => 4, 'title' => 'shoe', 'size' => 'L', 'group' => 'a'],
            ]);
            $options = new SearchOptions(filter: ['group' => new FacetExclude('a')], facets: ['size']);
            $facets     = $this->sortedFacets($this->assertFilterOnEveryPath($index, $options, [2, 3]));
            $reference ??= $facets['search'];

            $this->assertSame($reference, $facets['browse'], "cap {$cap}");
            $this->assertSame(['size' => ['min' => 10.0, 'max' => 20.0]], $facets['browse'][1], "cap {$cap}");
        }
    }

    public function testExclusionOnlyBrowseTotalCountsOverlappingExclusionsOnce(): void
    {
        $index = $this->exclusionIndex();

        // Doc 1 matches both exclusions, doc 5 matches two values of the first one.
        $this->assertFilterOnEveryPath(
            $index,
            new SearchOptions(filter: [
                'visibility' => new FacetExclude(['exclude-from-search', 'featured']),
                'brand'      => new FacetExclude('Nike'),
            ]),
            [2, 6],
        );
    }

    public function testFacetSearchExclusionOnlyWithPrefix(): void
    {
        $index = $this->exclusionIndex();

        $result = $index->facetSearch(new FacetSearchQuery(
            facetName:  'brand',
            facetQuery: 'a',
            filter:     ['visibility' => new FacetExclude('exclude-from-search')],
        ));

        $this->assertSame([['value' => 'Adidas', 'count' => 1]], $result->facetHits);
    }

    public function testBrowseExcludeFilterShapesAgree(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        // Broad (probed walk): everything but red. Selective (materialised IN): 1 of 12 left, once
        // driven by a positive filter and once by exclusions alone.
        $broad    = $index->search('', new SearchOptions(filter: ['color' => new FacetExclude('red')], limit: 3));
        $positive = $index->search('', new SearchOptions(
            filter: ['color' => 'red', 'brand' => new FacetExclude('Zeta')],
        ));
        $onlyExclusions = $index->search('', new SearchOptions(
            filter: ['color' => new FacetExclude('blue'), 'brand' => new FacetExclude('Zeta')],
        ));

        $this->assertSame([11, 10, 8], $broad->getIds());
        $this->assertSame(8, $broad->totalHits);
        $this->assertSame([3], $positive->getIds());
        $this->assertSame([3], $onlyExclusions->getIds());
        $this->assertSame(1, $onlyExclusions->totalHits);
    }

    public function testBrowseDistinctWithExcludeFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(
            filter:   ['color' => new FacetExclude('blue')],
            sort:     ['price:asc'],
            distinct: 'brand',
        ));

        $this->assertSame([3, 6], $result->getIds());
        $this->assertSame(2, $result->totalHits);
    }

    // --- Facets: counts ---

    public function testSearchFacetCountsStringFacet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'red'],
            ['id' => 3, 'title' => 'car', 'color' => 'blue'],
        ]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertSame(2, $result->facetDistribution['color']['red']);
        $this->assertSame(1, $result->facetDistribution['color']['blue']);
        $this->assertNull($result->facetDistribution['color']['green'] ?? null);
    }

    public function testSearchFacetCountsNumericFacet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'price' => 10000.0],
            ['id' => 2, 'title' => 'car', 'price' => 20000.0],
            ['id' => 3, 'title' => 'car', 'price' => 30000.0],
        ]);

        $result = $index->search('car', new SearchOptions(facets: ['price']));
        $this->assertSame(1, $result->facetDistribution['price'][10000]);
        $this->assertSame(1, $result->facetDistribution['price'][20000]);
        $this->assertSame(1, $result->facetDistribution['price'][30000]);
        $this->assertSame(['min' => 10000.0, 'max' => 30000.0], $result->facetStats['price']);
    }

    public function testFacetStatsKeepAZeroMinOrMax(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'delta'],
            sortableFields: ['price', 'delta'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'item', 'price' => 0, 'delta' => -10],
            ['id' => 2, 'title' => 'item', 'price' => 5, 'delta' => 0],
            ['id' => 3, 'title' => 'item', 'price' => 15.5, 'delta' => -2.5],
        ]);

        // A browse counts each key with a whole-key scan, a search with the doc-driven join.
        foreach (['browse' => '', 'search' => 'item'] as $path => $query) {
            $stats = $index->search($query, new SearchOptions(facets: ['price', 'delta']))->facetStats;
            $this->assertSame(['min' => 0.0, 'max' => 15.5], $stats['price'], $path);
            $this->assertSame(['min' => -10.0, 'max' => 0.0], $stats['delta'], $path);
        }
    }

    public function testFacetDistributionRespectMaxValuesPerFacet(): void
    {
        $index = new Index(
            $this->dbPath,
            config: new Config(maxValuesPerFacet: 2),
            schema: new SchemaConfig(filterableFields: ['color'], sortableFields: ['color']),
        );
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'red'],
            ['id' => 3, 'title' => 'car', 'color' => 'blue'],
            ['id' => 4, 'title' => 'car', 'color' => 'green'],
        ]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        // Top 2 by count: red (2), then one of blue/green (1 each)
        $this->assertCount(2, $result->facetDistribution['color']);
        $this->assertSame(2, $result->facetDistribution['color']['red']);
    }

    public function testFacetDistributionUnlimitedWhenMaxValuesPerFacetIsZero(): void
    {
        $index = new Index(
            $this->dbPath,
            config: new Config(maxValuesPerFacet: 0),
            schema: new SchemaConfig(filterableFields: ['color'], sortableFields: ['color']),
        );
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'green'],
        ]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertCount(3, $result->facetDistribution['color']);
    }

    // --- Facets: disjunctive counts ---

    public function testDisjunctiveFacetCountsShowAllValuesWhenFiltered(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
            ['id' => 3, 'title' => 'car', 'color' => 'red'],
        ]);

        // Filter by 'red' but count against the full result set for the 'color' key
        $result = $index->search('car', new SearchOptions(filter: ['color' => 'red'], facets: ['color']));
        // Disjunctive: both 'red' (2) and 'blue' (1) should appear even though filter is active
        $this->assertSame(2, $result->facetDistribution['color']['red']);
        $this->assertSame(1, $result->facetDistribution['color']['blue']);
    }

    // --- Facets: multi-value per document ---

    public function testMultiValueFacetOnSingleDocument(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => ['red', 'blue']]]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertSame(1, $result->facetDistribution['color']['red']);
        $this->assertSame(1, $result->facetDistribution['color']['blue']);
    }

    // --- Facets: boolean search ---

    public function testSearchBooleanWithFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car sedan', 'color' => 'red'],
            ['id' => 2, 'title' => 'car coupe', 'color' => 'blue'],
        ]);

        $result = $index->searchBoolean('car', new SearchOptions(filter: ['color' => 'red']));
        $this->assertSame([1], $result->getIds());
        $this->assertSame(1, $result->totalHits);
    }

    public function testSearchBooleanWithFacetCounts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'car', 'color' => 'red'],
            ['id' => 2, 'title' => 'car', 'color' => 'blue'],
        ]);

        $result = $index->searchBoolean('car', new SearchOptions(facets: ['color']));
        $this->assertSame(1, $result->facetDistribution['color']['red']);
        $this->assertSame(1, $result->facetDistribution['color']['blue']);
    }

    // --- Facets: no data for requested key ---

    public function testFacetCountsEmptyWhenNoFacetData(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['color']));
        $index->insert([['id' => 1, 'title' => 'car']]);

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertSame([], $result->facetDistribution);
    }

    // --- facetSearch ---

    public function testFacetSearchReturnsAllValuesOrderedByCountDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 3, 'title' => 'doc', 'genre' => 'Drama'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre'));

        $this->assertSame(2, count($result));
        $this->assertSame([
            ['value' => 'Action', 'count' => 2],
            ['value' => 'Drama',  'count' => 1],
        ], $result->facetHits);
    }

    public function testFacetSearchPrefixMatchesCaseInsensitively(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Science Fiction'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Action'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', facetQuery: 'sc'));

        $this->assertCount(1, $result);
        $this->assertSame('Science Fiction', $result->facetHits[0]['value']);
        $this->assertSame('sc', $result->facetQuery);
    }

    public function testFacetSearchPrefixUppercaseInputMatchesMixedCaseValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Science Fiction'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Action'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', facetQuery: 'SC'));

        $this->assertCount(1, $result);
        $this->assertSame('Science Fiction', $result->facetHits[0]['value']);
    }

    public function testFacetSearchPrefixNoMatchReturnsEmpty(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', facetQuery: 'zzz'));

        $this->assertSame([], $result->facetHits);
    }

    public function testFacetSearchEmptyPrefixReturnsAllValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Drama'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', facetQuery: ''));

        $this->assertCount(2, $result);
    }

    public function testFacetSearchLikeSpecialCharsInPrefixAreEscaped(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['tag'], sortableFields: ['tag']));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'tag' => '50%_off'],
            ['id' => 2, 'title' => 'doc', 'tag' => 'sale'],
        ]);

        // Without escaping, '%' would wildcard-match everything; '_' would match any char.
        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'tag', facetQuery: '50%'));

        $this->assertCount(1, $result);
        $this->assertSame('50%_off', $result->facetHits[0]['value']);
    }

    public function testFacetSearchFtsQueryRestrictsCandidateDocs(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'adventure movie', 'genre' => 'Action'],
            ['id' => 2, 'title' => 'drama film',      'genre' => 'Drama'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', query: 'adventure'));

        $this->assertCount(1, $result);
        $this->assertSame('Action', $result->facetHits[0]['value']);
        $this->assertSame(1, $result->facetHits[0]['count']);
    }

    public function testFacetSearchFtsQueryMultiKeywordRequiresBothWords(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'space adventure', 'genre' => 'Action'],   // matches both
            ['id' => 2, 'title' => 'space opera',     'genre' => 'Drama'],    // matches 'space' only
            ['id' => 3, 'title' => 'time adventure',  'genre' => 'Comedy'],   // matches 'adventure' only
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', query: 'space adventure'));

        $values = array_column($result->facetHits, 'value');
        $this->assertContains('Action', $values);
        $this->assertNotContains('Drama', $values);
        $this->assertNotContains('Comedy', $values);
    }

    public function testFacetSearchFtsQueryPhraseIsApplied(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'science fiction film', 'genre' => 'Sci-Fi'],  // phrase present, adjacent
            ['id' => 2, 'title' => 'fiction about science', 'genre' => 'Drama'],  // words present but not adjacent
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', query: '"science fiction"'));

        $values = array_column($result->facetHits, 'value');
        $this->assertContains('Sci-Fi', $values);
        $this->assertNotContains('Drama', $values);
    }

    public function testFacetSearchFtsQueryNoMatchReturnsEmpty(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'adventure', 'genre' => 'Action'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', query: 'zzznomatch'));

        $this->assertSame([], $result->facetHits);
    }

    public function testFacetSearchWhitespaceOnlyQueryIsEquivalentToNoRestriction(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Drama'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', query: '   '));

        $this->assertCount(2, $result);
    }

    public function testFacetSearchStringFilterRestrictsCandidateDocs(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre', 'lang'],
            sortableFields: ['genre', 'lang'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action', 'lang' => 'en'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Drama',  'lang' => 'fr'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', filter: ['lang' => 'en']));

        $this->assertCount(1, $result);
        $this->assertSame('Action', $result->facetHits[0]['value']);
    }

    public function testFacetSearchNumericRangeFilterRestrictsCandidateDocs(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre', 'year'],
            sortableFields: ['genre', 'year'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action', 'year' => 2010],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Drama',  'year' => 1990],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(
            facetName: 'genre',
            filter:    ['year' => FacetRange::min(2000)],
        ));

        $this->assertCount(1, $result);
        $this->assertSame('Action', $result->facetHits[0]['value']);
    }

    public function testFacetSearchFtsAndFilterAndPrefixCombined(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre', 'year'],
            sortableFields: ['genre', 'year'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'space adventure', 'genre' => 'Science Fiction', 'year' => 2010],
            ['id' => 2, 'title' => 'space adventure', 'genre' => 'Action',          'year' => 2010],
            ['id' => 3, 'title' => 'space adventure', 'genre' => 'Science Fiction', 'year' => 1990],
            ['id' => 4, 'title' => 'comedy film',     'genre' => 'Science Fiction', 'year' => 2010],
        ]);

        // prefix 'sc' + FTS 'space adventure' + year >= 2000 → only id 1 qualifies
        $result = $index->facetSearch(new FacetSearchQuery(
            facetName: 'genre',
            facetQuery: 'sc',
            query: 'space adventure',
            filter: ['year' => FacetRange::min(2000)],
        ));

        $this->assertCount(1, $result);
        $this->assertSame('Science Fiction', $result->facetHits[0]['value']);
        $this->assertSame(1, $result->facetHits[0]['count']);
    }

    public function testFacetSearchLimitCapsResults(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 3, 'title' => 'doc', 'genre' => 'Drama'],
            ['id' => 4, 'title' => 'doc', 'genre' => 'Comedy'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', limit: 2));

        $this->assertCount(2, $result);
        // Top value by count must be first
        $this->assertSame('Action', $result->facetHits[0]['value']);
    }

    public function testFacetSearchFilterWithoutQueryIsExact(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(filterableFields: ['genre', 'color'], sortableFields: ['genre', 'color']),
        );
        $docs = [];
        for ($i = 1; $i <= 7; $i++) {
            $docs[] = ['id' => $i, 'title' => 'doc', 'genre' => $i % 2 ? 'Action' : 'Drama', 'color' => 'red'];
        }
        $index->insert($docs);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', filter: ['color' => 'red']));

        $this->assertSame(
            [['value' => 'Action', 'count' => 4], ['value' => 'Drama', 'count' => 3]],
            $result->facetHits,
        );
    }

    public function testFacetSearchQueryWithNoMatchesReturnsEmptyWithFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre', 'color'],
            sortableFields: ['genre', 'color'],
        ));
        $index->insert([['id' => 1, 'title' => 'doc', 'genre' => 'Action', 'color' => 'red']]);

        $result = $index->facetSearch(new FacetSearchQuery(
            facetName: 'genre',
            query:     'nothingmatches',
            filter:    ['color' => 'red'],
        ));

        $this->assertSame([], $result->facetHits);
    }

    public function testFacetSearchDeclaredButUnpopulatedFieldDoesNotWarn(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre', 'year'],
            sortableFields: ['genre', 'year'],
        ));
        $index->insert([['id' => 1, 'title' => 'doc', 'genre' => 'Action']]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'year'));

        $this->assertSame([], $result->facetHits);
        $this->assertSame([], $result->warnings);
    }

    public function testFacetSearchEmptyIndexReturnsEmpty(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre'));

        $this->assertSame([], $result->facetHits);
    }

    public function testFacetSearchMultiValueFacetCountsEachValueOnce(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['tag'], sortableFields: ['tag']));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'tag' => ['php', 'search']],
            ['id' => 2, 'title' => 'doc', 'tag' => ['php', 'sqlite']],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'tag'));

        $counts = array_column($result->facetHits, 'count', 'value');
        $this->assertSame(2, $counts['php']);
        $this->assertSame(1, $counts['search']);
        $this->assertSame(1, $counts['sqlite']);
    }

    public function testFacetSearchPrefixOnNumericFacetMatchesStringRepresentation(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['year'],
            sortableFields: ['year'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'year' => 2010],
            ['id' => 2, 'title' => 'doc', 'year' => 2014],
            ['id' => 3, 'title' => 'doc', 'year' => 1990],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'year', facetQuery: '201'));

        $values = array_column($result->facetHits, 'value');
        $this->assertContains('2010', $values);
        $this->assertContains('2014', $values);
        $this->assertNotContains('1990', $values);
    }

    public function testFacetSearchMultiValueOrFilterRestrictsCandidateDocs(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre', 'lang'],
            sortableFields: ['genre', 'lang'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action', 'lang' => 'en'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Drama',  'lang' => 'fr'],
            ['id' => 3, 'title' => 'doc', 'genre' => 'Comedy', 'lang' => 'de'],
        ]);

        $result = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', filter: ['lang' => ['en', 'fr']]));

        $values = array_column($result->facetHits, 'value');
        $this->assertContains('Action', $values);
        $this->assertContains('Drama', $values);
        $this->assertNotContains('Comedy', $values);
    }

    public function testFacetSearchResultIsIterable(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'doc', 'genre' => 'Action'],
            ['id' => 2, 'title' => 'doc', 'genre' => 'Drama'],
        ]);

        $result   = $index->facetSearch(new FacetSearchQuery(facetName: 'genre'));
        $iterated = [];
        foreach ($result as $hit) {
            $iterated[] = $hit['value'];
        }

        $this->assertContains('Action', $iterated);
        $this->assertContains('Drama', $iterated);
    }

    public function testFacetSearchResultToArray(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['genre'],
            sortableFields: ['genre'],
        ));
        $index->insert([['id' => 1, 'title' => 'doc', 'genre' => 'Action']]);

        $arr = $index->facetSearch(new FacetSearchQuery(facetName: 'genre', facetQuery: 'ac'))->toArray();

        $this->assertArrayHasKey('facetHits', $arr);
        $this->assertArrayHasKey('facetQuery', $arr);
        $this->assertSame('ac', $arr['facetQuery']);
        $this->assertSame([['value' => 'Action', 'count' => 1]], $arr['facetHits']);
        $this->assertSame([], $arr['warnings']);
    }

    // --- filterableFields / sortableFields / searchableFields schema persistence ---

    public function testFacetFieldsPersistedAcrossReopen(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color', 'brand'],
            sortableFields: ['color', 'brand'],
        )))->close();

        $index = new Index($this->dbPath);
        $this->assertSame(['color', 'brand'], $index->filterableFields);
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

        $this->assertSame([], $index->search('car')->getIds());
    }

    public function testFacetFieldNotReturnedByFts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'scarlet']]);

        $this->assertSame([], $index->search('scarlet')->getIds());
        $this->assertSame([1], $index->search('car')->getIds());
    }

    public function testSearchableFieldsRestrictsTokenization(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(searchableFields: ['title']));
        $index->insert([['id' => 1, 'title' => 'car', 'sku' => 'ABC-123']]);

        $this->assertSame([1], $index->search('car')->getIds());
        $this->assertSame([], $index->search('ABC')->getIds());
    }

    public function testFieldInBothFacetAndSearchableIsIndexedAndFaceted(): void
    {
        $schema = new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
            searchableFields: ['title', 'brand'],
        );
        $index  = new Index($this->dbPath, schema: $schema);
        $index->insert([['id' => 1, 'title' => 'watch', 'brand' => 'Casio']]);

        // brand is searchable
        $this->assertSame([1], $index->search('casio')->getIds());
        // brand is also faceted
        $result = $index->search('casio', new SearchOptions(facets: ['brand']));
        $this->assertSame(1, $result->facetDistribution['brand']['Casio']);
    }

    public function testRebuildInheritsFacetAndSearchableFields(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
            searchableFields: ['title'],
        )))->close(); // phpcs:ignore

        Index::rebuild($this->dbPath, function (Index $idx): void {
            $idx->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        });

        $index = new Index($this->dbPath);
        $this->assertSame(['color'], $index->filterableFields);
        $this->assertSame(['title'], $index->searchableFields);
    }

    public function testStoredOnlyFieldAppearsInDocumentStore(): void
    {
        $doc   = ['id' => 1, 'title' => 'car', 'image_url' => 'https://example.com/car.jpg'];
        $index = new Index($this->dbPath, schema: new SchemaConfig(store: true, searchableFields: ['title']));
        $index->insert([$doc]);

        // image_url is stored but not indexed
        $this->assertSame([], $index->search('example')->getIds());
        $this->assertSame($doc, $index->get(1));
    }

    // --- Facets: rebuild ---

    public function testRebuildPreservesFacets(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        )))->close();

        Index::rebuild($this->dbPath, function (Index $idx): void {
            $idx->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        });

        $index = new Index($this->dbPath);
        $this->assertSame(['color'], $index->filterableFields);
    }

    // --- Facets: clear ---

    public function testClearRemovesFacetValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'car', 'color' => 'red']]);
        $index->clear();

        $result = $index->search('car', new SearchOptions(facets: ['color']));
        $this->assertSame([], $result->facetDistribution);
    }

    // --- Sort ---

    public function testSortByNumericFacetAsc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame([2, 3, 1], $index->search('product', new SearchOptions(sort: ['price:asc']))->getIds());
    }

    public function testSortByNumericFacetDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame([1, 3, 2], $index->search('product', new SearchOptions(sort: ['price:desc']))->getIds());
    }

    public function testSortByStringFacetAsc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Adidas'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Puma'],
        ]);
        $this->assertSame([2, 1, 3], $index->search('product', new SearchOptions(sort: ['brand:asc']))->getIds());
    }

    public function testSortByStringFacetDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Adidas'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Puma'],
        ]);
        $this->assertSame([3, 1, 2], $index->search('product', new SearchOptions(sort: ['brand:desc']))->getIds());
    }

    public function testSortDirectionCaseInsensitive(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
        ]);
        $this->assertSame([2, 1], $index->search('product', new SearchOptions(sort: ['price:ASC']))->getIds());
    }

    public function testSortNullsLastAsc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'price' => 5],
        ]);
        $this->assertSame([3, 1, 2], $index->search('product', new SearchOptions(sort: ['price:asc']))->getIds());
    }

    public function testSortNullsLastDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'price' => 5],
        ]);
        $this->assertSame([1, 3, 2], $index->search('product', new SearchOptions(sort: ['price:desc']))->getIds());
    }

    public function testSortMultiKey(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['category', 'price'],
            sortableFields: ['category', 'price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'category' => 'b', 'price' => 20],
            ['id' => 2, 'title' => 'product', 'category' => 'a', 'price' => 30],
            ['id' => 3, 'title' => 'product', 'category' => 'a', 'price' => 10],
            ['id' => 4, 'title' => 'product', 'category' => 'b', 'price' => 5],
        ]);
        $result = $index->search('product', new SearchOptions(sort: ['category:asc', 'price:asc']));
        $this->assertSame([3, 2, 4, 1], $result->getIds());
    }

    public function testSortWithFacetFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['category', 'price'],
            sortableFields: ['category', 'price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'category' => 'a', 'price' => 20],
            ['id' => 2, 'title' => 'product', 'category' => 'b', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'category' => 'a', 'price' => 5],
        ]);
        $result = $index->search('product', new SearchOptions(filter: ['category' => 'a'], sort: ['price:asc']));
        $this->assertSame([3, 1], $result->getIds());
        $this->assertSame(2, $result->totalHits);
    }

    public function testSortDoesNotAffectHitsCount(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $this->assertSame(
            $index->search('product')->totalHits,
            $index->search('product', new SearchOptions(sort: ['price:asc']))->totalHits,
        );
    }

    public function testSortDoesNotAffectFacetCounts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color'],
            sortableFields: ['price', 'color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30, 'color' => 'red'],
            ['id' => 2, 'title' => 'product', 'price' => 10, 'color' => 'blue'],
            ['id' => 3, 'title' => 'product', 'price' => 20, 'color' => 'red'],
        ]);
        $this->assertSame(
            $index->search('product', new SearchOptions(facets: ['color']))->facetDistribution,
            $index->search('product', new SearchOptions(facets: ['color'], sort: ['price:asc']))->facetDistribution,
        );
    }

    public function testSortWithPagination(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 40],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 30],
            ['id' => 4, 'title' => 'product', 'price' => 20],
        ]);
        $page1 = $index->search('product', new SearchOptions(limit: 2, offset: 0, sort: ['price:asc']));
        $page2 = $index->search('product', new SearchOptions(limit: 2, offset: 2, sort: ['price:asc']));
        $this->assertSame([2, 4], $page1->getIds());
        $this->assertSame([3, 1], $page2->getIds());
    }

    /** An index with 'brand' filterable only, 'price' sortable only, and 'size' in both lists. */
    private function fieldContractIndex(): Index
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand', 'size'],
            sortableFields: ['price', 'size'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike', 'price' => 30, 'size' => 'M'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Adidas', 'price' => 10, 'size' => 'L'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Nike', 'price' => 20, 'size' => 'L'],
        ]);
        return $index;
    }

    /** @return iterable<string, array{0: SearchOptions, 1: string}> */
    public static function undeclaredFieldReferences(): iterable
    {
        yield 'sort undeclared' => [new SearchOptions(sort: ['weight:asc']), "Sort field 'weight' is not sortable"];
        yield 'sort on a filterable-only field' => [
            new SearchOptions(sort: ['brand:asc']),
            "Sort field 'brand' is not sortable",
        ];
        yield 'sort id without declaring it' => [
            new SearchOptions(sort: ['id:asc']),
            "Sort field 'id' is not sortable",
        ];
        yield 'second sort spec undeclared' => [
            new SearchOptions(sort: ['price:asc', 'title:asc']),
            "Sort field 'title'",
        ];
        yield 'filter undeclared' => [
            new SearchOptions(filter: ['color' => 'red']),
            "Filter field 'color' is not filterable",
        ];
        yield 'filter on a sortable-only field' => [
            new SearchOptions(filter: ['price' => '10']),
            "Filter field 'price' is not filterable",
        ];
        yield 'exclusion on an undeclared field' => [
            new SearchOptions(filter: ['tags' => new FacetExclude('x')]),
            "Filter field 'tags' is not filterable",
        ];
        yield 'facets undeclared' => [
            new SearchOptions(facets: ['brand', 'color']),
            "Facet field 'color' is not filterable",
        ];
        yield 'facets on a sortable-only field' => [
            new SearchOptions(facets: ['price']),
            "Facet field 'price' is not filterable",
        ];
        yield 'distinct undeclared' => [new SearchOptions(distinct: 'sku'), "Distinct field 'sku' is not filterable"];
        yield 'distinct on a sortable-only field' => [
            new SearchOptions(distinct: 'price'),
            "Distinct field 'price' is not filterable",
        ];
    }

    #[DataProvider('undeclaredFieldReferences')]
    public function testUndeclaredFieldReferenceThrowsOnEveryPath(SearchOptions $options, string $message): void
    {
        $index = $this->fieldContractIndex();
        $paths = [
            'search'        => fn() => $index->search('product', $options),
            'searchBoolean' => fn() => $index->searchBoolean('product', $options),
            'browse'        => fn() => $index->search('', $options),
        ];
        foreach ($paths as $path => $run) {
            try {
                $run();
                $this->fail("{$path}: expected a QueryException");
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $path);
            }
        }
    }

    public function testUndeclaredFieldMessageListsTheDeclaredFields(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            "Sort field 'brand' is not sortable. Declare it in SchemaConfig::\$sortableFields when creating"
            . ' the index (declared: price, size).',
        );
        $this->fieldContractIndex()->search('', new SearchOptions(sort: ['brand:asc']));
    }

    public function testUndeclaredFieldMessageWithNoDeclaredFields(): void
    {
        $index = new Index($this->dbPath);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('(declared: none)');
        $index->search('', new SearchOptions(filter: ['color' => 'red']));
    }

    /** @return iterable<string, array{0: FacetSearchQuery, 1: string}> */
    public static function undeclaredFacetSearchReferences(): iterable
    {
        yield 'facetName undeclared' => [
            new FacetSearchQuery(facetName: 'color'),
            "Facet field 'color' is not filterable",
        ];
        yield 'facetName sortable-only' => [
            new FacetSearchQuery(facetName: 'price'),
            "Facet field 'price' is not filterable",
        ];
        yield 'filter undeclared' => [
            new FacetSearchQuery(facetName: 'brand', filter: ['color' => 'red']),
            "Filter field 'color' is not filterable",
        ];
        yield 'exclusion undeclared' => [
            new FacetSearchQuery(facetName: 'brand', query: 'product', filter: ['tags' => new FacetExclude('x')]),
            "Filter field 'tags' is not filterable",
        ];
    }

    #[DataProvider('undeclaredFacetSearchReferences')]
    public function testFacetSearchUndeclaredFieldThrows(FacetSearchQuery $query, string $message): void
    {
        $index = $this->fieldContractIndex();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($message);
        $index->facetSearch($query);
    }

    public function testSortableOnlyAndFilterableOnlyFieldsWork(): void
    {
        $index = $this->fieldContractIndex();

        $sorted = $index->search('product', new SearchOptions(filter: ['brand' => 'Nike'], sort: ['price:asc']));
        $this->assertSame([3, 1], $sorted->getIds());
        $this->assertSame([], $sorted->warnings);
        $browse = $index->search('', new SearchOptions(
            filter: ['size' => 'L'],
            facets: ['brand', 'size'],
            sort: ['size:asc', 'price:desc'],
        ));
        $this->assertSame([3, 2], $browse->getIds());
        $this->assertSame(['Adidas' => 1, 'Nike' => 1], $browse->facetDistribution['brand']);
        $this->assertSame(
            [['value' => 'Nike', 'count' => 2], ['value' => 'Adidas', 'count' => 1]],
            $index->facetSearch(new FacetSearchQuery(facetName: 'brand'))->facetHits,
        );
    }

    public function testFilterableAndSortableFieldsAreNotTokenised(): void
    {
        $index = $this->fieldContractIndex();

        $this->assertSame([], $index->search('nike', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([], $index->search('adidas', new SearchOptions(asYouType: false))->getIds());
    }

    public function testDeclaredButUnpopulatedFieldIsNotAnError(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['weight'],
        ));
        $index->insert([['id' => 1, 'title' => 'product']]);

        $result = $index->search('product', new SearchOptions(
            filter: ['color' => new FacetExclude('red')],
            facets: ['color'],
            sort: ['weight:asc'],
            distinct: 'color',
        ));

        $this->assertSame([1], $result->getIds());
        $this->assertSame([], $result->facetDistribution);
        $this->assertSame([], $result->warnings);
        $this->assertSame([], $index->facetSearch(new FacetSearchQuery(facetName: 'color'))->facetHits);
    }

    public function testSortDeclaredButUnpopulatedFieldDoesNotWarn(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'weight'],
            sortableFields: ['price', 'weight'],
        ));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);

        $result = $index->search('product', new SearchOptions(sort: ['weight:asc']));

        $this->assertSame([1], $result->getIds());
        $this->assertSame([], $result->warnings);
    }

    public function testSortByIdWhenDeclaredAsFacetField(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['id'], sortableFields: ['id']));
        $index->insert([
            ['id' => 3, 'title' => 'product red'],
            ['id' => 1, 'title' => 'product product'],
            ['id' => 20, 'title' => 'product'],
        ]);
        $index->insert([['id' => 7, 'title' => 'product blue']]);

        foreach (['search', 'searchBoolean'] as $method) {
            foreach (['', 'product'] as $query) {
                $asc  = $index->$method($query, new SearchOptions(sort: ['id:asc']));
                $desc = $index->$method($query, new SearchOptions(sort: ['id:desc']));
                $this->assertSame([1, 3, 7, 20], $asc->getIds(), "{$method}('{$query}') asc");
                $this->assertSame([20, 7, 3, 1], $desc->getIds(), "{$method}('{$query}') desc");
                $this->assertSame([], $asc->warnings);
            }
        }
    }

    public function testSortNumbersBeforeStringsInBothDirections(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['size'],
            sortableFields: ['size'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'size' => 'M'],
            ['id' => 2, 'title' => 'product', 'size' => 42],
            ['id' => 3, 'title' => 'product', 'size' => 'L'],
            ['id' => 4, 'title' => 'product', 'size' => 38],
            ['id' => 5, 'title' => 'product'],
        ]);

        $asc  = $index->search('product', new SearchOptions(sort: ['size:asc']))->getIds();
        $desc = $index->search('product', new SearchOptions(sort: ['size:desc']))->getIds();

        $this->assertSame([4, 2, 3, 1, 5], $asc);
        $this->assertSame([2, 4, 1, 3, 5], $desc);
    }

    public function testSortNumericLookingStringsCompareAsBytes(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['code'],
            sortableFields: ['code'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'code' => '9'],
            ['id' => 2, 'title' => 'product', 'code' => '10'],
            ['id' => 3, 'title' => 'product', 'code' => '100'],
        ]);

        $result = $index->search('product', new SearchOptions(sort: ['code:asc']));

        $this->assertSame([2, 3, 1], $result->getIds());
    }

    /** @return iterable<string, array{0: string}> */
    public static function sortPaths(): iterable
    {
        yield 'search' => ['search'];
        yield 'searchBoolean' => ['boolean'];
        yield 'browse' => ['browse'];
    }

    /** @return list<int> */
    private function sortedIds(Index $index, string $path, SearchOptions $options): array
    {
        $result = match ($path) {
            'search'  => $index->search('product', $options),
            'boolean' => $index->searchBoolean('product', $options),
            default   => $index->search('', $options),
        };
        return $result->getIds();
    }

    #[DataProvider('sortPaths')]
    public function testSortStringsIgnoreCaseButNotAccents(string $path): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(sortableFields: ['name']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'name' => 'apple'],
            ['id' => 2, 'title' => 'product', 'name' => 'Zebra'],
            ['id' => 3, 'title' => 'product', 'name' => 'Éclair'],
            ['id' => 4, 'title' => 'product', 'name' => 'Banana'],
            ['id' => 5, 'title' => 'product', 'name' => 'ÖL'],
            ['id' => 6, 'title' => 'product', 'name' => 'öko'],
        ]);

        // 'é' and 'ö' sort after 'z' (accents are not folded); 'ÖL' folds to 'öl', after 'öko'.
        $this->assertSame([1, 4, 2, 3, 6, 5], $this->sortedIds($index, $path, new SearchOptions(sort: ['name:asc'])));
        $this->assertSame([5, 6, 3, 2, 4, 1], $this->sortedIds($index, $path, new SearchOptions(sort: ['name:desc'])));
    }

    #[DataProvider('sortPaths')]
    public function testSortValuesDifferingOnlyInCaseTie(string $path): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(sortableFields: ['name', 'rank']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'name' => 'apple', 'rank' => 2],
            ['id' => 2, 'title' => 'product', 'name' => 'APPLE', 'rank' => 1],
            ['id' => 3, 'title' => 'product', 'name' => 'Apple', 'rank' => 3],
            ['id' => 4, 'title' => 'product', 'name' => 'apricot', 'rank' => 0],
        ]);

        $asc  = new SearchOptions(sort: ['name:asc', 'rank:asc']);
        $desc = new SearchOptions(sort: ['name:desc', 'rank:desc']);

        $this->assertSame([2, 1, 3, 4], $this->sortedIds($index, $path, $asc));
        $this->assertSame([4, 3, 1, 2], $this->sortedIds($index, $path, $desc));
    }

    #[DataProvider('sortPaths')]
    public function testMultiValueSortUsesFoldedExtremes(string $path): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(sortableFields: ['tags']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'tags' => ['banana', 'Zulu']],
            ['id' => 2, 'title' => 'product', 'tags' => ['Apple', 'mango']],
            ['id' => 3, 'title' => 'product', 'tags' => ['cherry', 'CHERRY']],
        ]);

        // Ascending by the smallest folded value, descending by the largest.
        $this->assertSame([2, 1, 3], $this->sortedIds($index, $path, new SearchOptions(sort: ['tags:asc'])));
        $this->assertSame([1, 2, 3], $this->sortedIds($index, $path, new SearchOptions(sort: ['tags:desc'])));
    }

    /** @return list<array{0: int, 1: string, 2: int}> rows of sort_keys, read straight from the file */
    private function sortKeyRows(): array
    {
        $stmt = new \PDO('sqlite:' . $this->dbPath)
            ->query('SELECT key_id, sort_key, doc_id FROM sort_keys ORDER BY 1, 2, 3');
        $this->assertNotFalse($stmt);
        /** @var list<array{0: int, 1: string, 2: int}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
        return $rows;
    }

    public function testSortKeysAreKeptOnlyForSortableStrings(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand', 'color'],
            sortableFields: ['brand', 'price'],
        ));
        $index->insert([['id' => 1, 'title' => 'a', 'brand' => 'Nike', 'color' => 'Red', 'price' => 5]]);
        $index->insert([
            ['id' => 2, 'title' => 'b', 'brand' => ['ACME', 'acme', 'Zeta'], 'color' => 'Blue', 'price' => 7],
            ['id' => 3, 'title' => 'c', 'brand' => 'Óscar'],
        ]);
        // Only brand (a sortable string field): no color (filterable only), no price (numeric).
        $rows = $this->sortKeyRows();

        $this->assertCount(1, array_unique(array_column($rows, 0)));
        $this->assertSame(
            [['acme', 2], ['nike', 1], ['zeta', 2], ['óscar', 3]],
            array_map(static fn(array $r): array => [$r[1], $r[2]], $rows),
        );
    }

    public function testSortKeysFollowUpdatesDeletesAndClear(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(sortableFields: ['brand']));
        $index->insert([
            ['id' => 1, 'title' => 'a', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'b', 'brand' => 'Puma'],
            ['id' => 3, 'title' => 'c', 'brand' => 'Fila'],
        ]);

        $index->update([['id' => 1, 'title' => 'a', 'brand' => 'Asics']]);
        $index->upsert([['id' => 2, 'title' => 'b']]);
        $index->delete(3);
        $this->assertSame([[1, 'asics', 1]], $this->sortKeyRows());

        $index->upsert([
            ['id' => 4, 'title' => 'd', 'brand' => 'Umbro'],
            ['id' => 5, 'title' => 'e', 'brand' => 'Kappa'],
        ]);
        $index->delete(1, 4);
        $this->assertSame([[1, 'kappa', 5]], $this->sortKeyRows());

        $index->clear();
        $this->assertSame([], $this->sortKeyRows());
    }

    public function testDistinctGroupsByExactValueNotSortKey(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'nike'],
        ]);

        $result = $index->search('', new SearchOptions(sort: ['brand:asc'], distinct: 'brand'));

        $this->assertSame([1, 2], $result->getIds());
    }

    public function testSortMixedStringsIsIndependentOfInsertionOrder(): void
    {
        // Loose comparison compared '9' and '10' numerically but '10a' byte-wise, which is not
        // a total order: the result depended on the order candidates arrived in.
        $orders = [];
        foreach ([['9', '10', '10a'], ['10a', '10', '9'], ['10', '10a', '9']] as $n => $codes) {
            $index = new Index($this->dbPath, force: true, schema: new SchemaConfig(
                filterableFields: ['code'],
                sortableFields: ['code'],
            ));
            $docs  = [];
            foreach ($codes as $i => $code) {
                $docs[] = ['id' => $i + 1, 'title' => 'product', 'code' => $code];
            }
            $index->insert($docs);
            $hits = $index->search('product', new SearchOptions(
                sort: ['code:asc'],
                attributesToRetrieve: ['code'],
            ))->hits;
            $orders[$n] = array_column($hits, 'code');
            $index->close();
        }

        $this->assertSame(['10', '10a', '9'], $orders[0]);
        $this->assertSame($orders[0], $orders[1]);
        $this->assertSame($orders[0], $orders[2]);
    }

    public function testSortMultiValueFieldUsesSmallestAscAndLargestDesc(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['size'],
            sortableFields: ['size'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'size' => [1, 50]],
            ['id' => 2, 'title' => 'product', 'size' => [10]],
            ['id' => 3, 'title' => 'product', 'size' => [20, 30]],
        ]);

        $asc  = $index->search('product', new SearchOptions(sort: ['size:asc']))->getIds();
        $desc = $index->search('product', new SearchOptions(sort: ['size:desc']))->getIds();

        $this->assertSame([1, 2, 3], $asc);
        $this->assertSame([1, 3, 2], $desc);
    }

    public function testSortOnBooleanSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 30],
            ['id' => 2, 'title' => 'product', 'price' => 10],
            ['id' => 3, 'title' => 'product', 'price' => 20],
        ]);
        $result = $index->searchBoolean('product', new SearchOptions(sort: ['price:asc']));
        $this->assertSame([2, 3, 1], $result->getIds());
    }

    public function testSortBooleanNullsLast(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'price' => 10],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'price' => 5],
        ]);
        $result = $index->searchBoolean('product', new SearchOptions(sort: ['price:asc']));
        $this->assertSame([3, 1, 2], $result->getIds());
    }

    public function testSortInvalidSpecThrowsOnSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->expectException(\InvalidArgumentException::class);
        $index->search('product', new SearchOptions(sort: ['price_asc']));
    }

    public function testSortInvalidDirectionThrows(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->expectException(\InvalidArgumentException::class);
        $index->search('product', new SearchOptions(sort: ['price:up']));
    }

    public function testSortInvalidSpecThrowsOnBoolean(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->expectException(\InvalidArgumentException::class);
        $index->searchBoolean('product', new SearchOptions(sort: ['price:up']));
    }

    public function testEmptySortIsNoop(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([['id' => 1, 'title' => 'product', 'price' => 10]]);
        $this->assertContains(1, $index->search('product', new SearchOptions(sort: []))->getIds());
    }

    // --- Browse (empty phrase) ---

    public function testBrowseEmptyPhraseReturnsAllDocsInsertionOrderDesc(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'apple'],
            ['id' => 2, 'title' => 'banana'],
            ['id' => 3, 'title' => 'cherry'],
        ]);
        $this->assertSame([3, 2, 1], $index->search('')->getIds());
    }

    public function testBrowseWhitespaceOnlyPhraseRoutes(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'apple'],
            ['id' => 2, 'title' => 'banana'],
        ]);
        $this->assertSame([2, 1], $index->search('   ')->getIds());
    }

    public function testBrowseEmptyIndexReturnsEmptyResult(): void
    {
        $index = new Index($this->dbPath);
        $result = $index->search('');
        $this->assertSame([], $result->getIds());
        $this->assertSame(0, $result->totalHits);
    }

    public function testBrowseTotalHits(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'apple'],
            ['id' => 2, 'title' => 'banana'],
            ['id' => 3, 'title' => 'cherry'],
        ]);
        $this->assertSame(3, $index->search('')->totalHits);
    }

    public function testBrowsePagination(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'a'],
            ['id' => 2, 'title' => 'b'],
            ['id' => 3, 'title' => 'c'],
            ['id' => 4, 'title' => 'd'],
        ]);
        $result = $index->search('', new SearchOptions(limit: 2, offset: 1));
        $this->assertSame([3, 2], $result->getIds());
        $this->assertSame(4, $result->totalHits);
    }

    public function testBrowseWithSort(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'a', 'price' => 30],
            ['id' => 2, 'title' => 'b', 'price' => 10],
            ['id' => 3, 'title' => 'c', 'price' => 20],
        ]);
        $this->assertSame([2, 3, 1], $index->search('', new SearchOptions(sort: ['price:asc']))->getIds());
    }

    public function testBrowseWithFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['category'],
            sortableFields: ['category'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'a', 'category' => 'shoes'],
            ['id' => 2, 'title' => 'b', 'category' => 'shirts'],
            ['id' => 3, 'title' => 'c', 'category' => 'shoes'],
        ]);
        $result = $index->search('', new SearchOptions(filter: ['category' => 'shoes']));
        $this->assertSame([3, 1], $result->getIds());
        $this->assertSame(2, $result->totalHits);
    }

    public function testBrowseWithFacetCounts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'a', 'color' => 'red'],
            ['id' => 2, 'title' => 'b', 'color' => 'blue'],
            ['id' => 3, 'title' => 'c', 'color' => 'red'],
        ]);
        $result = $index->search('', new SearchOptions(facets: ['color']));
        $this->assertSame(['red' => 2, 'blue' => 1], $result->facetDistribution['color']);
    }

    public function testBrowseViaBooleanSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'apple'],
            ['id' => 2, 'title' => 'banana'],
        ]);
        $this->assertSame([2, 1], $index->searchBoolean('')->getIds());
    }

    public function testBrowseSortAndFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['category', 'price'],
            sortableFields: ['category', 'price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'a', 'category' => 'shoes', 'price' => 50],
            ['id' => 2, 'title' => 'b', 'category' => 'shirts', 'price' => 20],
            ['id' => 3, 'title' => 'c', 'category' => 'shoes', 'price' => 30],
        ]);
        $result = $index->search('', new SearchOptions(filter: ['category' => 'shoes'], sort: ['price:asc']));
        $this->assertSame([3, 1], $result->getIds());
    }

    // --- Browse: exact SQL path ---

    /**
     * Twelve products; the oldest are the cheapest, so a newest-first candidate window misses them.
     *
     * @return list<array<string, mixed>>
     */
    private function browseCatalog(): array
    {
        $docs = [];
        for ($i = 1; $i <= 12; $i++) {
            $docs[] = [
                'id'    => $i,
                'title' => 'product',
                'price' => $i * 10,
                'color' => $i % 3 === 0 ? 'red' : 'blue',
                'brand' => $i <= 4 ? 'Acme' : 'Zeta',
            ];
        }
        return $docs;
    }

    public function testBrowseIsExactBeyondCandidateCaps(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(
                filterableFields: ['price', 'color', 'brand'],
                sortableFields: ['price', 'color', 'brand'],
            ),
            config: new Config(maxFacetCountDocs: 2),
        );
        $index->insert($this->browseCatalog());

        $sorted = $index->search('', new SearchOptions(sort: ['price:asc'], limit: 3));
        $this->assertSame([1, 2, 3], $sorted->getIds());
        $this->assertSame(12, $sorted->totalHits);

        $filtered = $index->search('', new SearchOptions(filter: ['color' => 'blue'], facets: ['color']));
        $this->assertSame(8, $filtered->totalHits);
        $this->assertSame([11, 10, 8, 7, 5, 4, 2, 1], $filtered->getIds());
        // Disjunctive with no other filter: counted over the whole index, so exact at any size.
        $this->assertSame(['blue' => 8, 'red' => 4], $filtered->facetDistribution['color']);

        $unfiltered = $index->search('', new SearchOptions(facets: ['brand']));
        $this->assertSame(['Zeta' => 8, 'Acme' => 4], $unfiltered->facetDistribution['brand']);
    }

    public function testBrowseFilteredFacetCountsUnderCapAreExact(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(filter: ['color' => 'blue'], facets: ['brand', 'color']));

        $this->assertSame(['Zeta' => 5, 'Acme' => 3], $result->facetDistribution['brand']);
        $this->assertSame(['blue' => 8, 'red' => 4], $result->facetDistribution['color']);
    }

    public function testBrowseFilteredFacetCountsAreCappedLikeSearch(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(
                filterableFields: ['price', 'color', 'brand'],
                sortableFields: ['price', 'color', 'brand'],
            ),
            config: new Config(maxFacetCountDocs: 3),
        );
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(filter: ['color' => 'blue'], facets: ['brand']));

        $this->assertSame(8, $result->totalHits);
        $this->assertSame(3, array_sum($result->facetDistribution['brand']));
    }

    public function testBrowseFacetStatsWithFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(filter: ['brand' => 'Zeta'], facets: ['price', 'brand']));

        $this->assertSame(['min' => 50.0, 'max' => 120.0], $result->facetStats['price']);
        $this->assertCount(8, $result->facetDistribution['price']);
        $this->assertArrayNotHasKey('brand', $result->facetStats);
    }

    public function testBrowseSortMatchesInMemorySortReference(): void
    {
        // The SQL walk must order exactly like sortDocIdsBySpecs(), which searchBoolean() uses
        // over the same candidates: mixed types, multi-value fields, missing values, ties,
        // secondary specs, both filter shapes (probed for broad filters, IN for selective), and
        // exclusions, which the walk probes with NOT EXISTS.
        $schema = new SchemaConfig(
            filterableFields: ['size', 'group', 'color', 'unused'],
            sortableFields: ['size', 'group', 'color', 'unused'],
        );
        $index  = new Index($this->dbPath, schema: $schema);
        $sizes  = [7, 'L', [3, 40], 12, null, 'M', 7, [2.5, 'XL'], 30, null];
        $sizes  = [...$sizes, 'S', 12, 7, [15, 1], 'L', 22, null, 9, 3, 'M'];
        // Mixed case: 'l' ties with 'L', 'xs' sorts between 'S'/'s' and 'XL'; 'Ä' after all ASCII.
        $sizes  = [...$sizes, 'l', 's', ['xs', 'M'], 'Ä', ['m', 'Xl'], 'ä'];
        $docs   = [];
        foreach ($sizes as $i => $size) {
            $doc = [
                'id'    => $i + 1,
                'title' => 'product',
                'group' => ['a', 'b', 'c'][$i % 3],
                'color' => $i % 7 === 0 ? 'red' : 'blue',
            ];
            if ($size !== null) {
                $doc['size'] = $size;
            }
            $docs[] = $doc;
        }
        $index->insert($docs);

        $sorts   = [
            ['size:asc'],
            ['size:desc'],
            ['group:asc', 'size:desc'],
            ['group:desc', 'size:asc'],
            ['size:asc', 'group:desc'],
            ['unused:asc', 'size:desc'],
        ];
        $filters = [
            [],
            ['group' => ['a', 'b']],
            ['color' => 'red'],
            ['size' => FacetRange::between(3, 20)],
            ['size' => new FacetExclude(['L', 'M'])],
            ['size' => ['l', 'L', 'Ä']],
            ['size' => new FacetExclude(FacetRange::between(3, 20))],
            ['color' => new FacetExclude('blue'), 'group' => ['a', 'b']],
            ['group' => new FacetExclude('c'), 'color' => new FacetExclude('red')],
        ];
        $pages   = [[0, 5], [3, 4], [0, 100], [17, 5]];
        foreach ($sorts as $sort) {
            foreach ($filters as $filter) {
                foreach ($pages as [$offset, $limit]) {
                    $options  = new SearchOptions(filter: $filter, sort: $sort, offset: $offset, limit: $limit);
                    $expected = $index->searchBoolean('product', $options);
                    $actual   = $index->search('', $options);
                    $label    = json_encode([$sort, $filter, $offset, $limit]);
                    $this->assertSame($expected->getIds(), $actual->getIds(), "ids {$label}");
                    $this->assertSame($expected->totalHits, $actual->totalHits, "totalHits {$label}");
                }
            }
        }
    }

    public function testRangeOnTheSortFieldOfASingleValuedKeyMatchesTheReference(): void
    {
        // Single-valued price: the browse walk seeks into the range instead of probing past every
        // price outside it. It must order exactly like the in-memory sort (searchBoolean()).
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'group'],
            sortableFields: ['price', 'group'],
        ));
        $prices = [12, 7.5, 'n/a', 30, null, 7.5, 18, 3, 25, null, 12, 9, 'free', 40, 15, 12, 22, 5, 18, 11];
        $docs   = [];
        foreach ($prices as $i => $price) {
            $doc = ['id' => $i + 1, 'title' => 'product', 'group' => ['a', 'b', 'c'][$i % 3]];
            if ($price !== null) {
                $doc['price'] = $price;
            }
            $docs[] = $doc;
        }
        $index->insert($docs);

        $ranges = [FacetRange::between(7, 20), FacetRange::min(15), FacetRange::max(9), FacetRange::between(100, 200)];
        $sorts  = [['price:asc'], ['price:desc'], ['price:asc', 'group:desc']];
        foreach ($ranges as $range) {
            foreach ($sorts as $sort) {
                foreach ([[0, 3], [2, 4], [0, 50]] as [$offset, $limit]) {
                    foreach ([[], ['group' => ['a', 'b']]] as $extra) {
                        $options  = new SearchOptions(
                            filter: ['price' => $range, ...$extra],
                            sort: $sort,
                            offset: $offset,
                            limit: $limit,
                        );
                        $label    = json_encode([$range, $sort, $offset, $limit, $extra]);
                        $expected = $index->searchBoolean('product', $options);
                        $actual   = $index->search('', $options);
                        $this->assertSame($expected->getIds(), $actual->getIds(), "ids {$label}");
                        $this->assertSame($expected->totalHits, $actual->totalHits, "total {$label}");
                    }
                }
            }
        }
    }

    public function testFilteredBrowseWithFacetsKeepsAnExactTotalAboveTheFacetCap(): void
    {
        $index = new Index($this->dbPath, config: new Config(maxFacetCountDocs: 3), schema: new SchemaConfig(
            filterableFields: ['color', 'brand'],
        ));
        $docs = [];
        for ($id = 1; $id <= 6; $id++) {
            $docs[] = [
                'id'    => $id,
                'title' => 'x',
                'color' => $id <= 5 ? 'red' : 'blue',
                'brand' => $id % 2 ? 'A' : 'B',
            ];
        }
        $index->insert($docs);

        $capped = $index->search('', new SearchOptions(filter: ['color' => 'red'], facets: ['brand']));
        $under  = $index->search('', new SearchOptions(filter: ['color' => 'blue'], facets: ['brand']));

        $this->assertSame(5, $capped->totalHits);
        $this->assertSame(['brand'], $capped->approximateFacets);
        $this->assertSame(3, array_sum($capped->facetDistribution['brand']));
        $this->assertSame(1, $under->totalHits);
        $this->assertSame([], $under->approximateFacets);
        $this->assertSame(['B' => 1], $under->facetDistribution['brand']);
    }

    public function testValueListTotalsOnSingleAndMultiValuedKeys(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['color', 'tags']));
        $index->insert([
            ['id' => 1, 'title' => 'x', 'color' => 'red', 'tags' => ['a', 'b']],
            ['id' => 2, 'title' => 'x', 'color' => 'blue', 'tags' => ['b']],
            ['id' => 3, 'title' => 'x', 'color' => 'green', 'tags' => ['a', 'c']],
        ]);

        $single = $index->search('', new SearchOptions(filter: ['color' => ['red', 'blue']]));
        $multi  = $index->search('', new SearchOptions(filter: ['tags' => ['a', 'b']]));

        $this->assertSame(2, $single->totalHits);
        $this->assertSame([2, 1], $single->getIds());
        $this->assertSame(3, $multi->totalHits);
        $this->assertSame([3, 2, 1], $multi->getIds());
    }

    public function testBrowseUnsortedFilterShapesAgree(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        // 'blue' matches 8/12 (probed walk); 'Acme' + 'red' matches 1/12 (materialised IN).
        $broad     = $index->search('', new SearchOptions(filter: ['color' => 'blue'], limit: 3, offset: 2));
        $selective = $index->search('', new SearchOptions(filter: ['color' => 'red', 'brand' => 'Acme']));

        $this->assertSame([8, 7, 5], $broad->getIds());
        $this->assertSame([3], $selective->getIds());
        $this->assertSame(1, $selective->totalHits);
    }

    public function testBrowseEmptyFilterValueListMatchesNothing(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(filter: ['color' => []], facets: ['brand']));

        $this->assertSame(0, $result->totalHits);
        $this->assertSame([], $result->getIds());
        $this->assertSame([], $result->facetDistribution);
    }

    public function testBrowseLimitZeroReportsExactTotal(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(
                filterableFields: ['price', 'color', 'brand'],
                sortableFields: ['price', 'color', 'brand'],
            ),
            config: new Config(maxFacetCountDocs: 2),
        );
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(filter: ['brand' => 'Zeta'], limit: 0));

        $this->assertSame([], $result->getIds());
        $this->assertSame(8, $result->totalHits);
    }

    public function testBrowseDistinctIsExactBeyondCandidateCaps(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(
                filterableFields: ['price', 'color', 'brand'],
                sortableFields: ['price', 'color', 'brand'],
            ),
            config: new Config(maxFacetCountDocs: 2),
        );
        $index->insert($this->browseCatalog());

        $result = $index->search('', new SearchOptions(sort: ['price:asc'], distinct: 'brand'));

        $this->assertSame([1, 5], $result->getIds());
        $this->assertSame(2, $result->totalHits);
    }

    public function testBrowseSortedPagesConcatenateToFullOrder(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['price', 'color', 'brand'],
            sortableFields: ['price', 'color', 'brand'],
        ));
        $index->insert($this->browseCatalog());

        $full  = $index->search('', new SearchOptions(sort: ['brand:desc', 'price:desc'], limit: 100))->getIds();
        $paged = [];
        for ($offset = 0; $offset < 12; $offset += 5) {
            $page  = $index->search('', new SearchOptions(
                sort:   ['brand:desc', 'price:desc'],
                limit:  5,
                offset: $offset,
            ));
            $paged = [...$paged, ...$page->getIds()];
        }

        $this->assertSame([12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1], $full);
        $this->assertSame($full, $paged);
    }

    // --- Distinct ---

    public function testDistinctCollapsesDuplicateStringValues(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product alpha', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product beta',  'brand' => 'Nike'],
            ['id' => 3, 'title' => 'product gamma', 'brand' => 'Adidas'],
            ['id' => 4, 'title' => 'product delta', 'brand' => 'Adidas'],
        ]);
        $result = $index->search('product', new SearchOptions(distinct: 'brand'));
        $this->assertCount(2, $result->getIds());
        // One doc per brand — IDs 1/2 are Nike, 3/4 are Adidas; both groups must be represented.
        $nikeIds   = array_filter($result->getIds(), fn(int $id): bool => in_array($id, [1, 2], true));
        $adidasIds = array_filter($result->getIds(), fn(int $id): bool => in_array($id, [3, 4], true));
        $this->assertCount(1, $nikeIds);
        $this->assertCount(1, $adidasIds);
    }

    public function testDistinctHitsReflectsDeduplicatedCount(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Adidas'],
            ['id' => 4, 'title' => 'product', 'brand' => 'Adidas'],
        ]);
        $result = $index->search('product', new SearchOptions(distinct: 'brand'));
        $this->assertSame(2, $result->totalHits);
    }

    public function testDistinctCountAllowsMultiplePerGroup(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 4, 'title' => 'product', 'brand' => 'Adidas'],
        ]);
        $result = $index->search('product', new SearchOptions(distinct: 'brand', distinctCount: 2));
        $this->assertSame(3, $result->totalHits);
        // 2 Nike (IDs 1,2,3) + 1 Adidas (ID 4) survive
        $ids    = $result->getIds();
        $this->assertCount(3, $ids);
        $nikes  = array_filter($ids, fn(int $id): bool => in_array($id, [1, 2, 3], true));
        $adidas = array_filter($ids, fn(int $id): bool => $id === 4);
        $this->assertCount(2, $nikes);
        $this->assertCount(1, $adidas);
    }

    public function testDistinctPreservesScoreOrderWithinGroup(): void
    {
        // Doc 1 has "widget" once; doc 2 has "widget widget" — doc 2 scores higher.
        // With distinct on brand (same group), only the top scorer survives.
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'widget',        'brand' => 'Acme'],
            ['id' => 2, 'title' => 'widget widget',  'brand' => 'Acme'],
        ]);
        $result = $index->search('widget', new SearchOptions(distinct: 'brand'));
        $this->assertSame([2], $result->getIds());
    }

    public function testDistinctNullPassesThrough(): void
    {
        // Docs without the distinct field value each appear independently.
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product'],
            ['id' => 2, 'title' => 'product'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Nike'],
        ]);
        $result = $index->search('product', new SearchOptions(distinct: 'brand'));
        // Both null-brand docs pass through; Nike collapses to 1 → 3 total
        $this->assertSame(3, $result->totalHits);
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
        $this->assertContains(3, $result->getIds());
    }

    public function testDistinctWithPagination(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product alpha',  'brand' => 'A'],
            ['id' => 2, 'title' => 'product beta',   'brand' => 'A'],
            ['id' => 3, 'title' => 'product gamma',  'brand' => 'B'],
            ['id' => 4, 'title' => 'product delta',  'brand' => 'B'],
            ['id' => 5, 'title' => 'product epsilon','brand' => 'C'],
        ]);
        // 3 distinct groups; page 1 (offset 0, limit 2) gets groups A and B
        $page1 = $index->search('product', new SearchOptions(limit: 2, offset: 0, distinct: 'brand'));
        $page2 = $index->search('product', new SearchOptions(limit: 2, offset: 2, distinct: 'brand'));
        $this->assertSame(3, $page1->totalHits);
        $this->assertSame(3, $page2->totalHits);
        $this->assertCount(2, $page1->getIds());
        $this->assertCount(1, $page2->getIds());
        // No overlap between pages
        $this->assertEmpty(array_intersect($page1->getIds(), $page2->getIds()));
    }

    public function testDistinctLimitZeroReportsAccurateHits(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Adidas'],
        ]);
        $result = $index->search('product', new SearchOptions(limit: 0, distinct: 'brand'));
        $this->assertSame([], $result->getIds());
        $this->assertSame(2, $result->totalHits);
    }

    public function testDistinctCountOneWithAllUniqueValuesMatchesNonDistinct(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['sku'], sortableFields: ['sku']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'sku' => 'A'],
            ['id' => 2, 'title' => 'product', 'sku' => 'B'],
            ['id' => 3, 'title' => 'product', 'sku' => 'C'],
        ]);
        $plain    = $index->search('product');
        $distinct = $index->search('product', new SearchOptions(distinct: 'sku'));
        $this->assertSame($plain->totalHits, $distinct->totalHits);
        $this->assertEqualsCanonicalizing($plain->getIds(), $distinct->getIds());
    }

    public function testDistinctOnBooleanSearch(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Adidas'],
        ]);
        $result = $index->searchBoolean('product', new SearchOptions(distinct: 'brand'));
        $this->assertSame(2, $result->totalHits);
        $this->assertCount(2, $result->getIds());
    }

    public function testDistinctBooleanWithPagination(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'A'],
            ['id' => 2, 'title' => 'product', 'brand' => 'A'],
            ['id' => 3, 'title' => 'product', 'brand' => 'B'],
            ['id' => 4, 'title' => 'product', 'brand' => 'C'],
        ]);
        $page1 = $index->searchBoolean('product', new SearchOptions(limit: 2, offset: 0, distinct: 'brand'));
        $page2 = $index->searchBoolean('product', new SearchOptions(limit: 2, offset: 2, distinct: 'brand'));
        $this->assertSame(3, $page1->totalHits);
        $this->assertSame(3, $page2->totalHits);
        $this->assertCount(2, $page1->getIds());
        $this->assertCount(1, $page2->getIds());
        $this->assertEmpty(array_intersect($page1->getIds(), $page2->getIds()));
    }

    public function testDistinctWithSort(): void
    {
        // sort: price:asc determines which doc wins per brand group, not BM25 score
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand', 'price'],
            sortableFields: ['brand', 'price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike',   'price' => 90],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike',   'price' => 50],
            ['id' => 3, 'title' => 'product', 'brand' => 'Adidas', 'price' => 70],
            ['id' => 4, 'title' => 'product', 'brand' => 'Adidas', 'price' => 30],
        ]);
        $result = $index->search('product', new SearchOptions(sort: ['price:asc'], distinct: 'brand'));
        // Cheapest Nike (id 2, price 50) and cheapest Adidas (id 4, price 30) survive
        // Sort order: Adidas $30 first, then Nike $50
        $this->assertSame([4, 2], $result->getIds());
    }

    public function testDistinctWithFacetFilter(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand', 'category'],
            sortableFields: ['brand', 'category'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike',   'category' => 'shoes'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike',   'category' => 'shirts'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Adidas', 'category' => 'shoes'],
            ['id' => 4, 'title' => 'product', 'brand' => 'Adidas', 'category' => 'shoes'],
        ]);
        $result = $index->search('product', new SearchOptions(filter: ['category' => 'shoes'], distinct: 'brand'));
        // Only shoe docs remain (IDs 1, 3, 4); one per brand → IDs 1 (Nike) and one of 3/4 (Adidas)
        $this->assertSame(2, $result->totalHits);
        $this->assertCount(2, $result->getIds());
        $nikeId   = array_filter($result->getIds(), fn(int $id): bool => $id === 1);
        $adidasId = array_filter($result->getIds(), fn(int $id): bool => in_array($id, [3, 4], true));
        $this->assertCount(1, $nikeId);
        $this->assertCount(1, $adidasId);
    }

    public function testDistinctWithFacetCounts(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand', 'category'],
            sortableFields: ['brand', 'category'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'brand' => 'Nike',   'category' => 'shoes'],
            ['id' => 2, 'title' => 'product', 'brand' => 'Nike',   'category' => 'shirts'],
            ['id' => 3, 'title' => 'product', 'brand' => 'Adidas', 'category' => 'shoes'],
        ]);
        // Facet counts are computed on the pre-distinct filtered result set (same as without distinct)
        $result = $index->search('product', new SearchOptions(facets: ['category'], distinct: 'brand'));
        $this->assertSame(2, $result->facetDistribution['category']['shoes']);
        $this->assertSame(1, $result->facetDistribution['category']['shirts']);
    }

    public function testDistinctOnNumericFacet(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['rating'],
            sortableFields: ['rating'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'rating' => 5],
            ['id' => 2, 'title' => 'product', 'rating' => 5],
            ['id' => 3, 'title' => 'product', 'rating' => 4],
        ]);
        $result = $index->search('product', new SearchOptions(distinct: 'rating'));
        $this->assertSame(2, $result->totalHits);
        // IDs 1/2 = rating 5 (one survives); ID 3 = rating 4 (survives)
        $rating5 = array_filter($result->getIds(), fn(int $id): bool => in_array($id, [1, 2], true));
        $rating4 = array_filter($result->getIds(), fn(int $id): bool => $id === 3);
        $this->assertCount(1, $rating5);
        $this->assertCount(1, $rating4);
    }

    public function testDistinctMultiValueFieldIsDeterministic(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['tag'], sortableFields: ['tag']));
        $index->insert([
            ['id' => 1, 'title' => 'product', 'tag' => ['b', 'a']],
            ['id' => 2, 'title' => 'product', 'tag' => ['a']],
            ['id' => 3, 'title' => 'product', 'tag' => ['b']],
        ]);
        // Doc 1 is represented by its smallest value 'a', so it collapses with doc 2, not doc 3.
        $result = $index->searchBoolean('product', new SearchOptions(distinct: 'tag'));
        $this->assertSame(2, $result->totalHits);
        $this->assertContains(3, $result->getIds());
    }

    // --- Warnings ---

    public function testWarningsEmptyByDefault(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['brand'],
            sortableFields: ['brand'],
        ));
        $index->insert([['id' => 1, 'title' => 'product', 'brand' => 'Nike']]);

        $result = $index->search('product', new SearchOptions(
            filter: ['brand' => 'Nike'],
            facets: ['brand'],
            sort: ['brand:asc'],
            distinct: 'brand',
        ));

        $this->assertSame([], $result->warnings);
        $this->assertSame([], $result->getWarnings());
        $this->assertSame([], $result->toArray()['warnings']);
    }

    // --- Approximate results ---

    /** @return list<array<string, mixed>> */
    private function sameWordDocs(int $n): array
    {
        $docs = [];
        for ($i = 1; $i <= $n; $i++) {
            $docs[] = ['id' => $i, 'title' => 'widget', 'color' => $i % 2 ? 'red' : 'blue'];
        }
        return $docs;
    }

    public function testSearchIsExhaustiveByDefault(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert($this->sameWordDocs(3));

        $result = $index->search('widget', new SearchOptions(facets: ['color']));

        $this->assertTrue($result->exhaustive);
        $this->assertTrue($result->isExhaustive());
        $this->assertSame([], $result->approximateFacets);
        $this->assertSame([], $result->warnings);
        $this->assertTrue($result->toArray()['exhaustive']);
        $this->assertSame([], $result->toArray()['approximateFacets']);
    }

    public function testSearchKeywordAboveMaxDocsIsNotExhaustive(): void
    {
        $index = new Index($this->dbPath, config: new Config(maxDocs: 2));
        $index->insert($this->sameWordDocs(3));

        $result = $index->search('widget');

        $this->assertFalse($result->exhaustive);
        $this->assertSame(2, $result->totalHits);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('Config::$maxDocs (2)', $result->warnings[0]);
    }

    public function testSearchKeywordExactlyAtMaxDocsIsExhaustive(): void
    {
        $index = new Index($this->dbPath, config: new Config(maxDocs: 3));
        $index->insert($this->sameWordDocs(3));

        $this->assertTrue($index->search('widget')->exhaustive);
        $this->assertTrue($index->searchBoolean('widget')->exhaustive);
    }

    public function testBooleanKeywordAboveMaxDocsIsNotExhaustive(): void
    {
        $index = new Index($this->dbPath, config: new Config(maxDocs: 2));
        $index->insert($this->sameWordDocs(3));

        $result = $index->searchBoolean('widget');

        $this->assertFalse($result->exhaustive);
        $this->assertSame(2, $result->totalHits);
        $this->assertCount(1, $result->warnings);
    }

    public function testCappedPrefixExpansionIsNotExhaustive(): void
    {
        $index = new Index($this->dbPath, config: new Config(fuzzyMaxExpansions: 2));
        $index->insert([
            ['id' => 1, 'title' => 'car'],
            ['id' => 2, 'title' => 'cart'],
            ['id' => 3, 'title' => 'carbon'],
        ]);

        $capped = $index->search('ca');
        $cached = $index->search('ca');
        $bool   = $index->searchBoolean('ca');

        $this->assertFalse($capped->exhaustive);
        $ids = $capped->getIds();
        sort($ids);
        $this->assertSame([1, 2], $ids, 'only the two shortest completions are searched');
        $this->assertFalse($cached->exhaustive, 'the flag must survive a wordlist cache hit');
        $this->assertFalse($bool->exhaustive);
        $this->assertStringContainsString('Config::$fuzzyMaxExpansions (2)', $capped->warnings[0]);
    }

    public function testPrefixExpansionExactlyAtCapIsExhaustive(): void
    {
        $index = new Index($this->dbPath, config: new Config(fuzzyMaxExpansions: 2));
        $index->insert([
            ['id' => 1, 'title' => 'car'],
            ['id' => 2, 'title' => 'cart'],
        ]);

        $this->assertTrue($index->search('ca')->exhaustive);
        // Without asYouType the last keyword is an exact lookup; no expansion to cap.
        $this->assertTrue($index->search('ca', new SearchOptions(asYouType: false))->exhaustive);
    }

    public function testSearchFacetCountsAboveCapAreReportedApproximate(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(filterableFields: ['color'], sortableFields: ['color']),
            config: new Config(maxFacetCountDocs: 2),
        );
        $index->insert($this->sameWordDocs(3));

        $plain       = $index->search('widget', new SearchOptions(facets: ['color']));
        $disjunctive = $index->searchBoolean('widget', new SearchOptions(
            filter: ['color' => 'red'],
            facets: ['color'],
        ));

        $this->assertTrue($plain->exhaustive);
        $this->assertSame(['color'], $plain->approximateFacets);
        $this->assertSame(['color'], $plain->getApproximateFacets());
        $this->assertSame(['color'], $disjunctive->approximateFacets);
        $this->assertStringContainsString("Facet counts for 'color' cover only 2", $plain->warnings[0]);
    }

    public function testSearchFacetCountsUnderCapAreNotApproximate(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(filterableFields: ['color'], sortableFields: ['color']),
            config: new Config(maxFacetCountDocs: 3),
        );
        $index->insert($this->sameWordDocs(3));

        $result = $index->search('widget', new SearchOptions(filter: ['color' => 'red'], facets: ['color']));

        $this->assertSame([], $result->approximateFacets);
        $this->assertSame([], $result->warnings);
    }

    public function testBrowseIsExhaustiveAndReportsOnlyCappedFilteredFacets(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(
                filterableFields: ['price', 'color', 'brand'],
                sortableFields: ['price', 'color', 'brand'],
            ),
            config: new Config(maxDocs: 1, maxFacetCountDocs: 3),
        );
        $index->insert($this->browseCatalog());

        // color is disjunctive with no other filter → whole-index count, exact; brand uses the 8 blue docs.
        $result = $index->search('', new SearchOptions(filter: ['color' => 'blue'], facets: ['brand', 'color']));
        $plain  = $index->search('', new SearchOptions(sort: ['price:asc'], facets: ['brand']));

        $this->assertTrue($result->exhaustive);
        $this->assertSame(['brand'], $result->approximateFacets);
        $this->assertCount(1, $result->warnings);
        $this->assertTrue($plain->exhaustive);
        $this->assertSame([], $plain->approximateFacets);
    }

    public function testFacetSearchReportsCappedQueryCandidates(): void
    {
        $index = new Index(
            $this->dbPath,
            schema: new SchemaConfig(filterableFields: ['color'], sortableFields: ['color']),
            config: new Config(maxFacetCountDocs: 2),
        );
        $index->insert($this->sameWordDocs(3));

        $capped  = $index->facetSearch(new FacetSearchQuery(facetName: 'color', query: 'widget'));
        $noQuery = $index->facetSearch(new FacetSearchQuery(facetName: 'color'));

        $this->assertFalse($capped->exhaustive);
        $this->assertFalse($capped->isExhaustive());
        $this->assertSame(2, array_sum(array_column($capped->facetHits, 'count')));
        $this->assertStringContainsString('Config::$maxFacetCountDocs (2)', $capped->warnings[0]);
        $this->assertTrue($noQuery->exhaustive);
        $this->assertSame(3, array_sum(array_column($noQuery->facetHits, 'count')));
        $this->assertFalse($capped->toArray()['exhaustive']);
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
        $this->assertSame([1, 2], $result->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertContains(2, $result->getIds());
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
        $this->assertContains(1, $result->getIds());
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
        $this->assertSame([1, 2], $result->getIds());
    }

    public function testFieldBoostUpsertUpdatesFieldHits(): void
    {
        // Upsert should replace old field_hits rows, not accumulate them.
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 3.0, 'body' => 1.0]));
        $index->insert([['id' => 1, 'title' => 'widget', 'body' => 'generic content']]);
        // Now replace: "widget" moved to body only; title no longer has it.
        $index->upsert([['id' => 1, 'title' => 'product overview', 'body' => 'widget listed here']]);

        $result = $index->search('widget');
        $this->assertContains(1, $result->getIds());
    }

    public function testFieldBoostDeleteClearsFieldHits(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'target document', 'body' => 'content']]);
        $index->delete(1);
        // After delete, index should be empty.
        $result = $index->search('target');
        $this->assertSame([], $result->getIds());
    }

    public function testFieldBoostClearClearsFieldHits(): void
    {
        $index = new Index($this->dbPath, config: new Config(fieldBoosts: ['title' => 2.0]));
        $index->insert([['id' => 1, 'title' => 'alpha', 'body' => 'content']]);
        $index->clear();
        $this->assertSame(0, $index->count());
        $result = $index->search('alpha');
        $this->assertSame([], $result->getIds());
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
        $this->assertSame(1, $result->getIds()[0], 'Title match should rank first with high title boost');
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
        $index   = new Index($this->dbPath);
        $skipped = $index->setSynonyms(oneWay: ['mobile phone' => ['smartphone']]);
        $this->assertSame([], $index->getSynonyms());
        $this->assertSame(['mobile phone'], $skipped);
    }

    public function testSetSynonymsSkipsMultiWordTargets(): void
    {
        $index   = new Index($this->dbPath);
        $skipped = $index->setSynonyms(oneWay: ['phone' => ['mobile device', 'smartphone']]);
        $synonyms = $index->getSynonyms();
        $this->assertArrayHasKey('phone', $synonyms);
        $this->assertNotContains('mobile device', $synonyms['phone']);
        $this->assertContains('smartphone', $synonyms['phone']);
        $this->assertSame(['mobile device'], $skipped);
    }

    public function testSetSynonymsReturnsEmptyWhenNoTermsSkipped(): void
    {
        $index   = new Index($this->dbPath);
        $skipped = $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $this->assertSame([], $skipped);
    }

    public function testSetSynonymsSkipsMultiWordTermsInEquivalenceGroup(): void
    {
        $index   = new Index($this->dbPath);
        $skipped = $index->setSynonyms(equivalences: [['car', 'automobile', 'motor vehicle']]);
        $synonyms = $index->getSynonyms();
        $this->assertArrayHasKey('car', $synonyms);
        $this->assertContains('automobile', $synonyms['car']);
        $this->assertSame(['motor vehicle'], $skipped);
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
        $this->assertContains(1, $index->search('car')->getIds());
    }

    public function testEquivalenceSynonymExpandsSearchForOtherTerm(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'car show'],
            ['id' => 2, 'title' => 'bike race'],
        ]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $this->assertContains(1, $index->search('automobile')->getIds());
    }

    public function testOneWaySynonymExpandsSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'smartphone review'],
            ['id' => 2, 'title' => 'laptop review'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone']]);
        $this->assertContains(1, $index->search('phone')->getIds());
        $this->assertNotContains(2, $index->search('phone')->getIds());
    }

    public function testOneWaySynonymDoesNotExpandReverse(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'phone review'],
            ['id' => 2, 'title' => 'laptop review'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone']]);
        $this->assertNotContains(1, $index->search('smartphone')->getIds());
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
        $ids = $index->search('phone')->getIds();
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
        $ids = $index->search('car', new SearchOptions(asYouType: false))->getIds();
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
        $this->assertContains(1, $index->searchBoolean('car')->getIds());
    }

    public function testOneWaySynonymInBooleanDoesNotExpandReverse(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'phone review'],
        ]);
        $index->setSynonyms(oneWay: ['phone' => ['smartphone']]);
        $this->assertNotContains(1, $index->searchBoolean('smartphone')->getIds());
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
        $this->assertContains(1, $result->getIds());
        $this->assertNotContains(2, $result->getIds());
    }

    // --- Synonyms: persistence ---

    public function testSynonymsPersistAfterReopenAndSearch(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'automobile']]);
        $index->setSynonyms(equivalences: [['car', 'automobile']]);
        $index->close();

        $index = new Index($this->dbPath);
        $this->assertContains(1, $index->search('car')->getIds());
    }

    // --- Synonyms: stemmer normalization ---

    public function testSetSynonymsNormalizesWithStemmer(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(language: 'en'));
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        // 'cars' stems to 'car'; synonym target 'sedan' is unchanged.
        // Searching 'car' should find the sedan doc via synonym.
        $index->setSynonyms(oneWay: ['cars' => ['sedan']]);
        $this->assertContains(1, $index->search('car', new SearchOptions(asYouType: false))->getIds());
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

        $this->assertContains(1, $rebuilt->search('car')->getIds());
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
        $this->assertNotContains(1, $rebuilt->search('car')->getIds());
    }

    public function testRebuildWithNoExistingSynonymsIsOk(): void
    {
        new Index($this->dbPath)->close();

        $rebuilt = Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([['id' => 1, 'title' => 'sedan']]);
        });

        $this->assertSame([], $rebuilt->getSynonyms());
        $this->assertContains(1, $rebuilt->search('sedan')->getIds());
    }

    // --- Write-lock contention (BEGIN IMMEDIATE) ---

    public function testWriteFailsFastWhenLockHeldAndBusyTimeoutZero(): void
    {
        $index = new Index($this->dbPath, config: new Config(busyTimeoutMs: 0));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        // A second connection takes the write lock and holds it.
        $blocker = new \PDO('sqlite:' . $this->dbPath);
        $blocker->exec('PRAGMA busy_timeout = 0');
        $blocker->exec('BEGIN IMMEDIATE');

        try {
            $this->expectException(\PDOException::class);
            $index->insert([['id' => 2, 'title' => 'coupe']]);
        } finally {
            $blocker->exec('ROLLBACK');
        }
    }

    public function testWriteSucceedsAfterLockReleased(): void
    {
        $index = new Index($this->dbPath, config: new Config(busyTimeoutMs: 0));
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $blocker = new \PDO('sqlite:' . $this->dbPath);
        $blocker->exec('PRAGMA busy_timeout = 0');
        $blocker->exec('BEGIN IMMEDIATE');
        $caught = null;
        try {
            $index->insert([['id' => 2, 'title' => 'coupe']]);
        } catch (\Throwable $caught) {
        }
        $this->assertInstanceOf(\PDOException::class, $caught);
        $blocker->exec('ROLLBACK');

        $index->insert([['id' => 2, 'title' => 'coupe']]);
        $this->assertContains(2, $index->search('coupe')->getIds());
    }

    // --- Cross-connection cache invalidation (PRAGMA data_version) ---

    public function testReaderSeesExternalInsertAfterWarmCaches(): void
    {
        $writer = new Index($this->dbPath);
        $writer->insert([['id' => 1, 'title' => 'sedan']]);

        $reader = new Index($this->dbPath, readonly: true);
        $this->assertSame(1, $reader->count());
        $this->assertSame(1, $reader->inspectQuery('sedan')->tokens[0]->numDocs);

        $writer->insert([['id' => 2, 'title' => 'sedan coupe']]);

        $this->assertSame(2, $reader->count());
        $this->assertSame(2, $reader->inspectQuery('sedan')->tokens[0]->numDocs);
        $this->assertSame(2, $reader->search('sedan')->getTotalHits());
    }

    public function testReaderSeesExternalSynonymChange(): void
    {
        $writer = new Index($this->dbPath);
        $writer->insert([['id' => 1, 'title' => 'automobile']]);

        $reader = new Index($this->dbPath, readonly: true);
        $this->assertSame([], $reader->getSynonyms());
        $this->assertNotContains(1, $reader->search('car')->getIds());

        $writer->setSynonyms(equivalences: [['car', 'automobile']]);

        $this->assertArrayHasKey('car', $reader->getSynonyms());
        $this->assertContains(1, $reader->search('car')->getIds());
    }

    public function testWriteAfterExternalTermPruneReindexesTerm(): void
    {
        // B caches the term ID for 'sedan'; A then deletes the only document using
        // it, pruning the wordlist row. B's next insert must not trust the dead
        // cached ID, or the term would silently vanish from the index.
        $b = new Index($this->dbPath);
        $b->insert([['id' => 1, 'title' => 'sedan']]);

        $a = new Index($this->dbPath);
        $a->delete(1);

        $b->insert([['id' => 2, 'title' => 'sedan']]);
        $this->assertContains(2, $b->search('sedan')->getIds());
        $this->assertContains(2, $a->search('sedan')->getIds());
    }

    // --- attributesToRetrieve ---

    /** @return Index */
    private function retrievalIndex(): Index
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'electric sedan', 'body' => 'a quiet electric car', 'year' => 2024],
            ['id' => 2, 'title' => 'diesel coupe', 'body' => 'a loud diesel car', 'year' => 2019],
        ]);
        return $index;
    }

    /** @return array<string, mixed> */
    private function fullDocOne(): array
    {
        return ['id' => 1, 'title' => 'electric sedan', 'body' => 'a quiet electric car', 'year' => 2024];
    }

    public function testAttributesToRetrieveNullReturnsWholeDocument(): void
    {
        $hit = $this->retrievalIndex()->search('sedan')->getHit(0);
        $this->assertSame($this->fullDocOne(), $hit);
    }

    public function testAttributesToRetrieveEmptyReturnsIdStubs(): void
    {
        $result = $this->retrievalIndex()->search('car', new SearchOptions(attributesToRetrieve: []));

        $this->assertSame(2, $result->getTotalHits());
        foreach ($result as $hit) {
            $this->assertSame(['id'], array_keys($hit));
        }
        $this->assertSame([1, 2], $result->getIds());
    }

    public function testAttributesToRetrieveFieldListKeepsOnlyThoseFields(): void
    {
        $hit = $this->retrievalIndex()
            ->search('sedan', new SearchOptions(attributesToRetrieve: ['title']))
            ->getHit(0);

        $this->assertSame(['id' => 1, 'title' => 'electric sedan'], $hit);
    }

    public function testAttributesToRetrieveWildcardReturnsWholeDocument(): void
    {
        $hit = $this->retrievalIndex()
            ->search('sedan', new SearchOptions(attributesToRetrieve: ['*']))
            ->getHit(0);

        $this->assertSame($this->fullDocOne(), $hit);
    }

    public function testAttributesToRetrieveKeepsFormattedAndHighlightsFullText(): void
    {
        // 'body' is highlighted but not retrieved: the highlight must still be computed
        // from the full stored value, and _formatted must survive the key filtering.
        $hit = $this->retrievalIndex()->search('quiet', new SearchOptions(
            attributesToHighlight: ['body'],
            attributesToRetrieve: ['title'],
        ))->getHit(0);

        $this->assertSame(['id', 'title', '_formatted'], array_keys($hit));
        $this->assertIsArray($hit['_formatted']);
        $this->assertSame('a <mark>quiet</mark> electric car', $hit['_formatted']['body']);
        $this->assertArrayNotHasKey('body', $hit);
    }

    public function testAttributesToRetrieveEmptySkipsStoreLookup(): void
    {
        $index = $this->retrievalIndex();
        $index->close();

        // A readonly reopen proves nothing is written; the point is that an empty
        // retrieve list produces stubs even though the store is enabled and populated.
        $read   = new Index($this->dbPath, readonly: true);
        $result = $read->search('sedan', new SearchOptions(attributesToRetrieve: []));

        $this->assertSame([['id' => 1]], $result->getHits());
        $this->assertNotNull($read->get(1));
    }

    public function testAttributesToRetrieveAppliesToBooleanSearch(): void
    {
        $hit = $this->retrievalIndex()
            ->searchBoolean('sedan', new SearchOptions(attributesToRetrieve: ['year']))
            ->getHit(0);

        $this->assertSame(['id' => 1, 'year' => 2024], $hit);
    }

    public function testAttributesToRetrieveAppliesToBrowse(): void
    {
        $result = $this->retrievalIndex()->search('', new SearchOptions(attributesToRetrieve: ['title']));

        $this->assertSame(['id' => 2, 'title' => 'diesel coupe'], $result->getHit(0));
    }

    // --- schema version / covering facet index ---

    /**
     * @param  list<string> $params
     * @return list<string>
     */
    private function indexPlanFor(string $path, string $sql, array $params): array
    {
        $pdo  = new \PDO('sqlite:' . $path);
        $stmt = $pdo->prepare('EXPLAIN QUERY PLAN ' . $sql);
        $stmt->execute($params);
        /** @var list<array{detail: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn(array $r): string => $r['detail'], $rows);
    }

    /** Read the stored DDL for a named index, or '' when it does not exist. */
    private function indexDdl(string $path, string $indexName): string
    {
        $pdo  = new \PDO('sqlite:' . $path);
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND name=?");
        $stmt->execute([$indexName]);
        $sql = $stmt->fetchColumn();

        return is_string($sql) ? $sql : '';
    }

    private function facetIndex(): Index
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color', 'size'],
            sortableFields: ['color', 'size'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'red shirt',  'color' => 'red',  'size' => 42],
            ['id' => 2, 'title' => 'blue shirt', 'color' => 'blue', 'size' => 44],
            ['id' => 3, 'title' => 'red pants',  'color' => 'red',  'size' => 42],
        ]);
        return $index;
    }

    private const string HTML_BODY = '<p>fast</p><p>delivery</p> <script>trackingpixel()</script> '
        . '<p>Fit &amp; Flare caf&eacute;</p>';

    public function testStripHtmlIndexesVisibleText(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(stripHtml: true));
        $index->insert([['id' => 1, 'body' => self::HTML_BODY]]);

        $this->assertSame([1], $index->search('fast', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([1], $index->search('delivery', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([1], $index->search('café', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([], $index->search('fastdelivery', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([], $index->search('trackingpixel', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([], $index->searchBoolean('amp', new SearchOptions(asYouType: false))->getIds());
        // The stored document keeps the raw HTML.
        $this->assertSame(self::HTML_BODY, $index->get(1)['body'] ?? null);
    }

    /** Set the schema_version key of a closed index file; null deletes it (files before 1.5.0). */
    private function setSchemaVersion(string $path, ?int $revision): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $revision === null
            ? $pdo->exec("DELETE FROM info WHERE key = 'schema_version'")
            : $pdo->exec("UPDATE info SET value = '{$revision}' WHERE key = 'schema_version'");
    }

    /** @return iterable<string, array{0: int|null}> */
    public static function oneXRevisions(): iterable
    {
        yield 'no version key (before 1.5.0)' => [null];
        yield 'revision 1' => [1];
        yield 'revision 2' => [2];
        yield 'revision 3 (1.6.0 to 1.7.x)' => [3];
    }

    #[DataProvider('oneXRevisions')]
    public function testOpeningA1xIndexThrowsWithRecreateHint(?int $revision): void
    {
        (new Index($this->dbPath))->close();
        $this->setSchemaVersion($this->dbPath, $revision);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('written by Fuzor 1.x');
        $this->expectExceptionMessage('force: true');
        new Index($this->dbPath);
    }

    public function testOpeningA1xIndexReadonlyThrows(): void
    {
        (new Index($this->dbPath))->close();
        $this->setSchemaVersion($this->dbPath, 3);

        $this->expectException(QueryException::class);
        new Index($this->dbPath, readonly: true);
    }

    public function testOpeningAnIndexFromANewerVersionThrows(): void
    {
        (new Index($this->dbPath))->close();
        $this->setSchemaVersion($this->dbPath, Index::CURRENT_SCHEMA_VERSION + 1);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('newer version of Fuzor');
        new Index($this->dbPath);
    }

    public function testForceRecreatesA1xIndex(): void
    {
        (new Index($this->dbPath))->close();
        $this->setSchemaVersion($this->dbPath, 3);

        $index = new Index($this->dbPath, force: true, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['color'],
        ));
        $index->insert([['id' => 1, 'title' => 'red shirt', 'color' => 'red']]);

        $this->assertSame(Index::CURRENT_SCHEMA_VERSION, $index->schemaVersion);
        $this->assertSame([1], $index->search('shirt', new SearchOptions(filter: ['color' => 'red']))->getIds());
    }

    public function testDocumentStoreFlagIsWrittenEitherWay(): void
    {
        (new Index($this->dbPath, schema: new SchemaConfig(store: false)))->close();
        $stmt = new \PDO('sqlite:' . $this->dbPath)->query("SELECT value FROM info WHERE key = 'has_document_store'");

        $this->assertNotFalse($stmt);
        $this->assertSame('0', $stmt->fetchColumn());
        $this->assertFalse(new Index($this->dbPath)->documentStoreEnabled);
    }

    public function testNewIndexReportsCurrentSchemaVersion(): void
    {
        $index = new Index($this->dbPath);
        $this->assertSame(Index::CURRENT_SCHEMA_VERSION, $index->schemaVersion);
        $index->close();

        $this->assertSame(Index::CURRENT_SCHEMA_VERSION, new Index($this->dbPath)->schemaVersion);
    }

    public function testCurrentSchemaUsesCoveringIndexForFacetJoin(): void
    {
        $this->facetIndex()->close();

        $plan = $this->indexPlanFor(
            $this->dbPath,
            'SELECT fv.key_id, fv.value, fv.num_value
             FROM json_each(?) je
             CROSS JOIN facet_values fv ON fv.doc_id = je.value
             WHERE fv.key_id IN (SELECT value FROM json_each(?))',
            [json_encode([1, 2, 3], JSON_THROW_ON_ERROR), json_encode([1], JSON_THROW_ON_ERROR)],
        );

        $this->assertNotEmpty(
            array_filter($plan, static fn(string $d): bool => str_contains($d, 'COVERING INDEX facet_doc_id_index')),
            "Expected a covering-index plan, got:\n" . implode("\n", $plan),
        );
    }

    public function testBulkLoadPreservesCurrentSchemaIndexShape(): void
    {
        $index = $this->facetIndex();
        $docs  = [];
        for ($i = 10; $i < 1_015; $i++) {
            $docs[] = ['id' => $i, 'title' => "shirt {$i}", 'color' => 'green', 'size' => 40];
        }
        $index->insert($docs);
        $index->close();

        $this->assertStringContainsString('num_value', $this->indexDdl($this->dbPath, 'facet_doc_id_index'));
    }

    public function testSnapshotPropagatesSourceSchemaVersion(): void
    {
        $snapPath = sys_get_temp_dir() . '/fuzor_test_schemasnap_' . uniqid() . '.db';
        $index    = $this->facetIndex();

        try {
            $index->snapshotTo($snapPath);
            $index->close();
            $this->assertSame(Index::CURRENT_SCHEMA_VERSION, new Index($snapPath)->schemaVersion);
        } finally {
            self::removeIndexFiles($snapPath);
        }
    }

    // --- completing keyword groups truncated at maxDocs ---

    /** Five docs where "shirt" is frequent, one with both words, two with "casual" only (doc 7 twice). */
    private function truncationIndex(int $maxDocs): Index
    {
        $index = new Index($this->dbPath, force: true, config: new Config(maxDocs: $maxDocs));
        $index->insert([
            ['id' => 1, 'title' => 'shirt shirt shirt'],
            ['id' => 2, 'title' => 'shirt shirt shirt'],
            ['id' => 3, 'title' => 'shirt shirt shirt'],
            ['id' => 4, 'title' => 'shirt shirt'],
            ['id' => 5, 'title' => 'shirt shirt'],
            ['id' => 6, 'title' => 'casual shirt'],
            ['id' => 7, 'title' => 'casual casual'],
            ['id' => 8, 'title' => 'casual jacket'],
        ]);
        return $index;
    }

    public function testDocumentMatchingAllWordsRanksFirstWhenACommonWordIsTruncated(): void
    {
        // "shirt" (6 docs) is cut to its 3 best rows; doc 6 is found through "casual" and must
        // still count as matching both words.
        $result = $this->truncationIndex(3)->search('casual shirt', new SearchOptions(asYouType: false));

        $this->assertSame(6, $result->getIds()[0]);
        $this->assertFalse($result->exhaustive);
    }

    public function testTruncatedRankingMatchesTheFullRankingForTheDocumentsItFinds(): void
    {
        $options = new SearchOptions(asYouType: false);
        $full    = $this->truncationIndex(100)->search('shirt casual', $options)->getIds();
        $capped  = $this->truncationIndex(3)->search('shirt casual', $options)->getIds();

        // "shirt" comes first, so every result contains it (MatchingStrategy::Last). Docs 4 and 5
        // are only reachable through its truncated rows; everything else must keep the order (so
        // the scores) of the untruncated search.
        $this->assertSame(array_values(array_intersect($full, $capped)), $capped);
        $this->assertSame([4, 5], array_values(array_diff($full, $capped)));
    }

    public function testTruncatedPrefixGroupIsCompletedToo(): void
    {
        $index = new Index($this->dbPath, config: new Config(maxDocs: 2));
        $index->insert([
            ['id' => 1, 'title' => 'shiny shiny shiny'],
            ['id' => 2, 'title' => 'shiny shiny shiny'],
            ['id' => 3, 'title' => 'shin shin'],
            ['id' => 4, 'title' => 'casual shirts'],
            ['id' => 5, 'title' => 'casual casual'],
        ]);

        // The last word "shi" expands to shiny, shin, shirts (several term IDs, over maxDocs).
        $result = $index->search('casual shi');

        $this->assertSame(4, $result->getIds()[0]);
    }

    /** 300 documents containing both words at varying distances, with a 'grade' of 1–4 (big ties). */
    private function proximityCorpus(): Index
    {
        mt_srand(7);
        $fill  = ['alpha', 'beta', 'gamma', 'delta', 'omega', 'sigma', 'kappa'];
        $docs  = [];
        for ($id = 1; $id <= 300; $id++) {
            $words = [];
            for ($i = 0, $n = mt_rand(4, 30); $i < $n; $i++) {
                $words[] = $fill[mt_rand(0, count($fill) - 1)];
            }
            for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
                array_splice($words, mt_rand(0, count($words)), 0, ['blue']);
            }
            for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
                array_splice($words, mt_rand(0, count($words)), 0, ['jeans']);
            }
            $docs[] = ['id' => $id, 'title' => implode(' ', $words), 'grade' => mt_rand(1, 4)];
        }
        $index = new Index($this->dbPath, schema: new SchemaConfig(sortableFields: ['grade']));
        $index->insert($docs);
        return $index;
    }

    public function testProximityEarlyStopGivesTheSamePagesAsRerankingEverything(): void
    {
        $index   = $this->proximityCorpus();
        $options = fn(int $offset, int $limit) => new SearchOptions(asYouType: false, offset: $offset, limit: $limit);

        $all = $index->search('blue jeans', $options(0, 1000))->getIds();
        $this->assertCount(300, $all);
        foreach ([[0, 1], [0, 10], [0, 20], [5, 20], [40, 10], [100, 50], [290, 20]] as [$offset, $limit]) {
            $this->assertSame(
                array_slice($all, $offset, $limit),
                $index->search('blue jeans', $options($offset, $limit))->getIds(),
                "offset {$offset}, limit {$limit}",
            );
        }
    }

    public function testSortedPagesRerankTiesLikeRerankingEverything(): void
    {
        // A page computed with the tie-only rerank must equal the same slice of a page that
        // covers every document (every tie group reranked).
        $index   = $this->proximityCorpus();
        $options = fn(int $offset, int $limit) => new SearchOptions(
            asYouType: false,
            sort: ['grade:desc'],
            offset: $offset,
            limit: $limit,
        );

        $all = $index->search('blue jeans', $options(0, 1000))->getIds();
        $this->assertCount(300, $all);
        foreach ([[0, 1], [0, 20], [7, 20], [60, 30], [150, 75], [295, 10]] as [$offset, $limit]) {
            $this->assertSame(
                array_slice($all, $offset, $limit),
                $index->search('blue jeans', $options($offset, $limit))->getIds(),
                "offset {$offset}, limit {$limit}",
            );
        }
    }

    // --- facet value order and facetSearch() matching ---

    private function facetValuesIndex(): Index
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['brand', 'size']));
        $index->insert([
            ['id' => 1, 'title' => 'dress', 'brand' => 'Rosa Clará', 'size' => 10],
            ['id' => 2, 'title' => 'dress', 'brand' => 'rosa-clara', 'size' => 9],
            ['id' => 3, 'title' => 'dress', 'brand' => 'Pronovias', 'size' => 9],
            ['id' => 4, 'title' => 'dress', 'brand' => 'Pronovias', 'size' => 12],
            ['id' => 5, 'title' => 'dress', 'brand' => 'Élan', 'size' => 36],
            ['id' => 6, 'title' => 'dress', 'brand' => 'atelier_aimée', 'size' => 36],
            ['id' => 7, 'title' => 'dress', 'brand' => 'Pronovias', 'size' => 36],
        ]);
        return $index;
    }

    /** @return list<string> */
    private function facetValues(Index $index, string $facetQuery, FacetOrder $order = FacetOrder::Count): array
    {
        $hits = $index->facetSearch(new FacetSearchQuery(
            facetName: 'brand',
            facetQuery: $facetQuery,
            sortFacetValuesBy: $order,
        ))->facetHits;
        return array_column($hits, 'value');
    }

    public function testFacetSearchMatchesTheStartOfAnyWordIgnoringCaseAndAccents(): void
    {
        $index = $this->facetValuesIndex();

        $this->assertEqualsCanonicalizing(['Rosa Clará', 'rosa-clara'], $this->facetValues($index, 'CLARA'));
        $this->assertEqualsCanonicalizing(['Rosa Clará', 'rosa-clara'], $this->facetValues($index, 'rosa cl'));
        $this->assertSame(['Élan'], $this->facetValues($index, 'ela'));
        $this->assertSame(['atelier_aimée'], $this->facetValues($index, 'Aimee'));
        // Only word starts: "ova" is inside "Pronovias".
        $this->assertSame([], $this->facetValues($index, 'ova'));
    }

    public function testFacetMatchKeyAsciiFastPathAgreesWithTheGeneralPath(): void
    {
        $key = new \ReflectionMethod(Index::class, 'facetMatchKey');
        foreach (['Rosa-Clara', ' A  B_c/D ', "tab\tsep", 'UPPER', '', 'x--y__z//w', 'Ünïcode ÉLAN'] as $text) {
            $general = trim(preg_replace('~[\s\-_/]+~u', ' ', Tokenizer::sortKey($text)) ?? '');
            $this->assertSame($general, $key->invoke(null, $text), $text);
        }
    }

    public function testFacetSearchTreatsLikeWildcardsLiterally(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(filterableFields: ['tag']));
        $index->insert([
            ['id' => 1, 'title' => 'x', 'tag' => '50% off'],
            ['id' => 2, 'title' => 'x', 'tag' => '500 club'],
            ['id' => 3, 'title' => 'x', 'tag' => 'a%b'],
            ['id' => 4, 'title' => 'x', 'tag' => 'axb'],
        ]);
        $values = fn(string $q) => array_column(
            $index->facetSearch(new FacetSearchQuery(facetName: 'tag', facetQuery: $q))->facetHits,
            'value',
        );

        $this->assertSame(['50% off'], $values('50%'));
        $this->assertSame(['a%b'], $values('a%'));
        $this->assertSame([], $values('a_b'));
    }

    public function testFacetSearchOrder(): void
    {
        $index = $this->facetValuesIndex();

        $this->assertSame(
            ['Pronovias', 'Rosa Clará', 'atelier_aimée', 'rosa-clara', 'Élan'],
            $this->facetValues($index, ''),
        );
        $this->assertSame(
            ['atelier_aimée', 'Pronovias', 'Rosa Clará', 'rosa-clara', 'Élan'],
            $this->facetValues($index, '', FacetOrder::Alpha),
        );
        $this->assertSame(['Rosa Clará', 'rosa-clara'], $this->facetValues($index, 'ros', FacetOrder::Alpha));
    }

    /** @param array<string, FacetOrder> $sortBy */
    private function facetOrderOptions(array $sortBy): SearchOptions
    {
        return new SearchOptions(facets: ['brand', 'size'], sortFacetValuesBy: $sortBy);
    }

    public function testFacetDistributionOrder(): void
    {
        $index = $this->facetValuesIndex();

        $count = $index->search('dress', $this->facetOrderOptions([]))->facetDistribution;
        $this->assertSame([36 => 3, 9 => 2, 10 => 1, 12 => 1], $count['size']);
        $this->assertSame('Pronovias', array_key_first($count['brand']));

        $alpha = $index->search('dress', $this->facetOrderOptions(['size' => FacetOrder::Alpha]))->facetDistribution;
        $this->assertSame([9 => 2, 10 => 1, 12 => 1, 36 => 3], $alpha['size']);
        $this->assertSame('Pronovias', array_key_first($alpha['brand']));

        $all = $index->search('', $this->facetOrderOptions(['*' => FacetOrder::Alpha, 'size' => FacetOrder::Count]))
            ->facetDistribution;
        $this->assertSame(
            ['atelier_aimée', 'Pronovias', 'Rosa Clará', 'rosa-clara', 'Élan'],
            array_keys($all['brand']),
        );
        $this->assertSame(36, array_key_first($all['size']));
    }

    public function testAlphaOrderIsAppliedBeforeTheValueCap(): void
    {
        $index = new Index($this->dbPath, config: new Config(maxValuesPerFacet: 2), schema: new SchemaConfig(
            filterableFields: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'x', 'brand' => 'Zeta'],
            ['id' => 2, 'title' => 'x', 'brand' => 'Zeta'],
            ['id' => 3, 'title' => 'x', 'brand' => 'Beta'],
            ['id' => 4, 'title' => 'x', 'brand' => 'alpha'],
        ]);

        $result = $index->search('x', new SearchOptions(
            facets: ['brand'],
            sortFacetValuesBy: ['brand' => FacetOrder::Alpha],
        ));

        $this->assertSame(['alpha' => 1, 'Beta' => 1], $result->facetDistribution['brand']);
    }

    // --- facet_counts ---

    /** facet_counts and a fresh GROUP BY over facet_values, both as key_id|value => [count, num_count, num]. */
    private function assertFacetCountsMatchFacetValues(string $when): void
    {
        $pdo   = new \PDO('sqlite:' . $this->dbPath);
        $table = static function (\PDO $pdo, string $sql): array {
            $stmt = $pdo->query($sql);
            \assert($stmt !== false);
            $out = [];
            /** @var list<array{0: int, 1: string, 2: int, 3: int, 4: float|null}> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            foreach ($rows as [$key, $value, $count, $numCount, $num]) {
                $out["{$key}|{$value}"] = [(int) $count, (int) $numCount, $num === null ? null : (float) $num];
            }
            ksort($out);
            return $out;
        };
        $this->assertSame(
            $table($pdo, 'SELECT key_id, value, COUNT(*), COUNT(num_value), MIN(num_value)
                            FROM facet_values GROUP BY key_id, value'),
            $table($pdo, 'SELECT key_id, value, count, num_count, num_value FROM facet_counts'),
            $when,
        );
    }

    public function testFacetCountsStayExactThroughEveryWritePath(): void
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['tags', 'size'],
            sortableFields: ['price'],
        ));
        $index->insert([['id' => 1, 'title' => 'a', 'tags' => ['x', 'y', 'x'], 'size' => 10, 'price' => 5]]);
        $this->assertFacetCountsMatchFacetValues('single insert');
        $index->insert([
            ['id' => 2, 'title' => 'b', 'tags' => ['y', 'z'], 'size' => '10', 'price' => 5.5],
            ['id' => 3, 'title' => 'c', 'tags' => 'z', 'size' => [10, 12], 'price' => 7],
            ['id' => 4, 'title' => 'd', 'tags' => ['x'], 'size' => 9],
        ]);
        $this->assertFacetCountsMatchFacetValues('bulk insert');
        $index->update([['id' => 1, 'title' => 'a', 'tags' => 'q', 'size' => 12]]);
        $this->assertFacetCountsMatchFacetValues('single update');
        $index->upsert([
            ['id' => 2, 'title' => 'b', 'tags' => ['x', 'q']],
            ['id' => 5, 'title' => 'e', 'tags' => 'z', 'price' => 5],
        ]);
        $this->assertFacetCountsMatchFacetValues('bulk upsert');
        $index->delete(3);
        $this->assertFacetCountsMatchFacetValues('single delete');
        $index->delete(1, 4);
        $this->assertFacetCountsMatchFacetValues('bulk delete');
        $index->clear();
        $this->assertFacetCountsMatchFacetValues('clear');
    }

    // --- matching strategies ---

    /**
     * big: 1,2,3,4 · fat: 1,2,5 · cat: 1,3,5,6 — fat is the rarest word.
     */
    private function strategyIndex(): Index
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['color'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'big fat cat', 'color' => 'red', 'price' => 50],
            ['id' => 2, 'title' => 'big fat', 'color' => 'red', 'price' => 40],
            ['id' => 3, 'title' => 'big cat', 'color' => 'blue', 'price' => 30],
            ['id' => 4, 'title' => 'big', 'color' => 'blue', 'price' => 20],
            ['id' => 5, 'title' => 'fat cat', 'color' => 'blue', 'price' => 10],
            ['id' => 6, 'title' => 'cat', 'color' => 'red', 'price' => 5],
        ]);
        return $index;
    }

    /** @param list<string> $sort */
    private function strategySearch(
        Index $index,
        string $query,
        MatchingStrategy $strategy,
        array $sort = [],
    ): SearchResult {
        return $index->search($query, new SearchOptions(
            asYouType: false,
            matchingStrategy: $strategy,
            sort: $sort,
            facets: ['color'],
        ));
    }

    public function testLastStrategyDropsWordsFromTheEnd(): void
    {
        $result = $this->strategySearch($this->strategyIndex(), 'big fat cat', MatchingStrategy::Last);

        // Bucket 3: doc 1; bucket 2 (big fat): doc 2; bucket 1 (big): docs 3 and 4 — doc 3 also has
        // "cat", but not "fat", so it is not in a higher bucket. Docs without "big" are not returned.
        $this->assertSame([1, 2], array_slice($result->getIds(), 0, 2));
        $this->assertEqualsCanonicalizing([3, 4], array_slice($result->getIds(), 2));
        $this->assertSame(4, $result->totalHits);
        $counts = $result->facetDistribution['color'];
        ksort($counts);
        $this->assertSame(['blue' => 2, 'red' => 2], $counts);
    }

    public function testLastIsTheDefaultStrategy(): void
    {
        $index = $this->strategyIndex();

        $this->assertSame(
            $this->strategySearch($index, 'big fat cat', MatchingStrategy::Last)->getIds(),
            $index->search('big fat cat', new SearchOptions(asYouType: false, facets: ['color']))->getIds(),
        );
    }

    public function testAllStrategyRequiresEveryWord(): void
    {
        $result = $this->strategySearch($this->strategyIndex(), 'big fat cat', MatchingStrategy::All);

        $this->assertSame([1], $result->getIds());
        $this->assertSame(1, $result->totalHits);
        $this->assertSame(['red' => 1], $result->facetDistribution['color']);
    }

    public function testFrequencyStrategyDropsTheMostFrequentWordsFirst(): void
    {
        $result = $this->strategySearch($this->strategyIndex(), 'big fat cat', MatchingStrategy::Frequency);

        // Order: fat (3 docs), then big and cat (4 each, query order). Doc 1 keeps all, doc 2
        // keeps fat + big, doc 5 keeps fat only; docs without "fat" are not returned.
        $this->assertSame([1, 2, 5], $result->getIds());
    }

    public function testUnknownWordStillCountsAsAWord(): void
    {
        $index = $this->strategyIndex();

        $this->assertEqualsCanonicalizing(
            [1, 2, 3, 4],
            $this->strategySearch($index, 'big zzz cat', MatchingStrategy::Last)->getIds(),
        );
        $this->assertSame([], $this->strategySearch($index, 'big zzz cat', MatchingStrategy::All)->getIds());
    }

    public function testSortAppliesAfterTheWordsBucket(): void
    {
        $index  = $this->strategyIndex();
        $result = $this->strategySearch($index, 'big fat cat', MatchingStrategy::Last, ['price:asc']);

        // The full match (price 50) still comes first; sort orders within each bucket.
        $this->assertSame([1, 2, 4, 3], $result->getIds());
    }

    public function testAllStrategyFindsAnSkuExactly(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([
            ['id' => 1, 'title' => 'FZ-04217 evening dress'],
            ['id' => 2, 'title' => 'FZ-04218 evening dress'],
            ['id' => 3, 'title' => 'gift card 04217'],
        ]);

        $options = new SearchOptions(asYouType: false, matchingStrategy: MatchingStrategy::All);
        $result  = $index->search('FZ-04217', $options);

        $this->assertSame([1], $result->getIds());
    }

    public function testAllStrategyIsExactWhenTheRarestWordIsComplete(): void
    {
        // "shirt" exceeds maxDocs; "linen" does not, and every result must contain it.
        $index = new Index($this->dbPath, config: new Config(maxDocs: 3));
        $docs  = [];
        for ($id = 1; $id <= 8; $id++) {
            $docs[] = ['id' => $id, 'title' => 'shirt shirt'];
        }
        $docs[] = ['id' => 9, 'title' => 'linen shirt'];
        $docs[] = ['id' => 10, 'title' => 'linen trousers'];
        $index->insert($docs);

        $all  = $index->search('linen shirt', new SearchOptions(
            asYouType: false,
            matchingStrategy: MatchingStrategy::All,
        ));
        $last = $index->search('shirt linen', new SearchOptions(asYouType: false));

        $this->assertSame([9], $all->getIds());
        $this->assertTrue($all->exhaustive);
        $this->assertFalse($last->exhaustive);
        $this->assertSame(9, $last->getIds()[0]);
    }

    // --- negated words and phrases in search() ---

    private function negationIndex(): Index
    {
        $index = new Index($this->dbPath, schema: new SchemaConfig(
            language: 'en',
            filterableFields: ['color'],
            sortableFields: ['price'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'casual shirt', 'color' => 'blue', 'price' => 30],
            ['id' => 2, 'title' => 'formal shirt', 'color' => 'white', 'price' => 50],
            ['id' => 3, 'title' => 'slim fit shirt', 'color' => 'blue', 'price' => 40],
            ['id' => 4, 'title' => 'fit red slim shirt', 'color' => 'red', 'price' => 20],
            ['id' => 5, 'title' => 'formal trousers', 'color' => 'black', 'price' => 60],
            ['id' => 6, 'title' => 'wordy t-shirt', 'color' => 'blue', 'price' => 10],
        ]);
        return $index;
    }

    /** @return list<int> */
    private function idsOf(Index $index, string $query, ?SearchOptions $options = null): array
    {
        $ids = $index->search($query, $options ?? new SearchOptions(asYouType: false))->getIds();
        sort($ids);
        return $ids;
    }

    public function testNegatedWordRemovesDocumentsContainingIt(): void
    {
        $index = $this->negationIndex();

        $this->assertSame([1, 3, 4, 6], $this->idsOf($index, 'shirt -formal'));
        // Stemmed like any query word: '-formals' removes 'formal'.
        $this->assertSame([1, 3, 4, 6], $this->idsOf($index, 'shirt -formals'));
        $this->assertSame([1, 6], $this->idsOf($index, 'shirt -formal -slim'));
    }

    public function testNegatedPhraseRemovesOnlyTheContiguousSequence(): void
    {
        $index = $this->negationIndex();

        // Doc 3 has "slim fit"; doc 4 has both words, but not in that order next to each other.
        $this->assertSame([1, 2, 4, 6], $this->idsOf($index, 'shirt -"slim fit"'));
        $this->assertSame([1, 2, 3, 6], $this->idsOf($index, 'shirt -"red slim shirt"'));
    }

    public function testQueryOfOnlyNegationsReturnsEveryOtherDocument(): void
    {
        $index = $this->negationIndex();

        $result = $index->search('-shirt', new SearchOptions(asYouType: false));
        $this->assertSame([5], $result->getIds());
        $this->assertSame(1, $result->totalHits);
        $this->assertSame([1, 3, 6], $this->idsOf($index, '-formal -red', new SearchOptions(
            filter: ['color' => 'blue'],
        )));
        $sorted = $index->search('-"slim fit"', new SearchOptions(sort: ['price:asc'], facets: ['color']));
        $this->assertSame([6, 4, 1, 2, 5], $sorted->getIds());
        $this->assertSame(5, $sorted->totalHits);
        $this->assertSame(['blue' => 2, 'black' => 1, 'red' => 1, 'white' => 1], $sorted->facetDistribution['color']);
    }

    public function testNegationsApplyToFacetCountsOfASearch(): void
    {
        $result = $this->negationIndex()->search('shirt -slim', new SearchOptions(
            asYouType: false,
            filter: ['color' => 'blue'],
            facets: ['color'],
        ));

        $ids = $result->getIds();
        sort($ids);
        $this->assertSame([1, 6], $ids);
        $this->assertSame(['blue' => 2, 'white' => 1], $result->facetDistribution['color']);
    }

    public function testHyphenInsideAWordIsNotANegation(): void
    {
        $index = $this->negationIndex();

        $this->assertSame($this->idsOf($index, 'wordy t shirt'), $this->idsOf($index, 'wordy t-shirt'));
        // A lone dash is ignored; a doubled one negates the word after it.
        $this->assertSame([1, 2, 3, 4, 6], $this->idsOf($index, 'shirt -'));
        $this->assertSame([1, 3, 4, 6], $this->idsOf($index, 'shirt --formal'));
    }

    public function testNegatedWordIsMatchedExactlyEvenWhileTyping(): void
    {
        $index = $this->negationIndex();

        // '-word' would prefix-match 'wordy' if it were treated like the last query word.
        $this->assertSame([1, 2, 3, 4, 6], $this->idsOf($index, 'shirt -word', new SearchOptions()));
        // Unknown words and stopwords exclude nothing.
        $this->assertSame([1, 2, 3, 4, 6], $this->idsOf($index, 'shirt -zzz -the'));
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->idsOf($index, '-zzz'));
    }

    public function testNegatedWordsAreNotHighlighted(): void
    {
        $result = $this->negationIndex()->search('fit -red', new SearchOptions(
            asYouType: false,
            attributesToHighlight: ['title'],
        ));

        $this->assertSame([3], $result->getIds());
        $this->assertSame('slim <mark>fit</mark> shirt', $this->formattedField($result, 'title'));
        $this->assertSame('fit -red', $result->query);
    }

    public function testFacetSearchHonoursNegations(): void
    {
        $index = $this->negationIndex();

        $this->assertSame(
            [['value' => 'blue', 'count' => 2], ['value' => 'white', 'count' => 1]],
            $index->facetSearch(new FacetSearchQuery(facetName: 'color', query: 'shirt -slim'))->facetHits,
        );
        $this->assertSame(
            [['value' => 'blue', 'count' => 3], ['value' => 'red', 'count' => 1]],
            $index->facetSearch(new FacetSearchQuery(facetName: 'color', query: '-formal'))->facetHits,
        );
    }

    public function testSearchBooleanKeepsItsOwnNotOperator(): void
    {
        $this->assertSame(
            [1, 3, 4, 6],
            $this->negationIndex()->searchBoolean('shirt -formal', new SearchOptions(asYouType: false))->getIds(),
        );
    }

    // --- multi-valued facet key flag ---

    /** @return array<string, int> facet key name => multi_valued flag, read straight from the file */
    private function multiValuedFlags(): array
    {
        $stmt = new \PDO('sqlite:' . $this->dbPath)->query('SELECT name, multi_valued FROM facet_keys ORDER BY name');
        $this->assertNotFalse($stmt);
        /** @var array<string, int> $flags */
        $flags = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        return $flags;
    }

    private function multiValuedIndex(): Index
    {
        return new Index($this->dbPath, schema: new SchemaConfig(
            filterableFields: ['tags', 'color'],
            sortableFields: ['price'],
        ));
    }

    public function testSingleInsertFlagsOnlyKeysWithTwoValues(): void
    {
        $index = $this->multiValuedIndex();
        $index->insert([['id' => 1, 'title' => 'a', 'tags' => ['x', 'y'], 'color' => ['red'], 'price' => 5]]);

        $this->assertSame(['color' => 0, 'price' => 0, 'tags' => 1], $this->multiValuedFlags());
    }

    public function testBulkInsertFlagsOnlyKeysWithTwoValues(): void
    {
        $index = $this->multiValuedIndex();
        $index->insert([
            ['id' => 1, 'title' => 'a', 'tags' => 'x', 'color' => 'red', 'price' => 5],
            ['id' => 2, 'title' => 'b', 'tags' => ['x', 'y'], 'color' => ['blue', ''], 'price' => [7]],
        ]);

        $this->assertSame(['color' => 0, 'price' => 0, 'tags' => 1], $this->multiValuedFlags());
    }

    public function testRepeatedValueIsNotMultiValued(): void
    {
        $index = $this->multiValuedIndex();
        $index->insert([['id' => 1, 'title' => 'a', 'tags' => ['x', 'x']]]);
        $index->insert([
            ['id' => 2, 'title' => 'b', 'tags' => ['y', 'y'], 'price' => [3, 3.0]],
            ['id' => 3, 'title' => 'c', 'tags' => 'z'],
        ]);

        $this->assertSame(['price' => 0, 'tags' => 0], $this->multiValuedFlags());
    }

    public function testUpdateAndUpsertFlagMultiValuedKeys(): void
    {
        $index = $this->multiValuedIndex();
        $index->insert([
            ['id' => 1, 'title' => 'a', 'tags' => 'x', 'color' => 'red'],
            ['id' => 2, 'title' => 'b', 'tags' => 'y', 'color' => 'blue'],
        ]);
        $this->assertSame(['color' => 0, 'tags' => 0], $this->multiValuedFlags());

        $index->update([['id' => 1, 'title' => 'a', 'tags' => ['x', 'y'], 'color' => 'red']]);
        $this->assertSame(['color' => 0, 'tags' => 1], $this->multiValuedFlags());

        $index->upsert([
            ['id' => 2, 'title' => 'b', 'tags' => 'y', 'color' => ['blue', 'navy']],
            ['id' => 3, 'title' => 'c', 'tags' => 'z', 'color' => 'red'],
        ]);
        $this->assertSame(['color' => 1, 'tags' => 1], $this->multiValuedFlags());
    }

    public function testDeleteKeepsTheFlagAndClearResetsIt(): void
    {
        $index = $this->multiValuedIndex();
        $index->insert([['id' => 1, 'title' => 'a', 'tags' => ['x', 'y']]]);
        $index->delete(1);
        $this->assertSame(['tags' => 1], $this->multiValuedFlags());

        $index->clear();
        $this->assertSame(['tags' => 0], $this->multiValuedFlags());
        $index->insert([['id' => 2, 'title' => 'b', 'tags' => ['x', 'y']]]);
        $this->assertSame(['tags' => 1], $this->multiValuedFlags());
    }

    public function testRebuildRecomputesTheFlag(): void
    {
        $index = $this->multiValuedIndex();
        $index->insert([
            ['id' => 1, 'title' => 'a', 'tags' => ['x', 'y']],
            ['id' => 2, 'title' => 'b', 'tags' => 'z'],
        ]);
        $index->delete(1);
        $index->close();

        Index::rebuild($this->dbPath)->close();

        $this->assertSame(['tags' => 0], $this->multiValuedFlags());
    }

    public function testFlagIsSetAgainAfterAnotherConnectionClears(): void
    {
        $writer = $this->multiValuedIndex();
        $writer->insert([['id' => 1, 'title' => 'a', 'tags' => ['x', 'y']]]);

        $other = new Index($this->dbPath);
        $other->clear();
        $other->close();

        $writer->insert([['id' => 2, 'title' => 'b', 'tags' => ['x', 'y']]]);
        $this->assertSame(['tags' => 1], $this->multiValuedFlags());
    }

    // --- checkpoint ---

    public function testCheckpointTruncatesWal(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        clearstatcache(true, $this->dbPath . '-wal');
        $this->assertGreaterThan(0, filesize($this->dbPath . '-wal'));

        $result = $index->checkpoint();

        $this->assertSame(0, $result['busy']);
        clearstatcache(true, $this->dbPath . '-wal');
        $this->assertSame(0, filesize($this->dbPath . '-wal'));
        $this->assertContains(1, $index->search('sedan')->getIds());
    }

    public function testCheckpointReturnsPageCounters(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $result = $index->checkpoint('PASSIVE');

        $this->assertGreaterThan(0, $result['log']);
        $this->assertSame($result['log'], $result['checkpointed']);
    }

    public function testCheckpointAcceptsAllModesCaseInsensitively(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        foreach (['PASSIVE', 'full', 'Restart', 'TRUNCATE'] as $mode) {
            $result = $index->checkpoint($mode);
            $this->assertSame(0, $result['busy'], "mode {$mode}");
        }
    }

    public function testCheckpointRejectsUnknownMode(): void
    {
        $index = new Index($this->dbPath);
        $this->expectException(\InvalidArgumentException::class);
        $index->checkpoint('SOMETHING');
    }

    public function testCheckpointThrowsOnReadonlyIndex(): void
    {
        new Index($this->dbPath)->close();
        $this->expectException(IOException::class);
        new Index($this->dbPath, readonly: true)->checkpoint();
    }

    // --- cacheSizeKb / mmapSizeBytes ---

    /** Read a pragma value from the index's own connection. */
    private function pragmaOf(Index $index, string $pragma): int
    {
        $pdo = new \ReflectionProperty(Index::class, 'pdo')->getValue($index);
        $this->assertInstanceOf(\PDO::class, $pdo);
        $stmt = $pdo->query("PRAGMA {$pragma}");
        $this->assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testDefaultCacheAndMmapPragmas(): void
    {
        $index = new Index($this->dbPath);
        $this->assertSame(-65536, $this->pragmaOf($index, 'cache_size'));
        $this->assertSame(536870912, $this->pragmaOf($index, 'mmap_size'));
    }

    public function testConfiguredCacheAndMmapPragmasApplied(): void
    {
        $index = new Index($this->dbPath, config: new Config(cacheSizeKb: 8_192, mmapSizeBytes: 1_073_741_824));
        $this->assertSame(-8192, $this->pragmaOf($index, 'cache_size'));
        $this->assertSame(1073741824, $this->pragmaOf($index, 'mmap_size'));
    }

    public function testConfiguredCacheAndMmapPragmasAppliedOnReadonlyOpen(): void
    {
        new Index($this->dbPath)->close();

        $read = new Index(
            $this->dbPath,
            readonly: true,
            config: new Config(cacheSizeKb: 4_096, mmapSizeBytes: 0),
        );
        $this->assertSame(-4096, $this->pragmaOf($read, 'cache_size'));
        $this->assertSame(0, $this->pragmaOf($read, 'mmap_size'));
    }

    public function testBulkLoadRestoresConfiguredCacheSize(): void
    {
        $index = new Index($this->dbPath, config: new Config(cacheSizeKb: 8_192));
        $docs  = [];
        for ($i = 1; $i <= 5; $i++) {
            $docs[] = ['id' => $i, 'title' => "sedan {$i}"];
        }
        $index->insert($docs);

        // The bulk path overrides cache_size to 512 MB; the finally block must put
        // the configured value back, not the library default.
        $this->assertSame(-8192, $this->pragmaOf($index, 'cache_size'));
        $this->assertSame(5, $index->count());
    }

    // --- reopenIfChanged ---

    public function testReopenIfChangedReturnsFalseWhenFileUntouched(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);

        $this->assertFalse($index->reopenIfChanged());
        $this->assertContains(1, $index->search('sedan')->getIds());
    }

    public function testReopenIfChangedDetectsSnapshotRotation(): void
    {
        $srcPath = sys_get_temp_dir() . '/fuzor_test_rotsrc_' . uniqid() . '.db';
        $writer  = new Index($this->dbPath);
        $writer->insert([['id' => 1, 'title' => 'sedan']]);
        $writer->close();

        $reader = new Index($this->dbPath, readonly: true);
        $this->assertSame(1, $reader->count());

        try {
            $src = new Index($srcPath);
            $src->insert([
                ['id' => 1, 'title' => 'sedan'],
                ['id' => 2, 'title' => 'coupe'],
            ]);
            $src->snapshotTo($this->dbPath);
            $src->close();

            // The open connection still reads the old inode until it reopens.
            $this->assertSame(1, $reader->count());
            $this->assertTrue($reader->reopenIfChanged());
            $this->assertSame(2, $reader->count());
            $this->assertContains(2, $reader->search('coupe')->getIds());
            $this->assertFalse($reader->reopenIfChanged());
        } finally {
            self::removeIndexFiles($srcPath);
        }
    }

    public function testReopenIfChangedDetectsRebuild(): void
    {
        $writer = new Index($this->dbPath);
        $writer->insert([['id' => 1, 'title' => 'sedan']]);
        $writer->close();

        $reader = new Index($this->dbPath, readonly: true);
        $this->assertSame(1, $reader->count());

        Index::rebuild($this->dbPath, function (Index $new): void {
            $new->insert([
                ['id' => 1, 'title' => 'sedan'],
                ['id' => 2, 'title' => 'coupe'],
            ]);
        })->close();

        $this->assertTrue($reader->reopenIfChanged());
        $this->assertSame(2, $reader->count());
    }

    public function testReopenIfChangedThrowsWhenFileGone(): void
    {
        $index = new Index($this->dbPath);
        $index->insert([['id' => 1, 'title' => 'sedan']]);
        $index->close();

        $reopened = new Index($this->dbPath, readonly: true);
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            @unlink($f);
        }

        $this->expectException(IOException::class);
        $reopened->reopenIfChanged();
    }
}

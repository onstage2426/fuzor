<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\Exceptions\QueryException;
use Fuzor\FacetSearchQuery;
use Fuzor\Index;
use Fuzor\MatchType;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Tests\Support\EnglishStemmer;
use PHPUnit\Framework\TestCase;

/**
 * On a stemmed index the word being typed and typos are matched against the words of the
 * documents, not against their stems: "hikin" and "hikng" find "hiking" (stem "hike").
 */
final class SurfaceWordsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_surface_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        Index::remove($this->path);
    }

    private function stemmed(?Config $config = null): Index
    {
        $index = new Index($this->path, config: $config, schema: new SchemaConfig(
            stemmer:              EnglishStemmer::class,
            filterableAttributes: ['brand'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'Hiking boots', 'brand' => 'Northpeak'],
            ['id' => 2, 'title' => 'Network connection cable', 'brand' => 'Linkline'],
            ['id' => 3, 'title' => 'Running shoes', 'brand' => 'Northpeak'],
        ]);
        return $index;
    }

    /** @return list<string> */
    private function surfaceRows(): array
    {
        $stmt = new \PDO('sqlite:' . $this->path)->query('SELECT word FROM surface_words ORDER BY word');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        /** @var list<string> */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testAWordBeingTypedMatchesTheWordsOfTheDocuments(): void
    {
        $index = $this->stemmed();

        $this->assertSame([1], $index->search('hikin')->getIds(), 'hiking, stem hike');
        $this->assertSame([2], $index->search('connecti')->getIds(), 'connection, stem connect');
        $this->assertSame([3], $index->search('runn')->getIds(), 'running, stem run');
        $this->assertSame([1], $index->search('boots hik')->getIds());
    }

    public function testATypoIsCountedAgainstTheWordAsWritten(): void
    {
        $index = $this->stemmed();

        // hikng is one letter short of hiking, two edits from its stem hike.
        $this->assertSame([1], $index->search('hikng', new SearchOptions(asYouType: false))->getIds());
        $this->assertSame([1], $index->search('hikng boots')->getIds());
        $this->assertSame([2], $index->search('conection cable')->getIds());
    }

    public function testAFullWordStillMatchesThroughItsStem(): void
    {
        $index = $this->stemmed();

        // "hikes" is not in any document, its stem is.
        $this->assertSame([1], $index->search('hikes')->getIds());
        $this->assertSame([1], $index->search('hikes', new SearchOptions(asYouType: false))->getIds());
    }

    public function testBooleanSearchFacetSearchAndInspectQueryUseTheTypedWord(): void
    {
        $index = $this->stemmed();

        $this->assertSame([1], $index->searchBoolean('hikin')->getIds());
        $this->assertSame([2], $index->searchBoolean('cable & connecti')->getIds());

        $facets = $index->facetSearch(new FacetSearchQuery(facetName: 'brand', q: 'hikin'));
        $this->assertSame([['value' => 'Northpeak', 'count' => 1]], $facets->facetHits);

        $token = $index->inspectQuery('hikin')->tokens[0];
        $this->assertSame(MatchType::Prefix, $token->matchType);
        $this->assertSame(['hike'], array_column($token->wordlistRows, 'term'));
    }

    public function testThePrefixCapCountsStemsNotWords(): void
    {
        $index = $this->stemmed(new Config(fuzzyMaxExpansions: 2));
        $index->insert([
            ['id' => 4, 'title' => 'connected connecting connections connector'],
        ]);

        $result = $index->search('conn');
        $this->assertTrue($result->exhaustive, 'two stems: connect and connector');
        $this->assertEqualsCanonicalizing([2, 4], $result->getIds());
    }

    public function testWordsOfUpdatedDocumentsAreAdded(): void
    {
        $index = $this->stemmed();
        $index->update([['id' => 3, 'title' => 'Trekking poles', 'brand' => 'Northpeak']]);
        $index->upsert([['id' => 5, 'title' => 'Climbing rope']]);

        $this->assertSame([3], $index->search('trekki')->getIds());
        $this->assertSame([5], $index->search('climbi')->getIds());
    }

    public function testAWriteThatRollsBackLeavesNoWordBehindAndForgetsNone(): void
    {
        $index = $this->stemmed();

        try {
            $index->insert([['id' => 6, 'title' => 'Paddling jacket'], ['id' => 1, 'title' => 'duplicate']]);
            $this->fail('a duplicate ID was inserted');
        } catch (QueryException) {
        }
        $this->assertNotContains('paddling', $this->surfaceRows());

        $index->insert([['id' => 7, 'title' => 'Paddling gloves']]);
        $this->assertSame([7], $index->search('paddli')->getIds());
    }

    public function testClearForgetsTheWords(): void
    {
        $index = $this->stemmed();
        $index->clear();
        $this->assertSame([], $this->surfaceRows());

        // "hikes" has the stem that "hiking" had; "hikin" is not a prefix of it.
        $index->insert([['id' => 8, 'title' => 'Hikes nearby']]);
        $this->assertSame([], $index->search('hikin')->getIds());
        $this->assertSame([8], $index->search('hike')->getIds());
    }

    public function testAnotherConnectionAndAReadonlyOneSeeTheWords(): void
    {
        $this->stemmed()->close();

        $writer = new Index($this->path);
        $writer->insert([['id' => 9, 'title' => 'Kayaking map']]);
        $writer->insert([['id' => 10, 'title' => 'Kayaking helmet']]);
        $writer->close();

        $reader = new Index($this->path, readonly: true);
        $this->assertEqualsCanonicalizing([9, 10], $reader->search('kayaki')->getIds());
        $this->assertSame([1], $reader->search('hikin')->getIds());
        $reader->close();
    }

    public function testAnIndexWithoutAStemmerKeepsNoWords(): void
    {
        $index = new Index($this->path);
        $index->insert([['id' => 1, 'title' => 'Hiking boots']]);

        $this->assertSame([], $this->surfaceRows());
        $this->assertSame([1], $index->search('hikin')->getIds());
        $this->assertSame([1], $index->search('hikng', new SearchOptions(asYouType: false))->getIds());
    }
}

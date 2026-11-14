<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\FacetSearchQuery;
use Fuzor\Filter;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\Sort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Sort specs as objects and strings, and the option checks made when the options are built. */
final class SortTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_sort_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        Index::remove($this->path);
    }

    public function testFactoriesAndStringForm(): void
    {
        $asc  = Sort::asc('price');
        $desc = Sort::desc('price');

        $this->assertSame(['price', true], [$asc->field, $asc->ascending]);
        $this->assertSame(['price', false], [$desc->field, $desc->ascending]);
        $this->assertSame('price:asc', (string) $asc);
        $this->assertSame('price:desc', (string) $desc);
        $this->assertEquals($desc, Sort::fromString('price:DESC'));
        $this->assertSame('a:b', Sort::fromString('a:b:asc')->field, 'the last colon separates the direction');
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'no direction'    => ['price'],
            'other direction' => ['price:up'],
            'no field'        => [':asc'],
            'empty'           => [''],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedSpecThrowsWhenTheOptionsAreBuilt(string $spec): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid sort spec '{$spec}'");
        new SearchOptions(sort: [$spec]);
    }

    public function testEmptyFieldNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sort::asc('');
    }

    public function testStringsAreReadBackAsSortObjects(): void
    {
        $options = new SearchOptions(sort: ['price:desc', Sort::asc('title')]);

        $this->assertEquals([Sort::desc('price'), Sort::asc('title')], $options->sort);
    }

    public function testObjectsAndStringsSortTheSame(): void
    {
        $index = $this->catalog();

        foreach (['', 'boots'] as $query) {
            $this->assertSame(
                $index->search($query, new SearchOptions(sort: ['price:desc', 'id:asc']))->getIds(),
                $index->search($query, new SearchOptions(sort: [Sort::desc('price'), Sort::asc('id')]))->getIds(),
                "query '{$query}'",
            );
        }
        $this->assertSame(
            [4, 1, 3],
            $index->searchBoolean('boots', new SearchOptions(sort: [Sort::asc('price')]))->getIds(),
        );
        $hit = $index->search('boots', new SearchOptions(
            sort: [Sort::desc('price')],
            showRankingScoreDetails: true,
        ))->hits[0];
        $rules = $hit['_rankingScoreDetails'] ?? null;
        $this->assertIsArray($rules);
        $this->assertSame(['order' => 2, 'value' => 159.0], $rules['price:desc'] ?? null);
        $index->close();
    }

    public function testUnsortableFieldStillThrowsWhenUsed(): void
    {
        $index   = $this->catalog();
        $options = new SearchOptions(sort: [Sort::asc('brand')]);

        $this->expectException(\Fuzor\Exceptions\QueryException::class);
        $this->expectExceptionMessage("Sort field 'brand' is not sortable");
        $index->search('boots', $options);
    }

    public function testFieldInFacetsAndFacetStatsOnlyThrowsWhenTheOptionsAreBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'price' is listed in both");
        new SearchOptions(facets: ['price'], facetStatsOnly: ['price']);
    }

    /** @return array<string, array{array<array-key, mixed>, string}> */
    public static function malformedFilters(): array
    {
        return [
            'a list, not a map'      => [['Northpeak'], 'must map field names to values'],
            'a bool'                 => [['brand' => true], 'the value of "brand"'],
            'not a finite number'    => [['price' => NAN], 'a number that is not finite'],
            'a nested list'          => [['brand' => [['Northpeak']]], 'the value of "brand"'],
        ];
    }

    /** @param array<array-key, mixed> $filter */
    #[DataProvider('malformedFilters')]
    public function testMalformedFilterThrowsWhereItIsPassed(array $filter, string $message): void
    {
        $index = $this->catalog();
        $calls = [
            // @phpstan-ignore argument.type (malformed on purpose)
            'SearchOptions'    => fn() => new SearchOptions(filter: $filter),
            // @phpstan-ignore argument.type
            'FacetSearchQuery' => fn() => new FacetSearchQuery('brand', filter: $filter),
            // @phpstan-ignore argument.type
            'deleteByFilter()' => fn() => $index->deleteByFilter($filter),
            // @phpstan-ignore argument.type
            'Filter::all()'    => fn() => Filter::all($filter),
        ];
        foreach ($calls as $where => $call) {
            try {
                $call();
                $this->fail("{$where} accepted the filter");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $where);
                $this->assertStringContainsString(
                    $where === 'Filter::all()' ? 'Filter::all() entry 0' : $where,
                    $e->getMessage(),
                );
            }
        }
        $this->assertCount(4, $index);
        $index->close();
    }

    private function catalog(): Index
    {
        $index = new Index($this->path, schema: new SchemaConfig(
            filterableAttributes: ['brand', 'price'],
            sortableAttributes:   ['price', 'id'],
        ));
        $index->insert([
            ['id' => 1, 'title' => 'Waterproof hiking boots', 'brand' => 'Northpeak', 'price' => 129],
            ['id' => 2, 'title' => 'Trail running shoes', 'brand' => 'Fellwalk', 'price' => 89],
            ['id' => 3, 'title' => 'Leather hiking boots', 'brand' => 'Northpeak', 'price' => 159],
            ['id' => 4, 'title' => 'Lightweight hiking boots', 'brand' => 'Fellwalk', 'price' => 99],
        ]);
        return $index;
    }
}

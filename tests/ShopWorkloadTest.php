<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\Index;
use Fuzor\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pages of a web shop (ShopCatalog) against a plain scan of its documents: every total, facet
 * count and bound must be what the documents say. These requests run through the exclusion
 * complement, the filtered counts and the field boosts; the scan shares none of that code.
 *
 * @phpstan-import-type ShopDoc from ShopCatalog
 */
class ShopWorkloadTest extends TestCase
{
    private const int DOCS = 700;
    private const array KEYWORD_SEARCHES = ['shop_search', 'shop_search_typo', 'category_search'];

    private static string $dbPath;

    /** @var array<int, ShopDoc> */
    private static array $docs = [];

    public static function setUpBeforeClass(): void
    {
        self::$dbPath = sys_get_temp_dir() . '/fuzor_shop_' . uniqid() . '.db';
        $documents    = ShopCatalog::documents(self::DOCS);
        $index        = new Index(self::$dbPath, force: true, schema: ShopCatalog::schema());
        $index->insert($documents);
        $index->close();
        foreach ($documents as $i => $document) {
            self::$docs[$i + 1] = $document;
        }
    }

    public static function tearDownAfterClass(): void
    {
        Index::remove(self::$dbPath);
    }

    /** @return array<string, array{string}> */
    public static function browsePages(): array
    {
        $names = array_diff(array_keys(ShopCatalog::searches()), self::KEYWORD_SEARCHES);
        return array_combine($names, array_map(fn(string $name): array => [$name], $names));
    }

    /** @return array<string, array{string}> */
    public static function keywordSearches(): array
    {
        return array_combine(
            self::KEYWORD_SEARCHES,
            array_map(fn(string $name): array => [$name], self::KEYWORD_SEARCHES),
        );
    }

    #[DataProvider('browsePages')]
    public function testBrowsePageMatchesAScanOfTheDocuments(string $name): void
    {
        $options = ShopCatalog::searches(0)[$name]['options'];
        $result  = $this->searchPage($name);
        $matches = self::scan($options->filter);

        $this->assertNotSame([], $matches, 'the catalogue must give this page something to show');
        $this->assertSame(count($matches), $result->totalHits);
        $this->assertCount(min($options->limit, max(0, count($matches) - $options->offset)), $result->hits);
        $this->assertPageFollowsSort($result, $options->sort);
        foreach ($options->facets as $key) {
            $counted = self::scan(self::withoutSelection($options->filter, $key));
            $this->assertEqualsCanonicalizing(
                self::countValues($counted, $key),
                $result->facetDistribution[$key] ?? [],
                "facet {$key}",
            );
            $bounds = $result->facetStats[$key] ?? null;
            $this->assertSame(self::numericBounds($counted, $key), $bounds, "bounds of {$key}");
        }
    }

    #[DataProvider('keywordSearches')]
    public function testKeywordSearchCountsTheDocumentsItFound(string $name): void
    {
        $options = ShopCatalog::searches(0)[$name]['options'];
        $all     = $this->searchPage($name, self::DOCS);
        $found   = array_intersect_key(self::$docs, array_flip($all->getIds()));

        $this->assertNotSame([], $found);
        $this->assertSame(count($found), $all->totalHits);
        $this->assertSame([], array_diff_key($found, self::scan($options->filter)), 'a hidden or filtered document');
        foreach ($options->facets as $key) {
            $this->assertEqualsCanonicalizing(
                self::countValues($found, $key),
                $all->facetDistribution[$key] ?? [],
                "facet {$key}",
            );
        }
    }

    #[DataProvider('keywordSearches')]
    public function testCardTextIsHighlightedAndEscaped(string $name): void
    {
        $result = $this->searchPage($name);
        $marked = 0;
        foreach ($result->hits as $hit) {
            $this->assertIsArray($hit['_formatted'] ?? null);
            $formatted = $hit['_formatted'];
            foreach (['title', 'description'] as $field) {
                $this->assertIsString($formatted[$field] ?? null);
                $text    = $formatted[$field];
                $marked += substr_count($text, '<mark>');
                $this->assertStringNotContainsString(
                    '<',
                    str_replace(['<mark>', '</mark>'], '', $text),
                    'only the marks may stay HTML',
                );
            }
        }
        $this->assertGreaterThan(0, $marked);
    }

    /** The page a new connection gives is the page a used one gives, in every detail. */
    public function testEverySearchIsTheSameOnAWarmConnection(): void
    {
        foreach (ShopCatalog::searches() as $name => $search) {
            $index = new Index(self::$dbPath, config: $search['config'], readonly: true);
            $cold  = $index->search($search['query'], $search['options'])->toArray();
            $warm  = $index->search($search['query'], $search['options'])->toArray();
            $index->close();
            $this->assertSame($cold, $warm, $name);
        }
    }

    private function searchPage(string $name, ?int $keywordLimit = null): SearchResult
    {
        $search = ShopCatalog::searches(0, $keywordLimit)[$name];
        $index  = new Index(self::$dbPath, config: $search['config'], readonly: true);
        $result = $index->search($search['query'], $search['options']);
        $index->close();
        return $result;
    }

    /** @param list<string> $sort */
    private function assertPageFollowsSort(SearchResult $result, array $sort): void
    {
        $previous = null;
        foreach ($result->getIds() as $id) {
            $current = [];
            foreach ($sort as $spec) {
                [$field, $direction] = explode(':', $spec);
                $value     = self::$docs[$id][$field];
                $current[] = [$direction === 'desc' ? -1 : 1, $value];
            }
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual(0, self::compareSortKeys($current, $previous), "document {$id}");
            }
            $previous = $current;
        }
    }

    /**
     * @param list<array{int, mixed}> $a
     * @param list<array{int, mixed}> $b
     */
    private static function compareSortKeys(array $a, array $b): int
    {
        foreach ($a as $i => [$direction, $value]) {
            $order = $direction * ($value <=> $b[$i][1]);
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }

    /**
     * The documents passing a filter, by a plain loop.
     *
     * @param  array<string, mixed> $filter
     * @return array<int, ShopDoc>
     */
    private static function scan(array $filter): array
    {
        return array_filter(self::$docs, function (array $doc) use ($filter): bool {
            foreach ($filter as $key => $wanted) {
                $values = self::valuesOf($doc, $key);
                $passes = $wanted instanceof FacetExclude
                    ? !self::holdsAny($values, $wanted->filter)
                    : self::holdsAny($values, $wanted);
                if (!$passes) {
                    return false;
                }
            }
            return true;
        });
    }

    /** @param list<int|float|string> $values */
    private static function holdsAny(array $values, mixed $wanted): bool
    {
        if ($wanted instanceof FacetRange) {
            return array_any($values, fn($v): bool => $v >= $wanted->gte && $v <= $wanted->lte);
        }
        /** @var list<string> $listed */
        $listed = is_array($wanted) ? $wanted : [$wanted];
        return array_intersect(array_map(fn($v): string => (string) $v, $values), $listed) !== [];
    }

    /**
     * A key's own selection does not narrow its counts; an exclusion always does.
     *
     * @param  array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private static function withoutSelection(array $filter, string $key): array
    {
        if (isset($filter[$key]) && !$filter[$key] instanceof FacetExclude) {
            unset($filter[$key]);
        }
        return $filter;
    }

    /**
     * @param  ShopDoc $doc
     * @return list<int|float|string>
     */
    private static function valuesOf(array $doc, string $key): array
    {
        $value = $doc[$key] ?? [];
        return is_array($value) ? $value : [$value];
    }

    /**
     * @param  array<int, ShopDoc> $docs
     * @return array<array-key, int>
     */
    private static function countValues(array $docs, string $key): array
    {
        $counts = [];
        foreach ($docs as $doc) {
            foreach (self::valuesOf($doc, $key) as $value) {
                $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * @param  array<int, ShopDoc> $docs
     * @return array{min: float, max: float}|null
     */
    private static function numericBounds(array $docs, string $key): ?array
    {
        $values = array_merge(...array_values(array_map(fn(array $doc): array => self::valuesOf($doc, $key), $docs)));
        if ($values === [] || !array_all($values, fn($v): bool => is_int($v) || is_float($v))) {
            return null;
        }
        return ['min' => (float) min($values), 'max' => (float) max($values)];
    }
}

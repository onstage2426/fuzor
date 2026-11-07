<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Random filter combinations against a plain scan of the documents: the total, the page and every
 * facet count of a browse, whichever way its groups are counted (from their own documents, or as
 * the whole index minus the others) and through the paths used when a condition exceeds the cap.
 *
 * @phpstan-type Doc array{id: int, title: string, color: list<string>, size?: string, stock: string, price: float}
 */
class FacetClassesTest extends TestCase
{
    private const int DOCS = 400;
    private const array COLORS = ['red', 'green', 'blue', 'black', 'white'];
    private const array SIZES = ['s', 'm', 'l'];
    private const array STOCK = ['in', 'out', 'back'];
    private const array FACETS = ['color', 'size', 'stock', 'price'];

    private string $path;

    /** @var array<int, Doc> */
    private array $docs = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_classes_' . uniqid() . '.db';
        $random     = new Randomizer(new Mt19937(7));
        for ($id = 1; $id <= self::DOCS; $id++) {
            $doc = [
                'id'    => $id,
                'title' => 'product',
                'color' => array_values(array_unique([
                    self::COLORS[$random->getInt(0, 4)],
                    self::COLORS[$random->getInt(0, 4)],
                ])),
                'stock' => self::STOCK[$random->getInt(0, 2)],
                'price' => $random->getInt(1, 40) * 2.5,
            ];
            if ($random->getInt(1, 10) <= 7) {
                $doc['size'] = self::SIZES[$random->getInt(0, 2)];
            }
            $this->docs[$id] = $doc;
        }
        $index = new Index($this->path, force: true, schema: new SchemaConfig(
            filterableAttributes: self::FACETS,
            sortableAttributes: ['price'],
            searchableAttributes: ['title'],
        ));
        $index->insert(array_values($this->docs));
        foreach (range(5, self::DOCS, 37) as $id) {
            $index->delete($id);
            unset($this->docs[$id]);
        }
        $index->close();
    }

    protected function tearDown(): void
    {
        Index::remove($this->path);
    }

    /** @return array<string, array{int}> */
    public static function caps(): array
    {
        return [
            'every condition in PHP'          => [50_000],
            'index larger than the cap'       => [self::DOCS - 100],
            'broad conditions above the cap'  => [120],
        ];
    }

    #[DataProvider('caps')]
    public function testBrowseMatchesAScanOfTheDocuments(int $cap): void
    {
        $index  = new Index($this->path, config: new Config(maxFacetCountDocs: $cap), readonly: true);
        $random = new Randomizer(new Mt19937($cap));
        $counted = 0;
        for ($i = 0; $i < 150; $i++) {
            $filter = $this->randomFilter($random);
            if ($filter === []) {
                continue;
            }
            // Some pages count only some keys: the filters on the others then bound the reads.
            $facets = $i % 3 === 2 ? ['size', 'price'] : self::FACETS;
            $result = $index->search('', new SearchOptions(
                limit: 500,
                filter: $filter,
                facets: $facets,
                sort: $i % 2 === 0 ? ['price:asc'] : [],
            ));
            $matches = $this->scan($filter);
            $label   = json_encode($filter, JSON_THROW_ON_ERROR);
            $this->assertSame(count($matches), $result->totalHits, $label);
            $this->assertEqualsCanonicalizing(array_keys($matches), $result->getIds(), $label);
            if ($i % 2 === 1) {
                $this->assertSame(array_reverse(array_keys($matches)), $result->getIds(), "order {$label}");
            }
            if ($result->approximateFacets !== []) {
                continue;
            }
            $counted++;
            foreach ($facets as $key) {
                $expected = $this->countValues($this->scan(self::withoutOwnFilter($filter, $key)), $key);
                $this->assertEqualsCanonicalizing(
                    $expected,
                    $result->facetDistribution[$key] ?? [],
                    "{$key} of {$label}",
                );
            }
            $prices = array_column($this->scan(self::withoutOwnFilter($filter, 'price')), 'price');
            $this->assertSame(
                $prices === [] ? null : ['min' => min($prices), 'max' => max($prices)],
                $result->facetStats['price'] ?? null,
                "price bounds of {$label}",
            );
        }
        $this->assertGreaterThan(50, $counted);
        $index->close();
    }

    /** @return array<string, list<string>|string|FacetRange|FacetExclude> */
    private function randomFilter(Randomizer $random): array
    {
        $filter = [];
        $color  = match ($random->getInt(0, 4)) {
            0       => self::COLORS[$random->getInt(0, 4)],
            1       => self::some($random),
            2       => new FacetExclude(self::some($random)),
            default => null,
        };
        $size = match ($random->getInt(0, 3)) {
            0       => self::SIZES[$random->getInt(0, 2)],
            1       => new FacetExclude(self::SIZES[$random->getInt(0, 2)]),
            default => null,
        };
        $stock = match ($random->getInt(0, 3)) {
            0       => ['in', 'back'],
            1       => self::STOCK[$random->getInt(0, 2)],
            default => null,
        };
        $price = match ($random->getInt(0, 3)) {
            0       => new FacetRange(gte: 10.0, lte: 80.0),
            1       => new FacetExclude(new FacetRange(gte: 50.0)),
            default => null,
        };
        foreach (['color' => $color, 'size' => $size, 'stock' => $stock, 'price' => $price] as $key => $value) {
            if ($value !== null) {
                $filter[$key] = $value;
            }
        }
        return $filter;
    }

    /** @return list<string> */
    private static function some(Randomizer $random): array
    {
        /** @var list<string> $colors */
        $colors = $random->shuffleArray(self::COLORS);
        return array_slice($colors, 0, $random->getInt(1, 3));
    }

    /**
     * @param  array<string, list<string>|string|FacetRange|FacetExclude> $filter
     * @return array<int, Doc>
     */
    private function scan(array $filter): array
    {
        return array_filter($this->docs, function (array $doc) use ($filter): bool {
            foreach ($filter as $key => $wanted) {
                $exclude = $wanted instanceof FacetExclude;
                $inner   = $exclude ? $wanted->filter : $wanted;
                if (self::holds($doc[$key] ?? null, $inner) === $exclude) {
                    return false;
                }
            }
            return true;
        });
    }

    /** @param list<string>|string|FacetRange|FacetExclude $wanted */
    private static function holds(mixed $values, string|array|FacetRange|FacetExclude $wanted): bool
    {
        if ($values === null) {
            return false;
        }
        /** @var list<float|string> $values */
        $values = is_array($values) ? $values : [$values];
        if ($wanted instanceof FacetExclude) {
            return false;
        }
        if ($wanted instanceof FacetRange) {
            foreach ($values as $value) {
                if ($value >= ($wanted->gte ?? -INF) && $value <= ($wanted->lte ?? INF)) {
                    return true;
                }
            }
            return false;
        }
        return array_intersect(array_map(strval(...), $values), (array) $wanted) !== [];
    }

    /**
     * @param  array<string, list<string>|string|FacetRange|FacetExclude> $filter
     * @return array<string, list<string>|string|FacetRange|FacetExclude>
     */
    private static function withoutOwnFilter(array $filter, string $key): array
    {
        if (!(($filter[$key] ?? null) instanceof FacetExclude)) {
            unset($filter[$key]);
        }
        return $filter;
    }

    /**
     * @param  array<int, Doc> $docs
     * @return array<array-key, int>
     */
    private function countValues(array $docs, string $key): array
    {
        $counts = [];
        foreach ($docs as $doc) {
            foreach ((array) ($doc[$key] ?? []) as $value) {
                $text          = (string) $value;
                $counts[$text] = ($counts[$text] ?? 0) + 1;
            }
        }
        return $counts;
    }
}

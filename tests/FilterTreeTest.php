<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\Filter;
use Fuzor\Index;
use Fuzor\Limits;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Random Filter trees against a plain scan of the documents: totals, pages and facet counts of
 * browse, search, boolean search and facet search, through the capped and uncapped paths, and
 * the documents deleteByFilter() removes.
 *
 * Facet counts follow the rule for trees: a key with exactly one positive leaf at the top level
 * (after simplification) is counted without that leaf; every other key over the filtered result.
 *
 * @phpstan-type Doc array{id: int, title: string, color: list<string>, size?: string, stock: string, price: float}
 * @phpstan-type FilterMap array<string, list<string>|string|FacetRange|FacetExclude>
 */
class FilterTreeTest extends TestCase
{
    private const int DOCS = 300;
    private const array COLORS = ['red', 'green', 'blue', 'black', 'white'];
    private const array SIZES = ['s', 'm', 'l'];
    private const array STOCK = ['in', 'out', 'back'];
    private const array WORDS = ['shirt', 'shoe', 'sock', 'scarf'];
    private const array FACETS = ['color', 'size', 'stock', 'price'];

    private string $path;

    /** @var array<int, Doc> */
    private array $docs = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fuzor_tree_' . uniqid() . '.db';
        $random     = new Randomizer(new Mt19937(5));
        for ($id = 1; $id <= self::DOCS; $id++) {
            $doc = [
                'id'    => $id,
                'title' => 'product ' . self::WORDS[$random->getInt(0, 3)],
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
        $index = new Index($this->path, schema: new SchemaConfig(
            filterableAttributes: [...self::FACETS, 'unused'],
            sortableAttributes: ['price'],
            searchableAttributes: ['title'],
        ));
        $index->insert(array_values($this->docs));
        foreach (range(4, self::DOCS, 37) as $id) {
            $index->delete($id);
            unset($this->docs[$id]);
        }
        $index->close();
    }

    protected function tearDown(): void
    {
        Index::remove($this->path);
    }

    /** @return array<string, array{int, int}> */
    public static function limits(): array
    {
        return [
            'nothing capped'              => [1_000, 50_000],
            'facet counts near the index' => [1_000, self::DOCS - 60],
            'words and conditions capped' => [12, 90],
        ];
    }

    #[DataProvider('limits')]
    public function testTreesMatchAScanOfTheDocuments(int $candidatesPerWord, int $facetCountDocs): void
    {
        $index   = new Index($this->path, readonly: true, config: new Config(limits: new Limits(
            candidatesPerWord: $candidatesPerWord,
            facetCountDocs: $facetCountDocs,
        )));
        $random  = new Randomizer(new Mt19937($candidatesPerWord + $facetCountDocs));
        $checked = 0;
        for ($i = 0; $i < 120; $i++) {
            $filter = $this->randomTree($random);
            $label  = json_encode($filter, JSON_THROW_ON_ERROR);
            foreach (['' => null, 'product' => null, 'shirt' => 'shirt', 'sh' => 'sh'] as $q => $word) {
                $words = $word === null
                    ? $this->docs
                    : array_filter($this->docs, fn(array $doc): bool => str_contains($doc['title'], " {$word}"));
                $sort    = $i % 3 === 0 ? ['price:asc'] : [];
                $result  = $index->search((string) $q, new SearchOptions(
                    asYouType: $q === 'sh',
                    limit: 500,
                    filter: $filter,
                    facets: self::FACETS,
                    sort: $sort,
                ));
                $checked += $this->assertResult($result, $filter, $words, "search '{$q}' {$label}", $sort !== []);
                if ($q !== 'sh') {
                    $boolean = $index->searchBoolean((string) $q, new SearchOptions(
                        asYouType: false,
                        limit: 500,
                        filter: $filter,
                        facets: self::FACETS,
                    ));
                    $this->assertResult($boolean, $filter, $words, "searchBoolean '{$q}' {$label}", false);
                }
                $facets = $index->facetSearch(new FacetSearchQuery(
                    facetName: 'color',
                    q: $q === 'sh' ? '' : (string) $q,
                    filter: $filter,
                    limit: 10,
                ));
                if ($facets->exhaustive) {
                    $this->assertEqualsCanonicalizing(
                        self::countValues($this->scan($filter, $q === 'sh' ? $this->docs : $words), 'color'),
                        array_column($facets->facetHits, 'count', 'value'),
                        "facetSearch '{$q}' {$label}",
                    );
                }
            }
            if ($i % 4 === 0) {
                $distinct = $index->search('', new SearchOptions(limit: 500, filter: $filter, distinct: 'stock'));
                $this->assertSame(
                    count(array_unique(array_column($this->scan($filter, $this->docs), 'stock'))),
                    $distinct->totalHits,
                    "distinct {$label}",
                );
            }
        }
        $this->assertGreaterThan(200, $checked, 'facet counts compared');
        $index->close();
    }

    public function testDeleteByFilterRemovesTheMatchingDocuments(): void
    {
        $random = new Randomizer(new Mt19937(99));
        $copy   = sys_get_temp_dir() . '/fuzor_tree_delete_' . uniqid() . '.db';
        for ($i = 0; $i < 12; $i++) {
            $filter = $this->randomTree($random);
            copy($this->path, $copy);
            $index   = new Index($copy);
            $deleted = $index->deleteByFilter($filter);
            $gone    = $this->scan($filter, $this->docs);
            $label   = json_encode($filter, JSON_THROW_ON_ERROR);
            $this->assertSame(count($gone), $deleted, $label);
            $left = $index->search('', new SearchOptions(limit: 500, attributesToRetrieve: []))->getIds();
            $this->assertEqualsCanonicalizing(array_keys(array_diff_key($this->docs, $gone)), $left, $label);
            $index->close();
            Index::remove($copy);
        }
    }

    /**
     * Simplification keeps the meaning: every document satisfies a tree exactly when it
     * satisfies its normal form.
     */
    public function testNormalFormKeepsTheMeaning(): void
    {
        $random = new Randomizer(new Mt19937(3));
        for ($i = 0; $i < 400; $i++) {
            $filter = $this->randomTree($random);
            $normal = $filter->normalized();
            foreach ($this->docs as $doc) {
                $this->assertSame(
                    self::holdsEntry($doc, $filter),
                    is_bool($normal) ? $normal : self::holdsEntry($doc, $normal),
                    json_encode($filter, JSON_THROW_ON_ERROR) . " on {$doc['id']}",
                );
            }
        }
    }

    /**
     * @param  array<int, Doc> $words The documents the query's words select.
     * @return int                    1 when facet counts were compared.
     */
    private function assertResult(SearchResult $result, Filter $filter, array $words, string $label, bool $sorted): int
    {
        $matches = $this->scan($filter, $words);
        $this->assertSame(count($matches), $result->totalHits, $label);
        // A capped boolean search pages and counts facets over its candidates; its total is exact.
        if (!$result->exhaustive && str_starts_with($label, 'searchBoolean')) {
            return 0;
        }
        $this->assertEqualsCanonicalizing(array_keys($matches), $result->getIds(), $label);
        if ($sorted) {
            $prices = array_map(fn(int $id): float => $this->docs[$id]['price'], $result->getIds());
            $ordered = $prices;
            sort($ordered);
            $this->assertSame($ordered, $prices, "price order {$label}");
        }
        if ($result->approximateFacets !== []) {
            return 0;
        }
        foreach (self::FACETS as $key) {
            $counted = $this->scan(self::withoutOwnLeaf($filter, $key), $words);
            $this->assertEqualsCanonicalizing(
                self::countValues($counted, $key),
                $result->facetDistribution[$key] ?? [],
                "{$key} of {$label}",
            );
        }
        return 1;
    }

    /**
     * The tree a facet of $key is counted over: without the key's top-level positive leaf when it
     * has exactly one there, else the tree itself.
     */
    private static function withoutOwnLeaf(Filter $filter, string $key): Filter
    {
        $normal = $filter->normalized();
        if (is_bool($normal)) {
            return $filter;
        }
        $own = [];
        foreach ($normal->entries as $i => $entry) {
            if (is_array($entry) && array_key_first($entry) === $key && !$entry[$key] instanceof FacetExclude) {
                $own[] = $i;
            }
        }
        if (count($own) !== 1) {
            return $filter;
        }
        $rest = $normal->entries;
        unset($rest[$own[0]]);
        return Filter::all(...array_values($rest));
    }

    /**
     * A random tree up to three levels deep over the four populated fields, with ranges,
     * exclusions, several leaves on one key and a declared field no document has (top level only:
     * inside a group it folds away, which the equivalence tests cover).
     */
    private function randomTree(Randomizer $random, int $depth = 0): Filter
    {
        $entries = [];
        $count   = $random->getInt(1, 3);
        for ($n = 0; $n < $count; $n++) {
            $entries[] = $depth < 2 && $random->getInt(0, 2) === 0
                ? $this->randomTree($random, $depth + 1)
                : $this->randomMap($random);
        }
        $operator = $random->getInt(0, $depth === 0 ? 3 : 2);
        if ($depth === 0 && $operator >= 2 && $random->getInt(0, 7) === 0) {
            $entries[] = ['unused' => $random->getInt(0, 1) === 0 ? 'x' : new FacetExclude('x')];
        }
        return match ($operator) {
            0       => Filter::any(...$entries),
            1       => Filter::not($entries[0]),
            default => Filter::all(...$entries),
        };
    }

    /** @return FilterMap */
    private function randomMap(Randomizer $random): array
    {
        $map = [];
        for ($n = $random->getInt(1, 2); $n > 0; $n--) {
            $key   = self::FACETS[$random->getInt(0, 3)];
            $value = match ($key) {
                'color' => $random->getInt(0, 1) === 0 ? self::COLORS[$random->getInt(0, 4)] : self::some($random),
                'size'  => self::SIZES[$random->getInt(0, 2)],
                'stock' => $random->getInt(0, 1) === 0 ? self::STOCK[$random->getInt(0, 2)] : ['in', 'back'],
                default => match ($random->getInt(0, 2)) {
                    0       => FacetRange::between(10.0, 60.0),
                    1       => FacetRange::min(70.0),
                    default => FacetRange::max(25.0),
                },
            };
            $map[$key] = $random->getInt(0, 4) === 0 ? new FacetExclude($value) : $value;
        }
        return $map;
    }

    /** @return list<string> */
    private static function some(Randomizer $random): array
    {
        /** @var list<string> $colors */
        $colors = $random->shuffleArray(self::COLORS);
        return array_slice($colors, 0, $random->getInt(1, 3));
    }

    /**
     * @param  array<int, Doc> $docs
     * @return array<int, Doc>
     */
    private function scan(Filter $filter, array $docs): array
    {
        return array_filter($docs, fn(array $doc): bool => self::holdsEntry($doc, $filter));
    }

    /**
     * @param Doc                 $doc
     * @param FilterMap|Filter    $entry
     */
    private static function holdsEntry(array $doc, array|Filter $entry): bool
    {
        if (is_array($entry)) {
            foreach ($entry as $key => $wanted) {
                $exclude = $wanted instanceof FacetExclude;
                if (self::holds($doc[$key] ?? null, $exclude ? $wanted->filter : $wanted) === $exclude) {
                    return false;
                }
            }
            return true;
        }
        return match ($entry->operator) {
            Filter::ALL => array_filter($entry->entries, fn($e): bool => !self::holdsEntry($doc, $e)) === [],
            Filter::ANY => array_filter($entry->entries, fn($e): bool => self::holdsEntry($doc, $e)) !== [],
            default     => !self::holdsEntry($doc, $entry->entries[0]),
        };
    }

    /** @param list<string>|string|FacetRange $wanted */
    private static function holds(mixed $values, string|array|FacetRange $wanted): bool
    {
        if ($values === null) {
            return false;
        }
        /** @var list<float|string> $values */
        $values = is_array($values) ? $values : [$values];
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
     * @param  array<int, Doc> $docs
     * @return array<array-key, int>
     */
    private static function countValues(array $docs, string $key): array
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

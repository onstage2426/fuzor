<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\FacetExclude;
use Fuzor\FacetOrder;
use Fuzor\FacetRange;
use Fuzor\MatchingStrategy;
use Fuzor\SchemaConfig;
use Fuzor\SearchOptions;
use Fuzor\TypoTolerance;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A generated web shop and the searches its pages send, for ShopWorkloadTest and the shop group
 * of FuzorBench. Modelled on a production catalogue: every request hides products with a constant
 * exclusion, pages count many sparse facets, multi-valued categories, a near-unique price per
 * product, a sort on two fields, highlighting and cropping on every card, and a new read-only
 * Index per request.
 *
 * @phpstan-type ShopDoc array<string, int|float|string|list<string>>
 * @phpstan-type ShopSearch array{query: string, config: Config, options: SearchOptions}
 */
final class ShopCatalog
{
    private const array TYPES = ['Tile', 'Paver', 'Slab', 'Brick', 'Kerb', 'Lamp', 'Cable', 'Fence'];
    private const array COLORS = ['Anthracite', 'Grey', 'Black', 'White', 'Sand', 'Terracotta'];
    private const array SIZES = ['30x30', '40x40', '60x60', '60x120', '20x30', '10x10'];
    private const array BRANDS = ['Stonewell', 'Terrano', 'Claymore', 'Lumina', 'Northgate', 'Basalto'];
    private const array FINISHES = ['smooth', 'rough', 'tumbled', 'polished'];
    private const array WORDS = [
        'ceramic', 'concrete', 'garden', 'terrace', 'driveway', 'frost', 'proof', 'durable', 'natural',
        'look', 'easy', 'clean', 'laid', 'outdoor', 'modern', 'classic', 'surface', 'delivery', 'pallet',
    ];
    /** A hidden product holds all three; a page excludes it by the first. */
    private const array HIDDEN = ['exclude-from-catalog', 'exclude-from-search', 'outofstock'];
    private const array FACETS = [
        'popularity', 'brand', 'size', 'finish', 'color', 'unit_price', 'stock_status', 'on_sale', 'tag',
    ];

    public static function schema(): SchemaConfig
    {
        $filterable = [
            'id', 'sku', 'date', 'price', 'unit_price', 'stock_status', 'on_sale', 'brand', 'size', 'finish',
            'color', 'category', 'tag', 'popularity', 'visibility',
        ];
        return new SchemaConfig(
            filterableAttributes: $filterable,
            sortableAttributes: [...$filterable, 'title_sort', 'menu_order', 'total_sales'],
            searchableAttributes: ['title', 'description'],
        );
    }

    /**
     * The same documents for the same arguments.
     *
     * @return list<ShopDoc>
     */
    public static function documents(int $count, int $seed = 1): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $chance = fn(int $percent): bool => $random->getInt(1, 100) <= $percent;
        $docs   = [];
        for ($id = 1; $id <= $count; $id++) {
            $type   = self::pick($random, self::TYPES);
            $size   = self::pick($random, self::SIZES);
            $color  = self::pick($random, self::COLORS);
            $hidden = $chance(17);
            $price  = $random->getInt(150, 25000) / 100;
            $title  = "{$type} {$size} {$color}";
            $words  = [];
            for ($i = $random->getInt(30, 80); $i > 0; $i--) {
                $words[] = self::pick($random, self::WORDS);
            }
            $doc = [
                'id'           => $id,
                'sku'          => sprintf('SK-%06d', $id),
                'title'        => $title,
                'title_sort'   => strtolower($title),
                'description'  => "{$title} & more: <" . strtolower($color) . '> ' . implode(' ', $words),
                'date'         => 1_700_000_000 + $random->getInt(0, 900) * 86_400,
                'menu_order'   => $random->getInt(0, intdiv($count, 2)),
                'total_sales'  => $chance(50) ? 0 : $random->getInt(1, 400),
                'price'        => $price,
                'stock_status' => $hidden ? 'outofstock' : 'instock',
                'on_sale'      => $chance(12) ? 'yes' : 'no',
                'brand'        => [self::pick($random, self::BRANDS)],
                'size'         => [$size],
                'finish'       => $chance(60) ? [self::pick($random, self::FINISHES)] : [],
                'color'        => array_values(array_unique([$color, self::pick($random, self::COLORS)])),
                'category'     => array_values(array_unique([
                    'all-' . strtolower($type),
                    strtolower($type) . '-' . $size,
                    strtolower($color),
                    'season-' . $random->getInt(1, 4),
                ])),
                'tag'          => $chance(20) ? ['tag-' . $random->getInt(1, 60)] : [],
                'popularity'   => $chance(5) ? ['bestseller'] : [],
                'visibility'   => $hidden ? self::HIDDEN : [],
                'card'         => "<li><a href=\"/p/{$id}\">{$title}</a></li>",
            ];
            if ($chance(55)) {
                $doc['unit_price'] = round($price / 4, 2);
            }
            $docs[] = $doc;
        }
        return $docs;
    }

    /** @param non-empty-list<string> $from */
    private static function pick(Randomizer $random, array $from): string
    {
        return $from[$random->getInt(0, count($from) - 1)];
    }

    /**
     * Every search of the site, by name.
     *
     * @param  int      $maxValuesPerFacet 0: no cap, for comparing whole distributions.
     * @param  int|null $keywordLimit      Page size of the keyword searches, to read all their hits.
     * @return array<string, ShopSearch>
     */
    public static function searches(int $maxValuesPerFacet = 401, ?int $keywordLimit = null): array
    {
        $archive  = self::config(5000, $maxValuesPerFacet);
        $slider   = self::config(500, 101);
        $menu     = ['menu_order:asc', 'title_sort:asc'];
        $category = ['category' => 'all-tile'];
        $range    = ['unit_price' => new FacetRange(gte: 5.0, lte: 20.0)];
        $sale     = ['on_sale' => 'yes'];
        $bounds   = ['price', 'unit_price'];
        $limit    = $keywordLimit ?? 24;
        $size     = ['size' => '60x60'];
        $dark     = ['color' => 'Anthracite'];
        $brands   = ['brand' => ['Lumina', 'Terrano', 'Stonewell']];
        $slider2  = ['price' => new FacetRange(gte: 10.0, lte: 100.0)];
        $bar      = self::config(500, min($maxValuesPerFacet, 101));
        $barSize  = $keywordLimit ?? 10;
        return [
            'shop'              => self::search('', $archive, self::page([], $menu)),
            'shop_page_3'       => self::search('', $archive, self::page([], $menu, offset: 48)),
            'shop_list_48'      => self::search('', $archive, self::page([], $menu, limit: 48)),
            'shop_sale'         => self::search('', $archive, self::page($sale, $menu)),
            'shop_checkbox'     => self::search('', $archive, self::page(['popularity' => 'bestseller'], $menu)),
            'shop_price_range'  => self::search('', $archive, self::page($range, $menu)),
            'shop_sort_price'   => self::search('', $archive, self::page([], ['price:asc'])),
            'shop_sort_popular' => self::search('', $archive, self::page([], ['total_sales:desc'])),
            'category'          => self::search('', $archive, self::page($category, $menu)),
            'brand'             => self::search('', $archive, self::page(['brand' => 'Lumina'], $menu)),
            'shop_bounds'       => self::search('', $archive, self::page([], $menu, limit: 1, counted: $bounds)),
            'slider_new'        => self::search('', $slider, self::page([], ['date:desc'], limit: 8, counted: [])),
            'slider_sale'       => self::search(
                '',
                $slider,
                self::page($sale, ['total_sales:desc'], limit: 8, counted: []),
            ),
            'shop_search'       => self::search('tile 60x60', $archive, self::page([], [], $limit)),
            'shop_search_typo'  => self::search('terrakotta', $archive, self::page([], [], $limit)),
            'category_search'   => self::search('anthracite', $archive, self::page($category, [], $limit)),
            // The sidebar, each click its own request.
            'cat_size'          => self::search('', $archive, self::page($category + $size, $menu)),
            'cat_size_color'    => self::search('', $archive, self::page($category + $size + $dark, $menu)),
            'cat_three'         => self::search(
                '',
                $archive,
                self::page($category + $dark + ['brand' => 'Lumina', 'on_sale' => 'no'], $menu),
            ),
            'cat_color'         => self::search('', $archive, self::page($category + $dark, $menu)),
            'cat_brand_multi'   => self::search('', $archive, self::page($category + $brands, $menu)),
            'cat_range'         => self::search('', $archive, self::page($category + $range, $menu)),
            'cat_size_range'    => self::search('', $archive, self::page($category + $size + $range, $menu)),
            'cat_sort_price'    => self::search('', $archive, self::page($category + $size, ['price:asc'])),
            'cat_deep'          => self::search('', $archive, self::page($category + $dark, $menu, offset: 96)),
            'brand_size'        => self::search('', $archive, self::page(['brand' => 'Lumina'] + $size, $menu)),
            'sale_category'     => self::search('', $archive, self::page($sale + $category, $menu)),
            'shop_color'        => self::search('', $archive, self::page($dark, $menu)),
            'shop_two'          => self::search('', $archive, self::page($dark + ['finish' => 'smooth'], $menu)),
            'shop_multi'        => self::search('', $archive, self::page($brands, $menu)),
            'shop_stock'        => self::search('', $archive, self::page(['stock_status' => 'instock'], $menu)),
            'shop_slider'       => self::search('', $archive, self::page($slider2, $menu)),
            'shop_slider_sort'  => self::search('', $archive, self::page($slider2, ['price:asc'])),
            'shop_range_filter' => self::search('', $archive, self::page($slider2 + $dark, $menu)),
            // The count next to a filter control.
            'counter'           => self::search('', $archive, self::page($category + $size, [], 1, counted: [])),
            // The search bar, a request per keystroke, and the results page.
            'bar_t'             => self::search('t', $bar, self::bar($barSize)),
            'bar_ti'            => self::search('ti', $bar, self::bar($barSize)),
            'bar_til'           => self::search('til', $bar, self::bar($barSize)),
            'bar_tile'          => self::search('tile', $bar, self::bar($barSize)),
            'bar_tile_6'        => self::search('tile 6', $bar, self::bar($barSize)),
            'bar_tile_60'       => self::search('tile 60', $bar, self::bar($barSize)),
            'bar_tile_60x'      => self::search('tile 60x', $bar, self::bar($barSize)),
            'bar_tile_60x60'    => self::search('tile 60x60', $bar, self::bar($barSize)),
            'bar_typo_two'      => self::search('terrakotta tlie', $bar, self::bar($barSize)),
            'bar_filter'        => self::search('tile 60', $bar, self::bar($barSize, ['brand' => 'Lumina'])),
            'bar_range'         => self::search('tile 60', $bar, self::bar($barSize, $slider2)),
            'bar_sort'          => self::search('tile 60', $bar, self::bar($barSize, [], ['price:asc'])),
            'search_filter'     => self::search('tile 60x60', $archive, self::page($dark, [], $limit)),
            'search_range'      => self::search('tile 60x60', $archive, self::page($slider2, [], $limit)),
            'search_sort'       => self::search('tile 60x60', $archive, self::page([], ['price:asc'], $limit)),
            'search_common'     => self::search('garden', $archive, self::page([], [], $limit)),
            'search_typo_two'   => self::search('anthrazite tlie', $archive, self::page([], [], $limit)),
            'search_no_hits'    => self::search('zzzqqq', $archive, self::page([], [], $limit)),
        ];
    }

    private static function config(int $maxDocs, int $maxValuesPerFacet): Config
    {
        return new Config(
            maxDocs: $maxDocs,
            maxValuesPerFacet: $maxValuesPerFacet,
            fieldBoosts: ['title' => 3.0],
            typoTolerance: new TypoTolerance(disableOnNumbers: true),
            maxTotalHits: 10100,
            maxFacetCountDocs: 50000,
        );
    }

    /**
     * What every page sends, around its own filter, sort and paging.
     *
     * @param array<string, FacetRange|list<string>|string> $filter
     * @param list<string>                                  $sort
     * @param list<string>|null                             $counted  Facets, when not the page's usual ones.
     * @param list<string>                                  $retrieve
     */
    private static function page(
        array $filter,
        array $sort,
        int $limit = 24,
        int $offset = 0,
        ?array $counted = null,
        string $hidden = 'exclude-from-catalog',
        array $retrieve = ['id', 'card'],
    ): SearchOptions {
        return new SearchOptions(
            asYouType: true,
            limit: $limit,
            offset: $offset,
            filter: $filter + ['visibility' => new FacetExclude([$hidden])],
            facets: $counted ?? self::FACETS,
            sortFacetValuesBy: ['*' => FacetOrder::Count],
            sort: $sort,
            matchingStrategy: MatchingStrategy::Last,
            attributesToHighlight: ['title', 'description'],
            highlightPreTag: '<mark>',
            highlightPostTag: '</mark>',
            attributesToCrop: ['description'],
            cropLength: 200,
            cropMarker: '…',
            attributesToRetrieve: $retrieve,
            escapeFormatted: true,
        );
    }

    /**
     * What the search bar sends: a short page and two facets, without what search hides.
     *
     * @param array<string, FacetRange|list<string>|string> $filter
     * @param list<string>                                  $sort
     */
    private static function bar(int $limit, array $filter = [], array $sort = []): SearchOptions
    {
        return self::page(
            $filter,
            $sort,
            $limit,
            counted: ['price', 'brand'],
            hidden: 'exclude-from-search',
            retrieve: ['id', 'title', 'price'],
        );
    }

    /** @return ShopSearch */
    private static function search(string $query, Config $config, SearchOptions $options): array
    {
        return ['query' => $query, 'config' => $config, 'options' => $options];
    }
}

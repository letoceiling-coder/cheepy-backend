<?php

namespace App\Services\SadovodParser;

use App\Services\SadovodParser\Parsers\CatalogParser;
use App\Services\SadovodParser\Parsers\MenuParser;
use App\Services\SadovodParser\Parsers\ProductParser;
use App\Services\SadovodParser\Parsers\SellerParser;

class SadovodParserService
{
    private HttpClient $http;
    private MenuParser $menuParser;
    private CatalogParser $catalogParser;
    private ProductParser $productParser;
    private SellerParser $sellerParser;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $this->loadConfig($config);
        $this->http = new HttpClient($this->config);
        $this->menuParser = new MenuParser($this->http, $this->config);
        $this->catalogParser = new CatalogParser($this->http);
        $this->productParser = new ProductParser($this->http);
        $this->sellerParser = new SellerParser($this->http);
    }

    private function loadConfig(array $overrides): array
    {
        $path = __DIR__ . '/../../config/sadovod.php';
        $defaults = [
            'base_url' => 'https://sadovodbaza.ru',
            'request_delay_ms' => 500,
            'verify_ssl' => true,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'exclude_menu_links' => ['/link/3'],
            'exclude_menu_text' => ['Женская одежда ТГ'],
        ];
        if (is_file($path)) {
            $defaults = array_merge($defaults, require $path);
        }
        if (function_exists('config') && config('sadovod')) {
            $defaults = array_merge($defaults, config('sadovod'));
        }
        return array_merge($defaults, $overrides);
    }

    /**
     * Run full parse: menu → categories → products → product details → sellers.
     *
     * @param array $options [categories_limit, products_per_category_limit, parse_product_details, parse_sellers]
     */
    public function run(array $options = []): array
    {
        $categoriesLimit = $options['categories_limit'] ?? 0;
        $productsPerCategoryLimit = $options['products_per_category_limit'] ?? 0;
        $parseProductDetails = $options['parse_product_details'] ?? true;
        $parseSellers = $options['parse_sellers'] ?? true;

        $result = [
            'meta' => [
                'source' => $this->config['base_url'],
                'parsed_at' => date('c'),
            ],
            'menu_main' => null,
            'categories' => [],
            'products_by_category' => [],
            'products' => [],
            'sellers' => [],
        ];

        $progress = $options['progress_callback'] ?? null;

        if ($progress) {
            $progress('Загрузка меню и категорий...');
        }
        $menuResult = $this->menuParser->parse();
        $result['menu_main'] = $menuResult['menu_main_html'];
        $result['categories'] = $menuResult['categories'];

        $allProductsById = [];
        $sellersMap = [];
        $categorySlugs = $this->collectAllCategorySlugs($result['categories']);
        if ($categoriesLimit > 0) {
            $categorySlugs = array_slice($categorySlugs, 0, $categoriesLimit);
        }

        $totalCats = count($categorySlugs);
        foreach ($categorySlugs as $idx => $cat) {
            if ($progress) {
                $progress(sprintf('Категория %d/%d: %s', $idx + 1, $totalCats, $cat));
            }
            $catInfo = $this->findCategoryBySlug($result['categories'], $cat);
            $catalogPath = $catInfo['url'] ?? '/catalog/' . $cat;
            $limit = $productsPerCategoryLimit > 0 ? $productsPerCategoryLimit : 0;
            $data = $this->catalogParser->parseCategory($catalogPath, $limit, 10);
            $products = $data['products'];
            if ($productsPerCategoryLimit > 0) {
                $products = array_slice($products, 0, $productsPerCategoryLimit);
            }
            $result['products_by_category'][$cat] = [
                'category' => $catInfo,
                'subcategories' => $data['subcategories'],
                'product_urls' => array_map(fn ($p) => $p['path'] ?? $p['url'], $products),
            ];
            foreach ($products as $p) {
                $id = $p['id'] ?? null;
                if ($id) {
                    if (!isset($allProductsById[$id])) {
                        $allProductsById[$id] = array_merge($p, ['category_slugs' => []]);
                    }
                    $allProductsById[$id]['category_slugs'][] = $cat;
                }
            }
        }

        $productList = array_values($allProductsById);
        if ($progress) {
            $progress(sprintf('Товаров в списке: %d. Загрузка деталей...', count($productList)));
        }
        [$result['products'], $sellersMap] = $this->enrichProducts(
            $productList,
            $parseProductDetails,
            $parseSellers,
            $progress
        );
        $result['sellers'] = array_values($sellersMap);

        return $this->sortResult($result);
    }

    private function collectAllCategorySlugs(array $categories): array
    {
        $slugs = [];
        foreach ($categories as $cat) {
            $slugs[] = $cat['slug'] ?? '';
            foreach ($cat['children'] ?? [] as $ch) {
                $slugs[] = $ch['slug'] ?? '';
            }
        }
        return array_values(array_unique(array_filter($slugs)));
    }

    private function findCategoryBySlug(array $categories, string $slug): array
    {
        foreach ($categories as $c) {
            if (($c['slug'] ?? '') === $slug) {
                return $c;
            }
            foreach ($c['children'] ?? [] as $ch) {
                if (($ch['slug'] ?? '') === $slug) {
                    return $ch;
                }
            }
        }
        return ['title' => $slug, 'url' => '/catalog/' . $slug, 'slug' => $slug];
    }

    /**
     * @param callable|null $progress fn(string $msg): void
     * @return array{0: array, 1: array<string, array>}
     */
    private function enrichProducts(array $products, bool $parseDetails, bool $parseSellers, ?callable $progress = null): array
    {
        $enriched = [];
        $sellersCollector = [];
        $total = count($products);

        foreach ($products as $i => $p) {
            if ($progress && $parseDetails && (($i + 1) % 5 === 0 || $i === 0)) {
                $progress(sprintf('  Товар %d/%d', $i + 1, $total));
            }
            $path = $p['path'] ?? $p['url'] ?? '';
            if (!str_contains($path, '/odejda/')) {
                $path = '/odejda/' . ($p['id'] ?? '');
            }
            $path = parse_url($path, PHP_URL_PATH) ?: $path;

            $item = [
                'id' => $p['id'] ?? null,
                'url' => $this->http->getAbsoluteUrl($path),
                'title' => $p['title'] ?? '',
                'price' => $p['price'] ?? '',
                'category_slugs' => $p['category_slugs'] ?? [],
            ];

            if ($parseDetails) {
                try {
                    $detail = $this->productParser->parse($path);
                    $item['photos'] = $detail['photos'];
                    $item['characteristics'] = $detail['characteristics'];
                    $item['description'] = $detail['description'];
                    $item['category'] = $detail['category'];
                    $item['seller'] = $detail['seller'];

                    if ($parseSellers && !empty($detail['seller']['slug']) && !isset($sellersCollector[$detail['seller']['slug']])) {
                        try {
                            $sellerData = $this->sellerParser->parse('/s/' . $detail['seller']['slug']);
                            $sellerData['contacts'] = $sellerData['contacts'] ?? [];
                            $sellersCollector[$detail['seller']['slug']] = $sellerData;
                        } catch (\Throwable $e) {
                            $sellersCollector[$detail['seller']['slug']] = array_merge($detail['seller'], ['contacts' => [], 'products' => []]);
                        }
                    }
                } catch (\Throwable $e) {
                    $item['error'] = $e->getMessage();
                }
            }

            $enriched[] = $item;
        }

        return [$enriched, $sellersCollector];
    }

    private function sortResult(array $result): array
    {
        usort($result['products'], function ($a, $b) {
            $c = strcmp(implode(',', $a['category_slugs'] ?? []), implode(',', $b['category_slugs'] ?? []));
            if ($c !== 0) {
                return $c;
            }
            $sellerA = $a['seller']['name'] ?? '';
            $sellerB = $b['seller']['name'] ?? '';
            return strcmp($sellerA, $sellerB);
        });
        if (!empty($result['sellers'])) {
            usort($result['sellers'], fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        }
        return $result;
    }

    /**
     * Parse only menu and categories (no products).
     */
    public function parseMenu(): array
    {
        return $this->menuParser->parse();
    }

    /**
     * Parse one category and return product list (no detail parsing).
     */
    public function parseCategory(string $catalogPath): array
    {
        return $this->catalogParser->parseCategory($catalogPath);
    }

    /**
     * Parse one product page.
     */
    public function parseProduct(string $path): array
    {
        return $this->productParser->parse($path);
    }

    /**
     * Parse one seller page.
     */
    public function parseSeller(string $path): array
    {
        return $this->sellerParser->parse($path);
    }
}

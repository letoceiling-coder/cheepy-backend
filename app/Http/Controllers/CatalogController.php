<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\SadovodParser\HttpClient;
use App\Services\SadovodParser\Parsers\CatalogParser;
use App\Services\SadovodParser\Parsers\ProductParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    /**
     * Главная страница / каталог категории — live с донора + из БД
     */
    public function index(Request $request)
    {
        $categorySlug = $request->input('category', '');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 24;

        // Меню категорий: сначала из БД, fallback — живой парсинг
        $menuCategories = $this->getMenuCategories();

        // Определить название текущей категории
        $currentCategoryTitle = 'Все товары';
        $currentCategory = null;
        if ($categorySlug !== '') {
            $currentCategory = $this->findCategoryBySlug($menuCategories, $categorySlug);
            $currentCategoryTitle = $currentCategory['name'] ?? $currentCategory['title'] ?? $categorySlug;
        }

        // Товары: сначала из БД, если нет — онлайн с донора
        $productsData = $this->getProducts($categorySlug, $page, $perPage);

        return view('catalog.index', [
            'categories'           => $menuCategories,
            'categorySlug'         => $categorySlug,
            'currentCategoryTitle' => $currentCategoryTitle,
            'productsData'         => $productsData,
            'page'                 => $page,
        ]);
    }

    /**
     * Страница товара: сначала из БД, fallback — онлайн
     */
    public function product(Request $request, string $externalId)
    {
        // Из БД
        $product = Product::where('external_id', $externalId)
            ->with(['seller', 'category', 'photoRecords' => fn($q) => $q->orderBy('sort_order')])
            ->first();

        if ($product) {
            $data = $this->formatProductForView($product);
        } else {
            // Онлайн с донора
            $data = $this->fetchProductOnline($externalId);
        }

        if (!$data) {
            return redirect('/')->with('error', 'Товар не найден');
        }

        return view('catalog.product', [
            'product' => $data,
            'categories' => $this->getMenuCategories(),
        ]);
    }

    // -------------------------------------------------------

    private function getMenuCategories(): array
    {
        // Кеш на 1 час
        return Cache::remember('menu_categories', 3600, function () {
            $dbCategories = Category::where('enabled', true)
                ->whereNull('parent_id')
                ->orderBy('sort_order')
                ->with(['children' => fn($q) => $q->where('enabled', true)->orderBy('sort_order')
                    ->with(['children' => fn($q2) => $q2->where('enabled', true)->orderBy('sort_order')])])
                ->get();

            if ($dbCategories->isNotEmpty()) {
                return $dbCategories->map(fn($c) => [
                    'title'    => $c->name,
                    'slug'     => $c->slug,
                    'url'      => '/catalog/' . $c->slug,
                    'children' => $c->children->map(fn($ch) => [
                        'title'    => $ch->name,
                        'slug'     => $ch->slug,
                        'url'      => '/catalog/' . $ch->slug,
                        'children' => $ch->children->map(fn($sub) => [
                            'title' => $sub->name, 'slug' => $sub->slug,
                        ])->toArray(),
                    ])->toArray(),
                ])->toArray();
            }

            // Fallback: онлайн меню
            try {
                $http = new HttpClient(config('sadovod'));
                $parser = new \App\Services\SadovodParser\Parsers\MenuParser($http, config('sadovod'));
                $result = $parser->parse();
                return $result['categories'] ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    private function getProducts(string $categorySlug, int $page, int $perPage): array
    {
        // Сначала из БД
        if ($categorySlug !== '') {
            $category = Category::where('external_slug', $categorySlug)
                ->orWhere('slug', $categorySlug)
                ->first();

            if ($category) {
                $dbQuery = Product::where('category_id', $category->id)
                    ->where('status', 'active')
                    ->orderBy('parsed_at', 'desc');

                $total = $dbQuery->count();
                if ($total > 0) {
                    $items = $dbQuery->skip(($page - 1) * $perPage)->take($perPage)->get();
                    return [
                        'items'       => $items->map(fn($p) => $this->formatProductForView($p))->toArray(),
                        'total'       => $total,
                        'page'        => $page,
                        'per_page'    => $perPage,
                        'total_pages' => (int) ceil($total / $perPage),
                        'source'      => 'db',
                    ];
                }
            }

            // Fallback: онлайн
            return $this->fetchCategoryOnline($categorySlug, $page, $perPage);
        }

        // Все товары из БД
        $total = Product::where('status', 'active')->count();
        $items = Product::where('status', 'active')
            ->orderBy('parsed_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'items'       => $items->map(fn($p) => $this->formatProductForView($p))->toArray(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'source'      => 'db',
        ];
    }

    private function fetchCategoryOnline(string $slug, int $page, int $perPage): array
    {
        $cacheKey = "cat_online_{$slug}_{$page}";
        return Cache::remember($cacheKey, 600, function () use ($slug, $page, $perPage) {
            try {
                $http = new HttpClient(config('sadovod'));
                $parser = new CatalogParser($http);
                $result = $parser->parseCategory('/catalog/' . $slug, $page - 1, $perPage);
                $products = $result['products'] ?? [];

                return [
                    'items'       => $products,
                    'total'       => count($products) > 0 ? ($result['total'] ?? count($products)) : 0,
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total_pages' => $result['total_pages'] ?? 1,
                    'source'      => 'live',
                ];
            } catch (\Throwable $e) {
                return [
                    'items' => [], 'total' => 0, 'page' => $page,
                    'per_page' => $perPage, 'total_pages' => 1,
                    'error' => $e->getMessage(), 'source' => 'error',
                ];
            }
        });
    }

    private function fetchProductOnline(string $externalId): ?array
    {
        $cacheKey = "product_online_{$externalId}";
        return Cache::remember($cacheKey, 900, function () use ($externalId) {
            try {
                $http = new HttpClient(config('sadovod'));
                $parser = new ProductParser($http);
                $data = $parser->parse('/odejda/' . $externalId);
                $data['id'] = $externalId;
                return $data;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    private function formatProductForView(Product $p): array
    {
        $photos = is_array($p->photos) ? $p->photos : (json_decode($p->photos ?? '[]', true) ?? []);

        // Предпочесть локальное фото если скачано
        $primaryPhoto = null;
        if ($p->relationLoaded('photoRecords') && $p->photoRecords->isNotEmpty()) {
            $ph = $p->photoRecords->first();
            if ($ph->local_path && file_exists(storage_path('app/' . $ph->local_path))) {
                $primaryPhoto = url('storage/' . $ph->local_path);
            } else {
                $primaryPhoto = $ph->original_url;
            }
        }
        if (!$primaryPhoto && !empty($photos)) {
            $primaryPhoto = $photos[0];
        }

        return [
            'id'          => $p->external_id,
            'title'       => $p->title,
            'price'       => $p->price,
            'photos'      => $photos,
            'photo'       => $primaryPhoto,
            'description' => $p->description,
            'characteristics' => $p->characteristics ?? [],
            'category'    => $p->category ? ['title' => $p->category->name, 'url' => '/catalog/' . $p->category->slug] : [],
            'seller'      => $p->seller ? [
                'name'     => $p->seller->name,
                'pavilion' => $p->seller->pavilion,
                'phone'    => $p->seller->phone,
                'whatsapp' => $p->seller->whatsapp_number,
            ] : [],
            'category_slugs' => is_array($p->category_slugs) ? $p->category_slugs : [],
        ];
    }

    private function findCategoryBySlug(array $categories, string $slug): ?array
    {
        foreach ($categories as $cat) {
            if (($cat['slug'] ?? '') === $slug) return $cat;
            foreach ($cat['children'] ?? [] as $ch) {
                if (($ch['slug'] ?? '') === $slug) return $ch;
                foreach ($ch['children'] ?? [] as $sub) {
                    if (($sub['slug'] ?? '') === $slug) return $sub;
                }
            }
        }
        return null;
    }
}

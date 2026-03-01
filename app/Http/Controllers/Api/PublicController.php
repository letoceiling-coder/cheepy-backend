<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Seller;
use App\Models\FilterConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Публичное API для пользовательских страниц Cheepy
 */
class PublicController extends Controller
{
    /**
     * GET /api/v1/public/menu
     * Иерархия категорий для навигации
     */
    public function menu(): JsonResponse
    {
        $categories = Category::where('enabled', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->with([
                'children' => fn($q) => $q->where('enabled', true)->orderBy('sort_order')
                    ->with(['children' => fn($q2) => $q2->where('enabled', true)->orderBy('sort_order')])
            ])
            ->get(['id', 'name', 'slug', 'icon', 'parent_id', 'sort_order', 'products_count']);

        return response()->json(['categories' => $categories]);
    }

    /**
     * GET /api/v1/public/categories/{slug}/products
     * Товары категории с фильтрацией и пагинацией
     */
    public function categoryProducts(Request $request, string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->where('enabled', true)
            ->firstOrFail();

        $query = Product::where('category_id', $category->id)
            ->where('status', 'active')
            ->where('is_relevant', true);

        // Фильтры из запроса (?color=красный&size=M&price_from=500)
        foreach ($request->all() as $key => $value) {
            if (in_array($key, ['page', 'per_page', 'sort_by', 'sort_dir', 'price_from', 'price_to', 'search'])) continue;
            if ($value === '' || $value === null) continue;
            // Фильтр по атрибуту через подзапрос
            $query->whereHas('attributes', function ($q) use ($key, $value) {
                $q->where('attr_name', $key)->where('attr_value', $value);
            });
        }

        if ($priceFrom = $request->input('price_from')) {
            $query->where('price_raw', '>=', (int) $priceFrom);
        }
        if ($priceTo = $request->input('price_to')) {
            $query->where('price_raw', '<=', (int) $priceTo);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortMap = ['price_asc' => ['price_raw', 'asc'], 'price_desc' => ['price_raw', 'desc'], 'new' => ['parsed_at', 'desc']];
        [$sortCol, $sortDir] = $sortMap[$request->input('sort_by', 'new')] ?? ['parsed_at', 'desc'];
        $query->orderBy($sortCol, $sortDir);

        $perPage = min((int) $request->input('per_page', 24), 60);
        $products = $query->with('seller:id,name,slug,pavilion')->paginate($perPage);

        // Доступные фильтры для этой категории
        $filters = $this->getCategoryFiltersWithValues($category->id);

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'filters' => $filters,
            'data' => $products->map(fn($p) => $this->formatPublicProduct($p)),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/public/products/{externalId}
     * Детальная карточка товара
     */
    public function product(string $externalId): JsonResponse
    {
        $product = Product::where('external_id', $externalId)
            ->where('status', 'active')
            ->with([
                'category:id,name,slug',
                'seller',
                'brand:id,name,slug,logo_url',
                'photoRecords' => fn($q) => $q->orderBy('sort_order'),
                'attributes',
            ])
            ->firstOrFail();

        // Другие товары этого продавца
        $sellerProducts = [];
        if ($product->seller_id) {
            $sellerProducts = Product::where('seller_id', $product->seller_id)
                ->where('id', '!=', $product->id)
                ->where('status', 'active')
                ->select(['id', 'external_id', 'title', 'price', 'photos'])
                ->limit(12)
                ->get()
                ->map(fn($p) => $this->formatPublicProduct($p));
        }

        return response()->json([
            'product' => $this->formatPublicProductFull($product),
            'seller_products' => $sellerProducts,
        ]);
    }

    /**
     * GET /api/v1/public/sellers/{slug}
     * Страница продавца
     */
    public function seller(Request $request, string $slug): JsonResponse
    {
        $seller = Seller::where('slug', $slug)->where('status', 'active')->firstOrFail();

        $products = Product::where('seller_id', $seller->id)
            ->where('status', 'active')
            ->paginate($request->input('per_page', 24));

        return response()->json([
            'seller' => [
                'id' => $seller->id,
                'name' => $seller->name,
                'slug' => $seller->slug,
                'pavilion' => $seller->pavilion,
                'pavilion_line' => $seller->pavilion_line,
                'pavilion_number' => $seller->pavilion_number,
                'description' => $seller->description,
                'contacts' => [
                    'phone' => $seller->phone,
                    'whatsapp_number' => $seller->whatsapp_number,
                    'whatsapp_url' => $seller->whatsapp_url,
                    'telegram_url' => $seller->telegram_url,
                    'vk_url' => $seller->vk_url,
                ],
                'seller_categories' => $seller->seller_categories ?? [],
                'products_count' => $seller->products_count,
                'source_url' => $seller->source_url,
            ],
            'data' => $products->map(fn($p) => $this->formatPublicProduct($p)),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/public/search?q=платье&page=1
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $products = Product::where('status', 'active')
            ->where(function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            })
            ->with(['category:id,name,slug', 'seller:id,name,slug'])
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'query' => $q,
            'data' => $products->map(fn($p) => $this->formatPublicProduct($p)),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/public/featured
     * Рекомендуемые товары для главной
     */
    public function featured(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 24), 60);
        $products = Product::where('status', 'active')
            ->where('is_relevant', true)
            ->where('photos_count', '>', 0)
            ->inRandomOrder()
            ->limit($limit)
            ->with('seller:id,name,slug')
            ->get();

        return response()->json(['data' => $products->map(fn($p) => $this->formatPublicProduct($p))]);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function getCategoryFiltersWithValues(int $categoryId): array
    {
        $configs = FilterConfig::where('category_id', $categoryId)
            ->where('is_active', true)
            ->where('is_filterable', true)
            ->orderBy('sort_order')
            ->get();

        $result = [];
        foreach ($configs as $cfg) {
            $entry = [
                'attr_name' => $cfg->attr_name,
                'display_name' => $cfg->display_name,
                'display_type' => $cfg->display_type,
            ];

            if ($cfg->display_type === 'range') {
                $entry['min'] = $cfg->range_min;
                $entry['max'] = $cfg->range_max;
            } else {
                $values = ProductAttribute::where('category_id', $categoryId)
                    ->where('attr_name', $cfg->attr_name)
                    ->distinct()
                    ->pluck('attr_value')
                    ->sort()
                    ->values()
                    ->toArray();
                $entry['values'] = $cfg->preset_values ?? $values;
            }

            $result[] = $entry;
        }

        return $result;
    }

    private function formatPublicProduct(Product $p): array
    {
        $photos = is_array($p->photos) ? $p->photos : [];
        return [
            'id' => $p->external_id,
            'title' => $p->title,
            'price' => $p->price,
            'thumbnail' => $photos[0] ?? null,
            'photos_count' => $p->photos_count,
            'category' => $p->category ? ['name' => $p->category->name, 'slug' => $p->category->slug] : null,
            'seller' => $p->seller ? ['name' => $p->seller->name, 'slug' => $p->seller->slug] : null,
        ];
    }

    private function formatPublicProductFull(Product $p): array
    {
        return [
            'id' => $p->external_id,
            'title' => $p->title,
            'price' => $p->price,
            'description' => $p->description,
            'photos' => $p->photos ?? [],
            'photos_detail' => ($p->relationLoaded('photoRecords') ? $p->photoRecords : collect())->map(fn($ph) => [
                'original_url' => $ph->original_url,
                'local_path' => $ph->local_path,
                'is_primary' => $ph->is_primary,
            ]),
            'characteristics' => $p->characteristics ?? [],
            'color' => $p->color,
            'size_range' => $p->size_range,
            'source_link' => $p->source_link,
            'source_url' => $p->source_url,
            'attributes' => $p->attributes->map(fn($a) => [
                'name' => $a->attr_name,
                'value' => $a->attr_value,
            ]),
            'category' => $p->category?->only(['id', 'name', 'slug']),
            'seller' => $p->seller ? [
                'name' => $p->seller->name,
                'slug' => $p->seller->slug,
                'pavilion' => $p->seller->pavilion,
                'pavilion_line' => $p->seller->pavilion_line,
                'pavilion_number' => $p->seller->pavilion_number,
                'phone' => $p->seller->phone,
                'whatsapp_number' => $p->seller->whatsapp_number,
                'whatsapp_url' => $p->seller->whatsapp_url,
            ] : null,
            'brand' => $p->brand ? [
                'name' => $p->brand->name,
                'slug' => $p->brand->slug,
                'logo_url' => $p->brand->logo_url,
            ] : null,
        ];
    }
}

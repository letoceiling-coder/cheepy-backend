<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\ProductSource;
use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Публичный каталог на слое system_products (опубликованные карточки CRM).
 * Включается env PUBLIC_CATALOG_USE_SYSTEM_PRODUCTS=true.
 */
class PublicSystemCatalogService
{
    public function menu(): JsonResponse
    {
        $countPublished = fn ($q) => $q->where('status', SystemProduct::STATUS_PUBLISHED);

        $categories = CatalogCategory::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->withCount(['systemProducts as products_count' => $countPublished])
            ->with([
                'children' => fn ($q) => $q->where('is_active', true)
                    ->orderBy('sort_order')
                    ->withCount(['systemProducts as products_count' => $countPublished])
                    ->with([
                        'children' => fn ($q2) => $q2->where('is_active', true)
                            ->orderBy('sort_order')
                            ->withCount(['systemProducts as products_count' => $countPublished]),
                    ]),
            ])
            ->get(['id', 'name', 'slug', 'icon', 'parent_id', 'sort_order', 'products_count']);

        return response()->json(['categories' => $categories]);
    }

    public function categoryProducts(Request $request, string $slug): JsonResponse
    {
        $category = CatalogCategory::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $query = SystemProduct::query()
            ->published()
            ->where('category_id', $category->id)
            ->with([
                'seller:id,name,slug,pavilion',
                'photos' => $this->enabledPhotos(),
                'category:id,name,slug',
                'productSources.donorProduct:id,external_id',
            ]);

        foreach ($request->all() as $key => $value) {
            if (in_array($key, ['page', 'per_page', 'sort_by', 'sort_dir', 'price_from', 'price_to', 'search'], true)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
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
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortMap = [
            'price_asc' => ['price_raw', 'asc'],
            'price_desc' => ['price_raw', 'desc'],
            'new' => ['created_at', 'desc'],
            /** Ручной порядок из CRM (list_position): меньше — выше в списке. */
            'position' => ['list_position', 'asc'],
        ];
        [$sortCol, $sortDir] = $sortMap[$request->input('sort_by', 'new')] ?? ['created_at', 'desc'];
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = min((int) $request->input('per_page', 24), 60);
        $page = $query->paginate($perPage);

        $filters = $this->buildSystemFiltersForCategory((int) $category->id);

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'filters' => $filters,
            'data' => $page->getCollection()->map(fn (SystemProduct $sp) => $this->formatSystemProductCard($sp))->values(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function product(string $externalId): JsonResponse
    {
        $sp = $this->findPublishedSystemProductByPublicId($externalId);

        $sp->load([
            'category:id,name,slug',
            'seller',
            'brand:id,name,slug,logo_url',
            'attributes',
            'photos' => $this->enabledPhotos(),
            'productSources.donorProduct:id,external_id,title,source_url',
        ]);

        $sellerProducts = [];
        if ($sp->seller_id) {
            $sellerProducts = SystemProduct::query()
                ->published()
                ->where('seller_id', $sp->seller_id)
                ->where('id', '!=', $sp->id)
                ->with(['photos' => $this->enabledPhotos(), 'productSources.donorProduct:id,external_id'])
                ->limit(12)
                ->get()
                ->map(fn (SystemProduct $p) => $this->formatSystemProductCard($p));
        }

        return response()->json([
            'product' => $this->formatSystemProductFull($sp),
            'seller_products' => $sellerProducts,
        ]);
    }

    public function seller(Request $request, string $slug): JsonResponse
    {
        $seller = \App\Models\Seller::where('slug', $slug)->where('status', 'active')->firstOrFail();

        $products = SystemProduct::query()
            ->published()
            ->where('seller_id', $seller->id)
            ->with(['photos' => fn ($q) => $q->orderBy('sort_order'), 'productSources.donorProduct:id,external_id'])
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
            'data' => $products->getCollection()->map(fn (SystemProduct $sp) => $this->formatSystemProductCard($sp))->values(),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $products = SystemProduct::query()
            ->published()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            })
            ->with(['category:id,name,slug', 'seller:id,name,slug', 'photos' => $this->enabledPhotos(), 'productSources.donorProduct:id,external_id'])
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'query' => $q,
            'data' => $products->getCollection()->map(fn (SystemProduct $sp) => $this->formatSystemProductCard($sp))->values(),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function featured(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 24), 60);
        $products = SystemProduct::query()
            ->published()
            ->whereHas('photos', fn ($q) => $q->where('is_enabled', true))
            ->inRandomOrder()
            ->limit($limit)
            ->with(['seller:id,name,slug', 'photos' => $this->enabledPhotos(), 'productSources.donorProduct:id,external_id'])
            ->get();

        return response()->json(['data' => $products->map(fn (SystemProduct $sp) => $this->formatSystemProductCard($sp))]);
    }

    private function findPublishedSystemProductByPublicId(string $externalId): SystemProduct
    {
        if (str_starts_with($externalId, 'sp-')) {
            $id = (int) substr($externalId, 3);
            if ($id > 0) {
                return SystemProduct::query()
                    ->published()
                    ->whereKey($id)
                    ->firstOrFail();
            }
        }

        return SystemProduct::query()
            ->published()
            ->whereHas('productSources', function ($q) use ($externalId) {
                $q->where('source', ProductSource::SOURCE_PARSER)
                    ->whereHas('donorProduct', fn ($q2) => $q2->where('external_id', $externalId));
            })
            ->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSystemFiltersForCategory(int $catalogCategoryId): array
    {
        $ids = SystemProduct::query()
            ->published()
            ->where('category_id', $catalogCategoryId)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $names = SystemProductAttribute::query()
            ->whereIn('system_product_id', $ids)
            ->select('attr_name')
            ->distinct()
            ->pluck('attr_name');

        $out = [];
        foreach ($names as $name) {
            $values = SystemProductAttribute::query()
                ->whereIn('system_product_id', $ids)
                ->where('attr_name', $name)
                ->distinct()
                ->pluck('attr_value')
                ->sort()
                ->values()
                ->all();
            $out[] = [
                'attr_name' => $name,
                'display_name' => $name,
                'display_type' => 'select',
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * @return \Closure(\Illuminate\Database\Eloquent\Relations\Relation): void
     */
    private function enabledPhotos(): \Closure
    {
        return fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order');
    }

    private function publicId(SystemProduct $sp): string
    {
        $sp->loadMissing('productSources.donorProduct:id,external_id');
        $donor = $sp->productSources->first()?->donorProduct;
        if ($donor && $donor->external_id !== null && $donor->external_id !== '') {
            return (string) $donor->external_id;
        }

        return 'sp-'.$sp->id;
    }

    private function formatSystemProductCard(SystemProduct $sp): array
    {
        $sp->loadMissing(['photos', 'category:id,name,slug', 'seller:id,name,slug', 'productSources.donorProduct:id,external_id']);
        $urls = $sp->photos->pluck('url')->filter()->values()->all();
        $thumb = $urls[0] ?? null;

        return [
            'id' => $this->publicId($sp),
            'title' => $sp->name,
            'price' => $sp->price,
            'thumbnail' => $thumb,
            'photos_count' => count($urls),
            'list_position' => (int) ($sp->list_position ?? 0),
            'category' => $sp->category ? ['name' => $sp->category->name, 'slug' => $sp->category->slug] : null,
            'seller' => $sp->seller ? ['name' => $sp->seller->name, 'slug' => $sp->seller->slug] : null,
        ];
    }

    private function formatSystemProductFull(SystemProduct $sp): array
    {
        $urls = $sp->photos->map(fn ($p) => $p->url)->filter()->values()->all();

        return [
            'id' => $this->publicId($sp),
            'title' => $sp->name,
            'price' => $sp->price,
            'list_position' => (int) ($sp->list_position ?? 0),
            'description' => $sp->description,
            'photos' => $urls,
            'photos_detail' => $sp->photos->map(fn ($ph) => [
                'original_url' => $ph->url,
                'local_path' => null,
                'is_primary' => (bool) $ph->is_primary,
            ]),
            'characteristics' => [],
            'color' => null,
            'size_range' => null,
            'source_link' => null,
            'source_url' => $sp->productSources->first()?->donorProduct?->source_url,
            'attributes' => $sp->attributes->map(fn ($a) => [
                'name' => $a->attr_name,
                'value' => $a->attr_value,
            ]),
            'category' => $sp->category?->only(['id', 'name', 'slug']),
            'seller' => $sp->seller ? [
                'name' => $sp->seller->name,
                'slug' => $sp->seller->slug,
                'pavilion' => $sp->seller->pavilion,
                'pavilion_line' => $sp->seller->pavilion_line,
                'pavilion_number' => $sp->seller->pavilion_number,
                'phone' => $sp->seller->phone,
                'whatsapp_number' => $sp->seller->whatsapp_number,
                'whatsapp_url' => $sp->seller->whatsapp_url,
            ] : null,
            'brand' => $sp->brand ? [
                'name' => $sp->brand->name,
                'slug' => $sp->brand->slug,
                'logo_url' => $sp->brand->logo_url,
            ] : null,
        ];
    }
}

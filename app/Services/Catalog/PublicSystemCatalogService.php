<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Http\Resources\PublicSystemProductStorefrontCardResource;
use App\Models\ProductSource;
use App\Models\Seller;
use App\Models\SellerReview;
use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use App\Services\CatalogAttributeNormalizer;
use App\Services\MarketplaceSettingsService;
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
        $countVisible = fn ($q) => $q->whereIn('status', $this->visibleStatuses());

        $categories = CatalogCategory::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->withCount(['systemProducts as products_count' => $countVisible])
            ->with([
                'children' => fn ($q) => $q->where('is_active', true)
                    ->orderBy('sort_order')
                    ->withCount(['systemProducts as products_count' => $countVisible])
                    ->with([
                        'children' => fn ($q2) => $q2->where('is_active', true)
                            ->orderBy('sort_order')
                            ->withCount(['systemProducts as products_count' => $countVisible]),
                    ]),
            ])
            ->get(['id', 'name', 'slug', 'icon', 'parent_id', 'sort_order', 'products_count']);

        $normalized = $this->filterMenuTree($categories->toArray(), 1);

        return response()->json(['categories' => array_values($normalized)]);
    }

    /**
     * GET /api/v1/public/categories/by-ids?ids=1,2,3
     * Возвращает плоский список категорий по указанным ID без фильтрации по products_count.
     * Нужен для блоков конструктора, где пользователь явно выбрал категории (показываем все выбранные).
     */
    public function categoriesByIds(Request $request): JsonResponse
    {
        $raw = (string) $request->query('ids', '');
        $ids = collect(preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($x) => (int) $x)
            ->filter(fn ($x) => $x > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $countVisible = fn ($q) => $q->whereIn('status', $this->visibleStatuses());

        $items = CatalogCategory::query()
            ->whereIn('id', $ids->all())
            ->withCount(['systemProducts as products_count' => $countVisible])
            ->get(['id', 'name', 'slug', 'icon', 'parent_id', 'sort_order', 'is_active']);

        $byId = $items->keyBy('id');
        $ordered = $ids
            ->map(fn ($id) => $byId->get($id))
            ->filter()
            ->values()
            ->map(function (CatalogCategory $c) {
                return [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'slug' => (string) $c->slug,
                    'icon' => $c->icon,
                    'parent_id' => $c->parent_id === null ? null : (int) $c->parent_id,
                    'sort_order' => (int) ($c->sort_order ?? 0),
                    'is_active' => (bool) $c->is_active,
                    'products_count' => (int) ($c->products_count ?? 0),
                ];
            });

        return response()->json(['data' => $ordered]);
    }

    public function categoryProducts(Request $request, string $slug): JsonResponse
    {
        $category = CatalogCategory::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $categoryIds = $this->collectActiveDescendantCategoryIds((int) $category->id);

        $query = SystemProduct::query()
            ->whereIn('status', $this->visibleStatuses())
            ->whereIn('category_id', $categoryIds)
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
                $q->where(function ($inner) use ($key) {
                    $inner->where('attribute_key', $key)->orWhere('attr_name', $key);
                })->where('attr_value', $value);
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

        $filters = $this->buildSystemFiltersForCategoryIds($categoryIds);

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'filters' => $filters,
            'data' => $page->getCollection()->map(fn (SystemProduct $sp) => $this->storefrontCard($sp))->values(),
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
        $sp = $this->findVisibleSystemProductByPublicId($externalId);

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
                ->whereIn('status', $this->visibleStatuses())
                ->where('seller_id', $sp->seller_id)
                ->where('id', '!=', $sp->id)
                ->with(['photos' => $this->enabledPhotos(), 'productSources.donorProduct:id,external_id'])
                ->limit(12)
                ->get()
                ->map(fn (SystemProduct $p) => $this->storefrontCard($p));
        }

        return response()->json([
            'product' => $this->formatSystemProductFull($sp),
            'seller_products' => $sellerProducts,
        ]);
    }

    public function seller(Request $request, string $slug): JsonResponse
    {
        $seller = \App\Models\Seller::where('slug', $slug)->where('status', 'active')->firstOrFail();

        $stats = SellerReview::query()
            ->where('seller_id', $seller->id)
            ->where('is_published', true)
            ->selectRaw('COUNT(*) as c, AVG(rating) as avg_r, SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as pos')
            ->first();

        $reviewCount = (int) ($stats->c ?? 0);
        $avgRating = $stats->avg_r !== null ? round((float) $stats->avg_r, 2) : null;
        $positiveCount = (int) ($stats->pos ?? 0);
        $positivePercent = $reviewCount > 0 ? (int) round(100 * $positiveCount / $reviewCount) : null;

        $query = SystemProduct::query()
            ->whereIn('status', $this->visibleStatuses())
            ->where('seller_id', $seller->id)
            ->with(['photos' => fn ($q) => $q->orderBy('sort_order'), 'productSources.donorProduct:id,external_id']);

        $sortBy = $request->input('sort_by', 'popular');
        switch ($sortBy) {
            case 'price_asc':
                $query->orderByRaw('COALESCE(price_raw, 4294967295) asc')->orderBy('id');
                break;
            case 'price_desc':
                $query->orderByRaw('COALESCE(price_raw, 0) desc')->orderBy('id');
                break;
            case 'new':
                $query->orderByDesc('created_at')->orderByDesc('id');
                break;
            case 'rating':
                $query->orderByDesc('list_position')->orderByDesc('id');
                break;
            case 'popular':
            default:
                $query->orderByDesc('list_position')->orderByDesc('id');
                break;
        }

        $perPage = min((int) $request->input('per_page', 24), 60);
        $products = $query->paginate($perPage);

        return response()->json([
            'seller' => [
                'id' => $seller->id,
                'name' => $seller->name,
                'slug' => $seller->slug,
                'avatar_url' => $seller->avatar_url,
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
                'created_at' => $seller->created_at?->toIso8601String(),
                'reviews_summary' => [
                    'count' => $reviewCount,
                    'avg_rating' => $avgRating,
                    'positive_percent' => $positivePercent,
                ],
            ],
            'data' => $products->getCollection()->map(fn (SystemProduct $sp) => $this->storefrontCard($sp))->values(),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/public/sellers
     * Продавцы с хотя бы одной видимой карточкой system_products.
     */
    public function sellers(Request $request): JsonResponse
    {
        $visible = $this->visibleStatuses();
        $sortBy = $request->input('sort_by', 'products_desc');

        $query = Seller::query()
            ->where('status', 'active')
            ->whereHas('systemProducts', fn ($q) => $q->whereIn('status', $visible))
            ->withCount([
                'systemProducts as visible_products_count' => fn ($q) => $q->whereIn('status', $visible),
                'sellerReviews as reviews_count' => fn ($q) => $q->where('is_published', true),
                'sellerReviews as positive_reviews_count' => fn ($q) => $q->where('is_published', true)->where('rating', '>=', 4),
            ])
            ->withAvg([
                'sellerReviews as reviews_avg_rating' => fn ($q) => $q->where('is_published', true),
            ], 'rating');

        switch ($sortBy) {
            case 'name_asc':
                $query->orderBy('name')->orderBy('sellers.id');
                break;
            case 'reviews_desc':
                $query->orderByDesc('reviews_count')->orderByDesc('reviews_avg_rating')->orderBy('name');
                break;
            case 'newest':
                $query->orderByDesc('created_at')->orderBy('name');
                break;
            case 'products_desc':
            default:
                $query->orderByDesc('visible_products_count')->orderBy('name');
                break;
        }

        $perPage = min((int) $request->input('per_page', 24), 60);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Seller $s) => $this->formatPublicSellerListRow($s))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPublicSellerListRow(Seller $s): array
    {
        $rc = (int) ($s->reviews_count ?? 0);
        $avg = $s->reviews_avg_rating !== null ? round((float) $s->reviews_avg_rating, 2) : null;
        $pos = (int) ($s->positive_reviews_count ?? 0);
        $positivePercent = $rc > 0 ? (int) round(100 * $pos / $rc) : null;

        return [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'avatar_url' => $s->avatar_url,
            'products_count' => (int) ($s->visible_products_count ?? 0),
            'reviews_summary' => [
                'count' => $rc,
                'avg_rating' => $avg,
                'positive_percent' => $positivePercent,
            ],
        ];
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $products = SystemProduct::query()
            ->whereIn('status', $this->visibleStatuses())
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            })
            ->with(['category:id,name,slug', 'seller:id,name,slug', 'photos' => $this->enabledPhotos(), 'productSources.donorProduct:id,external_id'])
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'query' => $q,
            'data' => $products->getCollection()->map(fn (SystemProduct $sp) => $this->storefrontCard($sp))->values(),
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
            ->whereIn('status', $this->visibleStatuses())
            ->whereHas('photos', fn ($q) => $q->where('is_enabled', true))
            ->inRandomOrder()
            ->limit($limit)
            ->with(['seller:id,name,slug', 'photos' => $this->enabledPhotos(), 'productSources.donorProduct:id,external_id'])
            ->get();

        return response()->json(['data' => $products->map(fn (SystemProduct $sp) => $this->storefrontCard($sp))]);
    }

    /**
     * POST /api/v1/public/products/storefront-cards
     * Карточки с ценой витрины (комиссия уже в price / price_raw) по списку id, как в URL товара.
     */
    public function storefrontCardsBatch(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        if (! is_array($ids)) {
            return response()->json([
                'message' => 'Поле ids обязательно и должно быть массивом идентификаторов (как в /product/{id}).',
            ], 422);
        }

        $normalized = collect($ids)
            ->map(fn ($x) => trim((string) $x))
            ->filter(fn ($x) => $x !== '')
            ->unique()
            ->take(50)
            ->values()
            ->all();

        if ($normalized === []) {
            return response()->json(['by_id' => (object) []]);
        }

        $resolved = $this->resolveVisibleSystemProductsByRequestedIds($normalized);
        $byId = [];
        foreach ($normalized as $reqId) {
            if (! isset($resolved[$reqId])) {
                continue;
            }
            $byId[$reqId] = (new PublicSystemProductStorefrontCardResource($resolved[$reqId]))->toArray($request);
        }

        return response()->json(['by_id' => empty($byId) ? (object) [] : $byId]);
    }

    /**
     * Eager-load для батча карточек (без лишних N+1).
     *
     * @return array<string, mixed>
     */
    private function storefrontCardBatchRelations(): array
    {
        return [
            'photos' => $this->enabledPhotos(),
            'category:id,name,slug',
            'seller:id,name,slug,pavilion',
            'productSources.donorProduct:id,external_id',
        ];
    }

    /**
     * Сопоставляет строки из запроса (как в маршруте товара) с видимыми system_products.
     *
     * @param  list<string>  $requestedIds
     * @return array<string, SystemProduct>
     */
    private function resolveVisibleSystemProductsByRequestedIds(array $requestedIds): array
    {
        $visible = $this->visibleStatuses();
        $resolved = [];

        $bySpPrefix = [];
        foreach ($requestedIds as $rid) {
            if (str_starts_with($rid, 'sp-')) {
                $pk = (int) substr($rid, 3);
                if ($pk > 0) {
                    $bySpPrefix[$rid] = $pk;
                }
            }
        }
        if ($bySpPrefix !== []) {
            $rows = SystemProduct::query()
                ->whereIn('status', $visible)
                ->whereIn('id', array_values($bySpPrefix))
                ->with($this->storefrontCardBatchRelations())
                ->get()
                ->keyBy('id');
            foreach ($bySpPrefix as $rid => $pk) {
                $sp = $rows->get($pk);
                if ($sp !== null) {
                    $resolved[$rid] = $sp;
                }
            }
        }

        $remaining = array_values(array_filter($requestedIds, fn (string $rid) => ! isset($resolved[$rid])));

        $numericRids = [];
        foreach ($remaining as $rid) {
            if (ctype_digit($rid)) {
                $numericRids[$rid] = (int) $rid;
            }
        }
        if ($numericRids !== []) {
            $rows = SystemProduct::query()
                ->whereIn('status', $visible)
                ->whereIn('id', array_values($numericRids))
                ->with($this->storefrontCardBatchRelations())
                ->get()
                ->keyBy('id');
            foreach ($numericRids as $rid => $pk) {
                $sp = $rows->get($pk);
                if ($sp !== null) {
                    $resolved[$rid] = $sp;
                }
            }
        }

        $remaining = array_values(array_filter($requestedIds, fn (string $rid) => ! isset($resolved[$rid])));
        if ($remaining === []) {
            return $resolved;
        }

        $products = SystemProduct::query()
            ->whereIn('status', $visible)
            ->whereHas('productSources', function ($q) use ($remaining): void {
                $q->where('source', ProductSource::SOURCE_PARSER)
                    ->whereHas('donorProduct', fn ($q2) => $q2->whereIn('external_id', $remaining));
            })
            ->with($this->storefrontCardBatchRelations())
            ->get();

        $byExternal = [];
        foreach ($products as $sp) {
            foreach ($sp->productSources as $ps) {
                if ($ps->source !== ProductSource::SOURCE_PARSER) {
                    continue;
                }
                $ext = $ps->donorProduct?->external_id;
                if ($ext === null || $ext === '') {
                    continue;
                }
                $key = (string) $ext;
                if (in_array($key, $remaining, true) && ! isset($byExternal[$key])) {
                    $byExternal[$key] = $sp;
                }
            }
        }

        foreach ($remaining as $rid) {
            if (! isset($resolved[$rid]) && isset($byExternal[$rid])) {
                $resolved[$rid] = $byExternal[$rid];
            }
        }

        return $resolved;
    }

    public function findVisibleSystemProductByPublicId(string $externalId): SystemProduct
    {
        if (str_starts_with($externalId, 'sp-')) {
            $id = (int) substr($externalId, 3);
            if ($id > 0) {
                return SystemProduct::query()
                    ->whereIn('status', $this->visibleStatuses())
                    ->whereKey($id)
                    ->firstOrFail();
            }
        }

        // Numeric URL segment: try system_products.id, then donor external_id.
        if (ctype_digit($externalId)) {
            $id = (int) $externalId;
            if ($id > 0) {
                $byPk = SystemProduct::query()
                    ->whereIn('status', $this->visibleStatuses())
                    ->whereKey($id)
                    ->first();
                if ($byPk !== null) {
                    return $byPk;
                }
            }
        }

        return SystemProduct::query()
            ->whereIn('status', $this->visibleStatuses())
            ->whereHas('productSources', function ($q) use ($externalId) {
                $q->where('source', ProductSource::SOURCE_PARSER)
                    ->whereHas('donorProduct', fn ($q2) => $q2->where('external_id', $externalId));
            })
            ->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSystemFiltersForCategoryIds(array $catalogCategoryIds): array
    {
        $ids = SystemProduct::query()
            ->whereIn('status', $this->visibleStatuses())
            ->whereIn('category_id', $catalogCategoryIds)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $normalizer = app(CatalogAttributeNormalizer::class);
        $keys = ['size', 'color', 'material', 'country_of_origin', 'brand'];
        $out = [];
        foreach ($keys as $key) {
            $values = SystemProductAttribute::query()
                ->whereIn('system_product_id', $ids)
                ->where('attribute_key', $key)
                ->distinct()
                ->pluck('attr_value')
                ->filter(fn ($v) => is_string($v) && trim($v) !== '')
                ->unique()
                ->sortBy(fn ($v) => $this->filterValueSortKey($key, (string) $v))
                ->values()
                ->all();
            if (empty($values)) {
                continue;
            }
            $out[] = [
                'attr_name' => $key,
                'display_name' => $normalizer->displayName($key),
                'display_type' => 'select',
                'values' => $values,
            ];
        }

        return $out;
    }

    private function filterValueSortKey(string $key, string $value): string
    {
        if ($key === 'size') {
            $order = [
                'XXS' => 1,
                'XS' => 2,
                'S' => 3,
                'M' => 4,
                'L' => 5,
                'XL' => 6,
                'XXL' => 7,
                '3XL' => 8,
                '4XL' => 9,
                '5XL' => 10,
            ];
            if (isset($order[$value])) {
                return sprintf('0001-%04d', $order[$value]);
            }
            if (ctype_digit($value)) {
                return sprintf('0002-%04d', (int) $value);
            }
        }

        return '9999-'.$value;
    }

    /**
     * @return list<int>
     */
    private function collectActiveDescendantCategoryIds(int $rootId): array
    {
        $all = CatalogCategory::query()
            ->where('is_active', true)
            ->get(['id', 'parent_id']);

        $childrenByParent = [];
        foreach ($all as $row) {
            $pid = $row->parent_id === null ? 0 : (int) $row->parent_id;
            $childrenByParent[$pid] ??= [];
            $childrenByParent[$pid][] = (int) $row->id;
        }

        $result = [];
        $stack = [$rootId];
        $seen = [];
        while (! empty($stack)) {
            $id = (int) array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $stack[] = (int) $childId;
            }
        }

        return $result;
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

    /**
     * Публичная карточка товара: price / price_raw уже с комиссией маркетплейса.
     * Используется списками, деталкой и батч-эндпоинтом витрины.
     */
    public function storefrontCard(SystemProduct $sp): array
    {
        $sp->loadMissing(['photos', 'category:id,name,slug', 'seller:id,name,slug', 'productSources.donorProduct:id,external_id']);
        $urls = $sp->photos->pluck('url')->filter()->values()->all();
        $thumb = $urls[0] ?? null;
        $price = $this->priceForStorefront($sp);

        return [
            'id' => $this->publicId($sp),
            'title' => $sp->name,
            'price' => $price > 0 ? number_format($price, 0, '.', ' ').' ₽' : $sp->price,
            'price_raw' => $price,
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
        $price = $this->priceForStorefront($sp);

        return [
            'id' => $this->publicId($sp),
            'title' => $sp->name,
            'price' => $price > 0 ? number_format($price, 0, '.', ' ').' ₽' : $sp->price,
            'price_raw' => $price,
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

    /**
     * Цена строки заказа (как на витрине): базовый price_raw + комиссия по категории.
     */
    public function priceForStorefront(SystemProduct $sp): int
    {
        return app(MarketplaceSettingsService::class)->priceWithCommission(
            $sp->price_raw ?: $this->parsePrice($sp->price),
            $sp->category_id ? (int) $sp->category_id : null
        );
    }

    private function parsePrice(?string $price): int
    {
        $digits = preg_replace('/[^\d]/', '', (string) $price);
        return $digits ? (int) $digits : 0;
    }

    /**
     * @return list<string>
     */
    private function visibleStatuses(): array
    {
        return [
            SystemProduct::STATUS_APPROVED,
            SystemProduct::STATUS_PUBLISHED,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function filterMenuTree(array $nodes, int $minProducts): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $children = $this->filterMenuTree($children, $minProducts);

            $ownCount = (int) ($node['products_count'] ?? 0);
            $childrenTotal = 0;
            foreach ($children as $child) {
                $childrenTotal += (int) ($child['products_count'] ?? 0);
            }
            $total = $ownCount + $childrenTotal;

            if ($total <= $minProducts) {
                continue;
            }

            $node['products_count'] = $total;
            $node['children'] = array_values($children);
            $out[] = $node;
        }

        return $out;
    }
}

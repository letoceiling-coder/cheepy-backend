<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use App\Models\SystemProductPhoto;
use App\Services\Catalog\SystemProductFromDonorService;
use App\Services\CatalogAttributeNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * System products — editable layer. Admin works here.
 * Parser writes ONLY to products (donor). products is READ-ONLY for admin.
 */
class SystemProductController extends Controller
{
    public function __construct(
        private SystemProductFromDonorService $fromDonorService
    ) {}

    /**
     * Связи для полной карточки CRM (в т.ч. похожие товары донора).
     *
     * @return array<int, string|\Closure>
     */
    private function relationsForSystemProductFull(): array
    {
        return [
            'productSources.donorProduct.category:id,name,slug',
            'productSources.donorProduct.seller:id,name,slug',
            'productSources.donorProduct.similarLinks.relatedProduct:id,title,external_id,photos,photos_count,source_url',
            'seller',
            'category',
            'brand',
            'attributes',
            'photos' => fn ($q) => $q->orderBy('sort_order'),
        ];
    }

    /**
     * GET /api/v1/system-products
     */
    public function index(Request $request): JsonResponse
    {
        $query = SystemProduct::with([
            'photos' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->limit(5),
            'productSources.donorProduct:id,title,external_id,price,photos_count,photos',
            'seller:id,name,slug',
            'category:id,name,slug',
            'brand:id,name,slug',
        ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        } elseif ($request->boolean('exclude_approved')) {
            $query->whereNotIn('status', [
                SystemProduct::STATUS_APPROVED,
                SystemProduct::STATUS_PUBLISHED,
            ]);
        }
        $categoryIdsFilter = $this->parseCategoryIdsQuery($request);
        if ($categoryIdsFilter !== null && $categoryIdsFilter !== []) {
            $query->whereIn('category_id', $categoryIdsFilter);
        } elseif ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }
        $sellerIdsFilter = $this->parseSellerIdsQuery($request);
        if ($sellerIdsFilter !== null && $sellerIdsFilter !== []) {
            $query->whereIn('seller_id', $sellerIdsFilter);
        } elseif ($request->filled('seller_id')) {
            $query->where('seller_id', (int) $request->input('seller_id'));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowed = ['created_at', 'updated_at', 'name', 'status', 'price_raw', 'list_position'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortDir);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $items = $query->paginate($perPage);

        return response()->json([
            'data' => $items->map(fn($sp) => $this->formatSystemProduct($sp)),
            'meta' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * CRM list: category_ids=1,2,3 или повтор category_ids[]=…
     *
     * @return list<int>|null null — параметр не передан; [] — пустой фильтр
     */
    private function parseCategoryIdsQuery(Request $request): ?array
    {
        $query = $request->query();
        if (! array_key_exists('category_ids', $query)) {
            return null;
        }
        $raw = $query['category_ids'];
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(
                array_map(static fn ($v) => (int) $v, $raw),
                static fn (int $id) => $id > 0
            )));
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $str, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => (int) $v, $parts),
            static fn (int $id) => $id > 0
        )));
    }

    /**
     * CRM list: seller_ids=1,2,3 или повтор seller_ids[]=…
     *
     * @return list<int>|null null — параметр не передан; [] — некорректно/пусто (не фильтруем)
     */
    private function parseSellerIdsQuery(Request $request): ?array
    {
        $query = $request->query();
        if (! array_key_exists('seller_ids', $query)) {
            return null;
        }
        $raw = $query['seller_ids'];
        if (is_array($raw)) {
            $ids = array_values(array_unique(array_filter(
                array_map(static fn ($v) => (int) $v, $raw),
                static fn (int $id) => $id > 0
            )));

            return $ids;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $str, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => (int) $v, $parts),
            static fn (int $id) => $id > 0
        )));
    }

    /**
     * GET /api/v1/system-products/{id}
     */
    public function show(int $id): JsonResponse
    {
        $sp = SystemProduct::with($this->relationsForSystemProductFull())->findOrFail($id);

        return response()->json($this->formatSystemProductFull($sp));
    }

    /**
     * POST /api/v1/system-products
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:500',
            'description' => 'nullable|string',
            'price' => 'nullable|string|max:100',
            'price_raw' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:draft,pending,approved,published,needs_review',
            'seller_id' => 'nullable|integer|exists:sellers,id',
            'category_id' => 'nullable|integer|exists:catalog_categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'donor_product_id' => 'nullable|integer|exists:products,id',
        ]);

        $sp = SystemProduct::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'price_raw' => $data['price_raw'] ?? null,
            'status' => $data['status'] ?? SystemProduct::STATUS_DRAFT,
            'seller_id' => $data['seller_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
        ]);

        if (!empty($data['donor_product_id'])) {
            $donor = Product::find((int) $data['donor_product_id']);
            ProductSource::create([
                'system_product_id' => $sp->id,
                'donor_product_id' => (int) $data['donor_product_id'],
                'source' => ProductSource::SOURCE_PARSER,
                'donor_updated_at' => $donor?->updated_at,
            ]);
        }

        $sp->load($this->relationsForSystemProductFull());
        return response()->json($this->formatSystemProductFull($sp), 201);
    }

    /**
     * PATCH /api/v1/admin/system-products/{id}
     * Редактор каталога (витринные поля). Смена workflow-статуса — только {@see moderate}.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $sp = SystemProduct::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:500',
            'description' => 'sometimes|nullable|string',
            'price' => 'sometimes|nullable|string|max:100',
            'price_raw' => 'sometimes|nullable|integer|min:0',
            'seller_id' => 'sometimes|nullable|integer|exists:sellers,id',
            'category_id' => 'sometimes|nullable|integer|exists:catalog_categories,id',
            'brand_id' => 'sometimes|nullable|integer|exists:brands,id',
            'list_position' => 'sometimes|nullable|integer|min:0|max:2147483647',
        ]);

        $sp->update($data);

        return response()->json($this->formatSystemProductFull($sp->fresh($this->relationsForSystemProductFull())));
    }

    /**
     * PATCH /api/v1/admin/system-products/{id}/crm-attributes
     * Замена атрибутов каталога CRM (не трогает парсер / products).
     */
    public function syncCrmAttributes(Request $request, int $id): JsonResponse
    {
        $sp = SystemProduct::findOrFail($id);

        $data = $request->validate([
            'attributes' => 'present|array',
            'attributes.*.attr_name' => 'required|string|max:200',
            'attributes.*.attr_value' => 'nullable|string|max:4000',
        ]);

        DB::transaction(function () use ($sp, $data) {
            SystemProductAttribute::where('system_product_id', $sp->id)->delete();

            $seen = [];
            $normalizer = app(CatalogAttributeNormalizer::class);
            foreach ($data['attributes'] as $row) {
                $raw = mb_substr((string) ($row['attr_value'] ?? ''), 0, 500);
                $attrName = trim((string) $row['attr_name']);
                if ($attrName === '') {
                    continue;
                }

                $rows = $normalizer->normalizeAttribute('', $attrName, $raw, 'text', 1.0, 'crm');
                foreach ($rows as $normalized) {
                    $key = $normalized['attribute_key']."\0".mb_strtolower((string) $normalized['attr_value']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $attrType = $this->detectCrmAttrType((string) $normalized['attr_value']);
                    $payload = [
                        'system_product_id' => $sp->id,
                        'attribute_key' => $normalized['attribute_key'],
                        'attr_name' => $normalized['attr_name'],
                        'attr_value' => $normalized['attr_value'],
                        'attr_value_original' => $normalized['attr_value_original'],
                        'attr_type' => $normalized['attr_type'] ?: $attrType,
                        'confidence' => $normalized['confidence'] ?? 1.0,
                    ];
                    if ($attrType === SystemProductAttribute::TYPE_INT) {
                        $payload['value_int'] = $this->parseCrmInt((string) $normalized['attr_value']);
                    } elseif ($attrType === SystemProductAttribute::TYPE_FLOAT) {
                        $payload['value_float'] = $this->parseCrmFloat((string) $normalized['attr_value']);
                    }
                    SystemProductAttribute::create($payload);
                }
            }
        });

        return response()->json($this->formatSystemProductFull($sp->fresh($this->relationsForSystemProductFull())));
    }

    /**
     * PATCH /api/v1/admin/system-products/{id}/crm-photos
     * Полный снимок фото карточки CRM (порядок, вкл/выкл, главное). Парсер не меняется.
     */
    public function syncCrmPhotos(Request $request, int $id): JsonResponse
    {
        $sp = SystemProduct::findOrFail($id);

        $data = $request->validate([
            'photos' => 'present|array',
            'photos.*.id' => 'nullable|integer',
            'photos.*.url' => 'required|string|max:1000',
            'photos.*.sort_order' => 'required|integer|min:0|max:9999',
            'photos.*.is_primary' => 'sometimes|boolean',
            'photos.*.is_enabled' => 'sometimes|boolean',
            'photos.*.media_file_id' => 'nullable|integer|exists:crm_media_files,id',
        ]);

        foreach ($data['photos'] as $i => $row) {
            $url = trim((string) $row['url']);
            if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
                throw ValidationException::withMessages([
                    "photos.$i.url" => ['URL фото должен начинаться с http(s)://'],
                ]);
            }
        }

        DB::transaction(function () use ($sp, $data) {
            $kept = [];
            foreach ($data['photos'] as $row) {
                $url = trim((string) $row['url']);
                if ($url === '') {
                    continue;
                }

                if (! empty($row['id'])) {
                    $photo = SystemProductPhoto::where('system_product_id', $sp->id)
                        ->whereKey((int) $row['id'])
                        ->first();
                    if (! $photo) {
                        continue;
                    }
                    $photo->update([
                        'url' => $url,
                        'sort_order' => (int) $row['sort_order'],
                        'is_primary' => (bool) ($row['is_primary'] ?? false),
                        'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                        'media_file_id' => $row['media_file_id'] ?? null,
                    ]);
                    $kept[] = $photo->id;
                } else {
                    $photo = SystemProductPhoto::create([
                        'system_product_id' => $sp->id,
                        'url' => $url,
                        'sort_order' => (int) $row['sort_order'],
                        'is_primary' => (bool) ($row['is_primary'] ?? false),
                        'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                        'media_file_id' => $row['media_file_id'] ?? null,
                    ]);
                    $kept[] = $photo->id;
                }
            }

            SystemProductPhoto::where('system_product_id', $sp->id)
                ->whereNotIn('id', $kept)
                ->delete();
        });

        return response()->json($this->formatSystemProductFull($sp->fresh($this->relationsForSystemProductFull())));
    }

    private function detectCrmAttrType(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', str_replace(',', '.', $value)));
        if ($normalized === '') {
            return SystemProductAttribute::TYPE_TEXT;
        }
        if (preg_match('/^-?\d+$/', $normalized)) {
            return SystemProductAttribute::TYPE_INT;
        }
        if (is_numeric($normalized)) {
            return SystemProductAttribute::TYPE_FLOAT;
        }

        return SystemProductAttribute::TYPE_TEXT;
    }

    private function parseCrmInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^-?\d+/', $value, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    private function parseCrmFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m)) {
            return (float) str_replace(',', '.', $m[0]);
        }

        return null;
    }

    /**
     * PATCH /api/v1/admin/system-products/{id}/moderate
     * Только решение по очереди модерации (изоляция от парсера и от редактора карточки).
     */
    public function moderate(Request $request, int $id): JsonResponse
    {
        $sp = SystemProduct::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|string|in:draft,pending,approved,published,needs_review',
        ]);

        $sp->update(['status' => $data['status']]);

        return response()->json($this->formatSystemProductFull($sp->fresh($this->relationsForSystemProductFull())));
    }

    /**
     * DELETE /api/v1/system-products/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        SystemProduct::findOrFail($id)->delete();
        return response()->json(['message' => 'Удалено'], 204);
    }

    /**
     * POST /api/v1/system-products/create-from-donor
     * Create system_product from donor product (products.id). Moderation flow entry.
     */
    public function createFromDonor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'donor_product_id' => 'required|integer|exists:products,id',
            'status' => 'nullable|string|in:draft,pending,approved,published',
        ]);

        $donor = Product::with(['attributes', 'photoRecords'])->findOrFail($data['donor_product_id']);
        $sp = $this->fromDonorService->createFromDonor(
            $donor,
            $data['status'] ?? SystemProduct::STATUS_DRAFT
        );

        return response()->json($this->formatSystemProductFull($sp), 201);
    }

    private function formatSystemProduct(SystemProduct $sp): array
    {
        $donors = $sp->relationLoaded('productSources')
            ? $sp->productSources->map(fn($ps) => [
                'donor_product_id' => $ps->donor_product_id,
                'source' => $ps->source,
                'donor' => $ps->donorProduct ? [
                    'id' => $ps->donorProduct->id,
                    'title' => $ps->donorProduct->title,
                    'external_id' => $ps->donorProduct->external_id ?? null,
                    'photos_count' => $ps->donorProduct->photos_count ?? 0,
                ] : null,
            ])->toArray()
            : [];

        return [
            'id' => $sp->id,
            'name' => $sp->name,
            'price' => $sp->price,
            'price_raw' => $sp->price_raw,
            'status' => $sp->status,
            'seller_id' => $sp->seller_id,
            'category_id' => $sp->category_id,
            'brand_id' => $sp->brand_id,
            'list_position' => (int) ($sp->list_position ?? 0),
            'thumbnail_url' => $this->thumbnailUrlForSystemProduct($sp),
            'seller' => $sp->seller?->only(['id', 'name', 'slug']),
            'category' => $sp->category?->only(['id', 'name', 'slug']),
            'brand' => $sp->brand?->only(['id', 'name', 'slug']),
            'donor_sources' => $donors,
            'created_at' => $sp->created_at->toIso8601String(),
            'updated_at' => $sp->updated_at->toIso8601String(),
        ];
    }

    /**
     * Превью для списка: сначала CRM-фото (включено), иначе первое фото донора из JSON.
     */
    private function thumbnailUrlForSystemProduct(SystemProduct $sp): ?string
    {
        if ($sp->relationLoaded('photos') && $sp->photos->isNotEmpty()) {
            $enabled = $sp->photos->filter(fn ($p) => (bool) ($p->is_enabled ?? true));
            $list = $enabled->isNotEmpty() ? $enabled : $sp->photos;
            $cand = $list->sort(function ($a, $b) {
                $ap = $a->is_primary ? 1 : 0;
                $bp = $b->is_primary ? 1 : 0;
                if ($ap !== $bp) {
                    return $bp <=> $ap;
                }

                return ($a->sort_order ?? 0) <=> ($b->sort_order ?? 0);
            })->first();
            if ($cand && ! empty(trim((string) $cand->url))) {
                return $cand->url;
            }
        }

        $donor = $sp->productSources->first()?->donorProduct;
        if ($donor && is_array($donor->photos) && count($donor->photos) > 0) {
            $first = $donor->photos[0];

            return is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * Похожие товары донора (таблица product_similar), если связь загружена.
     *
     * @return list<array{related_external_id: string, related_product_id: int|null, sort_order: int, related: array<string, mixed>|null}>
     */
    private function formatDonorSimilarLinks(?Product $d): array
    {
        if ($d === null || ! $d->relationLoaded('similarLinks')) {
            return [];
        }

        return $d->similarLinks->map(function ($row) {
            $rel = $row->relatedProduct;
            $thumb = null;
            if ($rel && is_array($rel->photos) && isset($rel->photos[0])) {
                $thumb = is_string($rel->photos[0]) ? $rel->photos[0] : null;
            }

            return [
                'related_external_id' => $row->related_external_id,
                'related_product_id' => $row->related_product_id,
                'sort_order' => (int) $row->sort_order,
                'related' => $rel ? [
                    'id' => $rel->id,
                    'title' => $rel->title,
                    'external_id' => $rel->external_id,
                    'source_url' => $rel->source_url,
                    'thumbnail' => $thumb,
                ] : null,
            ];
        })->values()->toArray();
    }

    private function formatSystemProductFull(SystemProduct $sp): array
    {
        $base = $this->formatSystemProduct($sp);
        $base['description'] = $sp->description;
        $base['attributes'] = $sp->attributes->map(fn ($a) => [
            'attr_name' => $a->attr_name,
            'attr_value' => $a->attr_value,
            'attr_type' => $a->attr_type,
            'value_int' => $a->value_int,
            'value_float' => $a->value_float,
        ])->toArray();
        $base['photos'] = $sp->photos->sortBy('sort_order')->values()->map(fn ($p) => [
            'id' => $p->id,
            'url' => $p->url,
            'is_primary' => $p->is_primary,
            'sort_order' => $p->sort_order,
            'is_enabled' => (bool) ($p->is_enabled ?? true),
            'media_file_id' => $p->media_file_id,
        ])->toArray();

        $base['donor_sources'] = $sp->productSources->map(function ($ps) {
            $d = $ps->donorProduct;
            if (!$d) {
                return ['donor_product_id' => $ps->donor_product_id, 'source' => $ps->source, 'donor' => null];
            }
            $firstPhoto = is_array($d->photos) ? ($d->photos[0] ?? null) : null;
            return [
                'donor_product_id' => $ps->donor_product_id,
                'source' => $ps->source,
                'donor' => [
                    'id' => $d->id,
                    'external_id' => $d->external_id,
                    'title' => $d->title,
                    'price' => $d->price,
                    'source_url' => $d->source_url,
                    'photos' => $d->photos ?? [],
                    'thumbnail' => $firstPhoto,
                    'category' => $d->category?->only(['id', 'name', 'slug']),
                    'seller' => $d->seller?->only(['id', 'name', 'slug']),
                    'similar_products' => $this->formatDonorSimilarLinks($d),
                ],
            ];
        })->toArray();

        $donor = $sp->productSources->first()?->donorProduct;
        $suggested = $this->fromDonorService->resolveMappedCatalogCategoryIdForDonorProduct($donor);
        $base['mapping_suggested_category_id'] = $suggested;

        return $base;
    }
}

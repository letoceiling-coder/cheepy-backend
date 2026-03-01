<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExcludedRule;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * GET /api/v1/products
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category:id,name,slug', 'seller:id,name,slug'])
            ->select(['id', 'external_id', 'title', 'price', 'price_raw', 'status',
                'category_id', 'seller_id', 'photos', 'photos_count', 'color',
                'is_relevant', 'parsed_at', 'created_at']);

        // Фильтры
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }
        if ($sellerId = $request->input('seller_id')) {
            $query->where('seller_id', $sellerId);
        }
        if ($request->input('photos_only')) {
            $query->where('photos_count', '>', 0);
        }
        if ($request->input('no_photos')) {
            $query->where('photos_count', 0);
        }
        if ($priceFrom = $request->input('price_from')) {
            $query->where('price_raw', '>=', (int) $priceFrom);
        }
        if ($priceTo = $request->input('price_to')) {
            $query->where('price_raw', '<=', (int) $priceTo);
        }
        if ($request->input('is_relevant') !== null) {
            $query->where('is_relevant', $request->boolean('is_relevant'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'parsed_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowed = ['parsed_at', 'price_raw', 'title', 'created_at', 'photos_count'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortDir);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $products = $query->paginate($perPage);

        return response()->json([
            'data' => $products->map(fn($p) => $this->formatProduct($p)),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/products/{id}
     */
    public function show(string $id): JsonResponse
    {
        $product = Product::with([
            'category', 'seller', 'brand',
            'photoRecords' => fn($q) => $q->orderBy('sort_order'),
            'attributes',
        ])->where('external_id', $id)
          ->orWhere('id', is_numeric($id) ? $id : 0)
          ->firstOrFail();

        return response()->json($this->formatProductFull($product));
    }

    /**
     * PATCH /api/v1/products/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->only(['status', 'title', 'description', 'price', 'brand_id', 'is_relevant']));
        return response()->json($this->formatProductFull($product->fresh()));
    }

    /**
     * DELETE /api/v1/products/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Product::findOrFail($id)->delete();
        return response()->json(['message' => 'Удалено']);
    }

    /**
     * POST /api/v1/products/bulk
     * Массовые действия: delete, hide, publish
     */
    public function bulk(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        $action = $request->input('action');

        if (empty($ids)) {
            return response()->json(['error' => 'Не указаны ID'], 422);
        }

        match ($action) {
            'delete'  => Product::whereIn('id', $ids)->delete(),
            'hide'    => Product::whereIn('id', $ids)->update(['status' => 'hidden']),
            'publish' => Product::whereIn('id', $ids)->update(['status' => 'active']),
            default   => null,
        };

        return response()->json(['message' => "Действие '{$action}' применено к " . count($ids) . " товарам"]);
    }

    // -------------------------------------------------------
    // Форматирование
    // -------------------------------------------------------

    private function formatProduct(Product $p): array
    {
        $firstPhoto = is_array($p->photos) ? ($p->photos[0] ?? null) : null;
        return [
            'id' => $p->id,
            'external_id' => $p->external_id,
            'title' => $p->title,
            'price' => $p->price,
            'price_raw' => $p->price_raw,
            'status' => $p->status,
            'is_relevant' => $p->is_relevant,
            'photos_count' => $p->photos_count,
            'thumbnail' => $firstPhoto,
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name, 'slug' => $p->category->slug] : null,
            'seller' => $p->seller ? ['id' => $p->seller->id, 'name' => $p->seller->name, 'slug' => $p->seller->slug] : null,
            'parsed_at' => $p->parsed_at?->toIso8601String(),
        ];
    }

    private function formatProductFull(Product $p): array
    {
        return [
            'id' => $p->id,
            'external_id' => $p->external_id,
            'source_url' => $p->source_url,
            'title' => $p->title,
            'price' => $p->price,
            'price_raw' => $p->price_raw,
            'description' => $p->description,
            'status' => $p->status,
            'is_relevant' => $p->is_relevant,
            'color' => $p->color,
            'size_range' => $p->size_range,
            'characteristics' => $p->characteristics,
            'source_link' => $p->source_link,
            'source_published_at' => $p->source_published_at?->toIso8601String(),
            'category_slugs' => $p->category_slugs,
            'photos' => $p->photos ?? [],
            'photos_downloaded' => $p->photos_downloaded,
            'photos_detail' => ($p->relationLoaded('photoRecords') ? $p->photoRecords : collect())->map(fn($ph) => [
                'id' => $ph->id,
                'original_url' => $ph->original_url,
                'local_path' => $ph->local_path,
                'is_primary' => $ph->is_primary,
                'download_status' => $ph->download_status,
                'sort_order' => $ph->sort_order,
            ]),
            'attributes' => $p->attributes->map(fn($a) => [
                'name' => $a->attr_name,
                'value' => $a->attr_value,
                'type' => $a->attr_type,
            ]),
            'category' => $p->category?->only(['id', 'name', 'slug']),
            'seller' => $p->seller ? [
                'id' => $p->seller->id,
                'name' => $p->seller->name,
                'slug' => $p->seller->slug,
                'pavilion' => $p->seller->pavilion,
                'phone' => $p->seller->phone,
                'whatsapp_number' => $p->seller->whatsapp_number,
            ] : null,
            'brand' => $p->brand?->only(['id', 'name', 'slug', 'logo_url']),
            'parsed_at' => $p->parsed_at?->toIso8601String(),
            'created_at' => $p->created_at->toIso8601String(),
        ];
    }
}

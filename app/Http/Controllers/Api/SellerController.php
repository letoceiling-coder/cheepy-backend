<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerController extends Controller
{
    /**
     * GET /api/v1/sellers
     */
    public function index(Request $request): JsonResponse
    {
        $query = Seller::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('pavilion', 'like', "%{$search}%");
            });
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($request->input('has_products')) {
            $query->where('products_count', '>', 0);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $sellers = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $sellers->map(fn($s) => $this->formatSeller($s)),
            'meta' => [
                'total' => $sellers->total(),
                'per_page' => $sellers->perPage(),
                'current_page' => $sellers->currentPage(),
                'last_page' => $sellers->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/sellers/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $seller = Seller::where('slug', $slug)
            ->orWhere('id', is_numeric($slug) ? $slug : 0)
            ->firstOrFail();

        return response()->json($this->formatSellerFull($seller));
    }

    /**
     * GET /api/v1/sellers/{slug}/products
     */
    public function products(Request $request, string $slug): JsonResponse
    {
        $seller = Seller::where('slug', $slug)->firstOrFail();

        $products = Product::where('seller_id', $seller->id)
            ->where('status', 'active')
            ->select(['id', 'external_id', 'title', 'price', 'photos', 'photos_count', 'category_id'])
            ->with('category:id,name,slug')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'seller' => $this->formatSeller($seller),
            'data' => $products->items(),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/sellers/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $seller = Seller::findOrFail($id);
        $seller->update($request->only(['status', 'is_verified', 'rating']));
        return response()->json($this->formatSellerFull($seller->fresh()));
    }

    private function formatSeller(Seller $s): array
    {
        return [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->name,
            'pavilion' => $s->pavilion,
            'pavilion_line' => $s->pavilion_line,
            'pavilion_number' => $s->pavilion_number,
            'status' => $s->status,
            'is_verified' => $s->is_verified,
            'products_count' => $s->products_count,
            'last_parsed_at' => $s->last_parsed_at?->toIso8601String(),
        ];
    }

    private function formatSellerFull(Seller $s): array
    {
        $data = $this->formatSeller($s);
        $data['source_url'] = $s->source_url;
        $data['description'] = $s->description;
        $data['contacts'] = [
            'phone' => $s->phone,
            'whatsapp_url' => $s->whatsapp_url,
            'whatsapp_number' => $s->whatsapp_number,
            'telegram_url' => $s->telegram_url,
            'vk_url' => $s->vk_url,
        ];
        $data['seller_categories'] = $s->seller_categories ?? [];
        return $data;
    }
}

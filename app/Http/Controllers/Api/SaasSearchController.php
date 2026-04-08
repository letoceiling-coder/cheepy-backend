<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductFacetsService;
use App\Services\Catalog\ProductFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasSearchController extends Controller
{
    public function __construct(
        private ProductFilterService $filterService,
        private ProductFacetsService $facetsService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer',
            'price_min' => 'nullable|numeric',
            'price_max' => 'nullable|numeric',
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'attributes' => 'nullable',
        ]);

        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $priceMin = isset($validated['price_min']) ? (float) $validated['price_min'] : null;
        $priceMax = isset($validated['price_max']) ? (float) $validated['price_max'] : null;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;
        $attributes = $this->parseAttributes($request->input('attributes'));

        $request->merge(['page' => $page]);
        $products = $this->filterService->paginate(
            $categoryId,
            $priceMin,
            $priceMax,
            $attributes,
            $search,
            20
        );

        $facets = $this->facetsService->getFacets($categoryId, $priceMin, $priceMax, $attributes, $search);

        return response()->json([
            'products' => $products->getCollection()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => $p->price,
                'price_raw' => $p->price_raw,
                'status' => $p->status,
                'category_id' => $p->category_id,
                'seller_id' => $p->seller_id,
                'brand_id' => $p->brand_id,
            ])->values()->all(),
            'pagination' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
            'facets' => $facets,
        ]);
    }

    private function parseAttributes(mixed $attributes): array
    {
        if (is_array($attributes)) {
            return $attributes;
        }
        if (is_string($attributes) && $attributes !== '') {
            $decoded = json_decode($attributes, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}

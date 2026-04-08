<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Services\Catalog\CategoryMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCategoryMappingController extends Controller
{
    public function __construct(
        private CategoryMappingService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);
        $paginated = $this->service->listPaginated($perPage);
        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'donor_category_id' => 'required|integer|exists:donor_categories,id|unique:category_mapping,donor_category_id',
            'catalog_category_id' => 'required|integer|exists:catalog_categories,id',
            'confidence' => 'nullable|integer|min:0|max:100',
            'is_manual' => 'nullable|boolean',
        ]);
        $data['confidence'] = $data['confidence'] ?? 100;
        $data['is_manual'] = $data['is_manual'] ?? false;
        $mapping = $this->service->create($data);
        return response()->json($mapping->load(['donorCategory', 'catalogCategory']), 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $mapping = CategoryMapping::find($id);
        if (! $mapping) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $this->service->delete($mapping);
        return response()->json(null, 204);
    }
}

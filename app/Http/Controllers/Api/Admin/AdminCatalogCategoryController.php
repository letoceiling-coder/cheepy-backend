<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use App\Services\Catalog\CatalogCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminCatalogCategoryController extends Controller
{
    public function __construct(
        private CatalogCategoryService $service
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
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:catalog_categories,slug',
            'parent_id' => 'nullable|integer|exists:catalog_categories,id',
            'sort_order' => 'nullable|integer|min:0',
            'icon' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;
        $category = $this->service->create($data);
        return response()->json($category, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = $this->service->findById($id);
        if (! $category) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('catalog_categories')->ignore($category->id)],
            'parent_id' => 'nullable|integer|exists:catalog_categories,id',
            'sort_order' => 'sometimes|integer|min:0',
            'icon' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        $category = $this->service->update($category, $data);
        return response()->json($category);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = $this->service->findById($id);
        if (! $category) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $this->service->delete($category);
        return response()->json(null, 204);
    }

    /**
     * Body: JSON array [{ "id": int, "sort_order": int }, ...]
     * Route must be registered BEFORE categories/{id} OR {id} must use whereNumber('id').
     */
    public function reorder(Request $request): JsonResponse
    {
        $items = $request->json()->all();

        if (! is_array($items)) {
            return response()->json(['message' => 'Expected a JSON array'], 422);
        }

        $items = array_values($items);

        try {
            $validated = Validator::make($items, [
                '*' => 'required|array',
                '*.id' => 'required|integer|exists:catalog_categories,id',
                '*.sort_order' => 'required|integer|min:0',
            ])->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($validated) {
                foreach ($validated as $item) {
                    CatalogCategory::query()
                        ->whereKey((int) $item['id'])
                        ->update(['sort_order' => (int) $item['sort_order']]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('admin.catalog.categories.reorder', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Reorder failed',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json(['success' => true]);
    }
}

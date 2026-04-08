<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CategoryMappingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryMappingController extends Controller
{
    public function __construct(
        private CategoryMappingService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);
        $status = $request->get('status');
        $status = is_string($status) ? strtolower($status) : null;
        if ($status !== null && ! in_array($status, ['mapped', 'unmapped'], true)) {
            $status = null;
        }

        $minConfidence = null;
        if ($request->get('min_confidence')) {
            $minConfidence = (int) $request->get('min_confidence');
        }

        $paginated = $this->service->listPaginated($perPage, $minConfidence, $status);

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
            'donor_category_id' => 'required|integer|exists:donor_categories,id',
            'catalog_category_id' => 'required|integer|exists:catalog_categories,id',
            'confidence' => 'nullable|integer|min:0|max:100',
            'is_manual' => 'nullable|boolean',
        ]);

        $confidence = (int) ($data['confidence'] ?? 100);
        $isManualOnCreate = (bool) ($data['is_manual'] ?? false);

        ['mapping' => $mapping, 'created' => $created] = $this->service->upsertManualMapping(
            (int) $data['donor_category_id'],
            (int) $data['catalog_category_id'],
            $confidence,
            $isManualOnCreate,
        );

        return response()->json([
            'data' => $mapping,
        ], $created ? 201 : 200);
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->deleteMapping($id);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(null, 204);
    }
}

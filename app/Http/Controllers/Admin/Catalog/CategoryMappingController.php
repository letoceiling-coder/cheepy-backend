<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Services\Catalog\AutoMappingService;
use App\Services\Catalog\CategoryMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $existing = CategoryMapping::where('donor_category_id', $data['donor_category_id'])->first();

        if ($existing) {
            $existing->update([
                'catalog_category_id' => $data['catalog_category_id'],
                'confidence' => $data['confidence'] ?? 100,
                'is_manual' => true,
            ]);

            $mapping = $existing->fresh()->load(['donorCategory', 'catalogCategory']);
            $this->logManualOverrideIfNeeded($mapping);

            return response()->json([
                'data' => $mapping,
            ]);
        }

        $data['confidence'] = $data['confidence'] ?? 100;
        $data['is_manual'] = $data['is_manual'] ?? false;
        $mapping = $this->service->create($data);
        $mapping->load(['donorCategory', 'catalogCategory']);

        if ($data['is_manual']) {
            $this->logManualOverrideIfNeeded($mapping);
        }

        return response()->json([
            'data' => $mapping,
        ], 201);
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

    private function logManualOverrideIfNeeded(CategoryMapping $mapping): void
    {
        try {
            app(AutoMappingService::class)->logManualOverride(
                (int) $mapping->donor_category_id,
                (int) $mapping->catalog_category_id,
                (int) ($mapping->confidence ?? 100)
            );
        } catch (Throwable $e) {
            Log::warning('auto_mapping manual_override log failed', [
                'donor_category_id' => $mapping->donor_category_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

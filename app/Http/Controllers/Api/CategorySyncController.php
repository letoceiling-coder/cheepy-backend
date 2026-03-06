<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CategorySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/parser/categories/sync
 * Sync categories from donor (sadovodbaza.ru) catalog menu.
 */
class CategorySyncController extends Controller
{
    public function __invoke(CategorySyncService $syncService): JsonResponse
    {
        try {
            $result = $syncService->sync();
            return response()->json([
                'created' => (int) ($result['created'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'last_synced_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('CategorySync failed', ['error' => $e->getMessage()]);
            return response()->json([
                'created' => 0,
                'updated' => 0,
                'last_synced_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ], 503);
        }
    }
}

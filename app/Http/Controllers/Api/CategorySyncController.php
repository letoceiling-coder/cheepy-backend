<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CategorySyncService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/parser/categories/sync
 * Sync categories from donor (sadovodbaza.ru) catalog menu.
 */
class CategorySyncController extends Controller
{
    public function __invoke(CategorySyncService $syncService): JsonResponse
    {
        $result = $syncService->sync();

        return response()->json([
            'message' => 'Categories synced',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'last_synced_at' => now()->toIso8601String(),
        ]);
    }
}

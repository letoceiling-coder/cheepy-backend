<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Catalog\MappingSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MappingSuggestionController extends Controller
{
    public function __construct(
        private MappingSuggestionService $service
    ) {}

    /**
     * Fresh DB-derived suggestions each request; no application cache layer.
     * Query: ?limit= (default 100, max 500).
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 100), 500);
        $suggestions = $this->service->suggest($limit);

        return response()
            ->json(['data' => $suggestions])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}

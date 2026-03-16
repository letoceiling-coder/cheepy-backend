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

    public function index(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 100), 500);
        $suggestions = $this->service->suggest($limit);
        return response()->json(['data' => $suggestions]);
    }
}

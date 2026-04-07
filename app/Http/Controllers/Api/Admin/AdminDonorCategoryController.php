<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Catalog\DonorCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDonorCategoryController extends Controller
{
    public function __construct(
        private DonorCategoryService $service
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
}

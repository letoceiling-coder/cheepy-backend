<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSystemProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);
        $status = $request->get('status');
        $search = $request->get('search');

        $query = SystemProduct::query()
            ->with(['category:id,name,slug', 'productSources.product:id,external_id,title,source_url'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('id', 'like', '%' . $search . '%');
            });
        }

        $paginated = $query->paginate($perPage);

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

    public function update(Request $request, int $id): JsonResponse
    {
        $product = SystemProduct::find($id);
        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['draft', 'pending', 'approved', 'published', 'needs_review'])],
            'name' => 'sometimes|string|max:500',
            'price' => 'nullable|string|max:50',
            'price_raw' => 'nullable|integer|min:0',
        ]);

        $product->update($data);

        return response()->json($product->fresh(['category:id,name,slug', 'productSources.product:id,external_id,title,source_url']));
    }

    public function show(int $id): JsonResponse
    {
        $product = SystemProduct::with(['category:id,name,slug', 'productSources.product:id,external_id,title,source_url', 'seller:id,name'])
            ->find($id);
        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($product);
    }
}

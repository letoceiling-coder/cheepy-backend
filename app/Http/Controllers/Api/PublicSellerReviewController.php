<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\SellerReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSellerReviewController extends Controller
{
    /**
     * GET /api/v1/public/sellers/{slug}/reviews
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $seller = Seller::where('slug', $slug)->where('status', 'active')->firstOrFail();

        $perPage = min((int) $request->input('per_page', 10), 40);
        $page = $request->input('page', 1);

        $paginator = SellerReview::query()
            ->where('seller_id', $seller->id)
            ->where('is_published', true)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', (int) $page);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (SellerReview $r) => $this->formatReview($r))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/public/sellers/{slug}/reviews
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $seller = Seller::where('slug', $slug)->where('status', 'active')->firstOrFail();

        $validated = $request->validate([
            'author_name' => 'required|string|max:80',
            'rating' => 'required|integer|min:1|max:5',
            'body' => 'required|string|min:10|max:5000',
        ]);

        $review = SellerReview::create([
            'seller_id' => $seller->id,
            'author_name' => $validated['author_name'],
            'rating' => $validated['rating'],
            'body' => $validated['body'],
            'is_published' => true,
        ]);

        return response()->json(['data' => $this->formatReview($review)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReview(SellerReview $r): array
    {
        return [
            'id' => $r->id,
            'author_name' => $r->author_name,
            'rating' => (int) $r->rating,
            'body' => $r->body,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}

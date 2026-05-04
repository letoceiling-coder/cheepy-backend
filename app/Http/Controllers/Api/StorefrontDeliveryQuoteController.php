<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Services\Storefront\StorefrontDeliveryQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontDeliveryQuoteController extends Controller
{
    /**
     * GET /api/v1/store/delivery-quote?product_id=&quantity=
     *
     * Авторизация storefront (customer или админ-пользователь после маппинга на User).
     */
    public function show(
        Request $request,
        PublicSystemCatalogService $catalog,
        StorefrontDeliveryQuoteService $quotes,
    ): JsonResponse {
        $validated = $request->validate([
            'product_id' => 'required|string|max:190',
            'quantity' => 'nullable|integer|min:1|max:99',
        ]);

        $user = $request->attributes->get('storefront_user');
        if ($user === null) {
            return response()->json(['error' => 'Необходима авторизация'], 401);
        }

        try {
            $product = $catalog->findVisibleSystemProductByPublicId($validated['product_id']);
        } catch (\Throwable) {
            return response()->json(['error' => 'Товар не найден'], 404);
        }

        $qty = isset($validated['quantity']) ? (int) $validated['quantity'] : 1;

        return response()->json($quotes->buildQuotesForProduct($user, $product, $qty));
    }
}

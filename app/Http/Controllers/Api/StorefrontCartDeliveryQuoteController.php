<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Services\Storefront\StorefrontDeliveryQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/store/cart-delivery-quote — тарифы доставки по составу корзины.
 */
class StorefrontCartDeliveryQuoteController extends Controller
{
    public function store(
        Request $request,
        PublicSystemCatalogService $catalog,
        StorefrontDeliveryQuoteService $quotes,
    ): JsonResponse {
        $user = $request->attributes->get('storefront_user');
        if (! $user instanceof User) {
            return response()->json(['error' => 'Необходима авторизация'], 401);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:190'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $lines = [];
        foreach ($data['items'] as $row) {
            try {
                $sp = $catalog->findVisibleSystemProductByPublicId((string) $row['product_id']);
            } catch (\Throwable) {
                return response()->json([
                    'error' => 'Один из товаров недоступен для расчёта доставки',
                    'product_id' => $row['product_id'],
                ], 422);
            }

            $lines[] = [
                'product' => $sp,
                'quantity' => max(1, min(99, (int) $row['quantity'])),
            ];
        }

        return response()->json($quotes->buildQuotesForCartLines($user, $lines));
    }
}
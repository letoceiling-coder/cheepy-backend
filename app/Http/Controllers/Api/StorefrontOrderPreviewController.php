<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Services\Storefront\StorefrontOrderQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/store/order-preview — серверная сводка по корзине (каталог) + промокод + доставка.
 */
final class StorefrontOrderPreviewController extends Controller
{
    public function store(
        Request $request,
        PublicSystemCatalogService $catalog,
        StorefrontOrderQuoteService $quoteService,
    ): JsonResponse {
        /** @var User|null $user */
        $user = $request->attributes->get('storefront_user');
        if (! $user instanceof User) {
            return response()->json(['error' => 'Необходима авторизация'], 401);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:190'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.color' => ['nullable', 'string', 'max:80'],
            'items.*.size' => ['nullable', 'string', 'max:80'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        $q = $quoteService->quote(
            $user,
            $data['items'],
            $data['coupon_code'] ?? null,
            false,
            $catalog,
        );

        if (! ($q['ok'] ?? false)) {
            $code = $q['code'] ?? null;
            $payload = ['error' => $q['error'] ?? 'Ошибка расчёта'];
            if (($q['product_id'] ?? '') !== '') {
                $payload['product_id'] = $q['product_id'];
            }
            if ($code !== null) {
                $payload['code'] = $code;
            }

            return response()->json($payload, 422);
        }

        return response()->json([
            'subtotal_catalog_rub' => $q['subtotal_catalog_rub'],
            'discount_rub' => $q['discount_rub'],
            'subtotal_after_coupon_rub' => $q['subtotal_after_coupon_rub'],
            'delivery_amount' => $q['delivery_amount'],
            'delivery_provider' => $q['delivery_provider'],
            'delivery_type' => $q['delivery_type'],
            'total_amount' => $q['total_amount'],
            'coupon' => isset($q['coupon']) && $q['coupon'] !== null ? [
                'code' => $q['coupon']->code,
                'discount_type' => $q['coupon']->discount_type,
                'discount_value' => (int) $q['coupon']->discount_value,
                'name' => $q['coupon']->name,
            ] : null,
            'coupon_applied' => $q['coupon'] !== null,
        ]);
    }
}

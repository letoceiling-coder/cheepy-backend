<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Storefront\StorefrontDeliveryQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /api/v1/store/checkout — создание заказа и платежа для витрины (JWT покупателя).
 */
class StorefrontCheckoutController extends Controller
{
    private const FREE_DELIVERY_THRESHOLD_RUB = 3000;

    private const DELIVERY_FLAT_RUB = 299;

    public function store(
        Request $request,
        PublicSystemCatalogService $catalog,
        PaymentProviderManager $manager,
        StorefrontDeliveryQuoteService $deliveryQuotes,
    ): JsonResponse {
        $user = $request->attributes->get('storefront_user');
        if (! $user instanceof User) {
            return response()->json(['error' => 'Необходима авторизация'], 401);
        }

        $activeNames = $manager->getActiveProviderNames();
        if ($activeNames === []) {
            return response()->json(['error' => 'Платежи временно недоступны'], 503);
        }
        $allowedProviders = implode(',', $activeNames);
        $defaultProvider = $activeNames[0];

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:190'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.color' => ['nullable', 'string', 'max:80'],
            'items.*.size' => ['nullable', 'string', 'max:80'],
            'provider' => ['nullable', 'string', 'in:'.$allowedProviders],
        ]);

        $provider = strtolower((string) ($data['provider'] ?? $defaultProvider));

        $lines = [];
        foreach ($data['items'] as $row) {
            try {
                $sp = $catalog->findVisibleSystemProductByPublicId((string) $row['product_id']);
            } catch (\Throwable) {
                return response()->json([
                    'error' => 'Один из товаров недоступен для заказа',
                    'product_id' => $row['product_id'],
                ], 422);
            }
            $sp->loadMissing(['photos']);
            $unitRub = $catalog->priceForStorefront($sp);
            if ($unitRub <= 0) {
                return response()->json([
                    'error' => 'Для товара не задана цена',
                    'product_id' => $row['product_id'],
                ], 422);
            }
            $qty = (int) $row['quantity'];
            $lines[] = [
                'product' => $sp,
                'quantity' => $qty,
                'unit_rub' => $unitRub,
                'line_total_rub' => $unitRub * $qty,
                'color' => $row['color'] ?? null,
                'size' => $row['size'] ?? null,
            ];
        }

        $subtotalRub = (int) array_sum(array_column($lines, 'line_total_rub'));

        $cartLinesOnly = [];
        foreach ($lines as $l) {
            $cartLinesOnly[] = ['product' => $l['product'], 'quantity' => $l['quantity']];
        }

        $deliverySnapshot = [];
        $deliveryProvider = null;
        $deliveryType = 'flat';

        $qb = $deliveryQuotes->buildQuotesForCartLines($user, $cartLinesOnly);

        if ($qb['needs_address'] ?? false) {
            return response()->json([
                'error' => 'Добавьте адрес доставки в личном кабинете (раздел «Адреса доставки»), чтобы оформить заказ.',
                'code' => 'needs_delivery_address',
            ], 422);
        }

        if ($subtotalRub >= self::FREE_DELIVERY_THRESHOLD_RUB) {
            $deliveryRub = 0;
            $deliveryType = 'free_threshold';
            $deliverySnapshot = [
                'mode' => 'free_threshold',
                'threshold_rub' => self::FREE_DELIVERY_THRESHOLD_RUB,
            ];
        } else {
            $cheapest = $qb['cheapest_price_rub'] ?? null;
            if ($cheapest !== null) {
                $deliveryRub = max(0, (int) round((float) $cheapest));
                $cq = is_array($qb['cheapest_quote'] ?? null) ? $qb['cheapest_quote'] : null;
                $deliveryProvider = $cq ? (string) ($cq['integration'] ?? '') : null;
                if ($deliveryProvider === '') {
                    $deliveryProvider = null;
                }
                $deliveryType = $deliveryProvider ?: 'carrier';
                $deliverySnapshot = [
                    'mode' => 'integrations_min',
                    'integration' => $deliveryProvider,
                    'provider_title' => is_array($cq) ? ($cq['provider_title'] ?? null) : null,
                    'service_code' => is_array($cq) ? ($cq['service_code'] ?? null) : null,
                    'quoted_price_rub' => round((float) $cheapest, 2),
                    'threshold_rub' => self::FREE_DELIVERY_THRESHOLD_RUB,
                ];
            } else {
                $deliveryRub = self::DELIVERY_FLAT_RUB;
                $deliveryType = 'flat_fallback';
                $deliverySnapshot = [
                    'mode' => 'flat_fallback',
                    'flat_rub' => self::DELIVERY_FLAT_RUB,
                    'threshold_rub' => self::FREE_DELIVERY_THRESHOLD_RUB,
                    'reason' => 'no_carrier_quotes',
                ];
            }
        }

        $totalRub = $subtotalRub + $deliveryRub;
        $totalFloat = round($totalRub, 2);

        if ($totalRub <= 0) {
            return response()->json(['error' => 'Некорректная сумма заказа'], 422);
        }

        $returnToken = Str::random(32);

        try {
            $responsePayload = DB::transaction(function () use (
                $user,
                $lines,
                $subtotalRub,
                $deliveryRub,
                $deliveryProvider,
                $deliveryType,
                $deliverySnapshot,
                $totalRub,
                $totalFloat,
                $provider,
                $returnToken,
                $manager
            ) {
                $number = $this->generateUniqueOrderNumber();

                $order = CustomerOrder::create([
                    'user_id' => $user->id,
                    'number' => $number,
                    'status' => 'awaiting_payment',
                    'subtotal_amount' => $subtotalRub,
                    'discount_amount' => 0,
                    'delivery_amount' => $deliveryRub,
                    'bonus_spent_amount' => 0,
                    'total_amount' => $totalRub,
                    'currency' => 'RUB',
                    'payment_status' => 'pending',
                    'delivery_provider' => $deliveryProvider,
                    'delivery_type' => $deliveryType,
                    'delivery_snapshot' => array_merge($deliverySnapshot, [
                        'subtotal_snapshot_rub' => $subtotalRub,
                    ]),
                    'paid_at' => null,
                ]);

                foreach ($lines as $line) {
                    /** @var \App\Models\SystemProduct $sp */
                    $sp = $line['product'];
                    $thumb = $sp->photos->first()?->url;
                    $attrs = array_filter([
                        'color' => $line['color'],
                        'size' => $line['size'],
                    ], fn ($v) => $v !== null && $v !== '');

                    CustomerOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $sp->id,
                        'product_name' => $sp->name,
                        'product_image' => $thumb,
                        'sku' => null,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_rub'],
                        'total_price' => $line['line_total_rub'],
                        'attributes' => $attrs === [] ? null : $attrs,
                    ]);
                }

                $payment = Payment::create([
                    'api_key_id' => null,
                    'customer_order_id' => $order->id,
                    'amount' => $totalFloat,
                    'provider' => $provider,
                    'status' => 'pending',
                    'user_email' => $user->email,
                    'return_token' => $returnToken,
                ]);

                $providerService = $manager->getProvider($provider);
                $checkout = $providerService->createCheckout(null, $totalFloat, [
                    'payment_id' => $payment->id,
                    'return_token' => $returnToken,
                    'description' => 'Заказ '.$order->number,
                    'line_item_name' => 'Заказ '.$order->number,
                ]);

                $payment->update([
                    'provider_id' => $checkout['provider_id'] ?? null,
                ]);

                return [
                    'payment_id' => $payment->id,
                    'return_token' => $returnToken,
                    'provider' => $provider,
                    'provider_id' => $checkout['provider_id'] ?? null,
                    'checkout_url' => $checkout['checkout_url'] ?? null,
                    'order_id' => $order->id,
                    'order_number' => $order->number,
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Не удалось создать оплату'], 422);
        }

        if (empty($responsePayload['checkout_url'])) {
            return response()->json(['error' => 'Платёжный провайдер не вернул ссылку'], 422);
        }

        return response()->json($responsePayload);
    }

    private function generateUniqueOrderNumber(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $number = 'CH-'.strtoupper(Str::random(10));
            if (! CustomerOrder::query()->where('number', $number)->exists()) {
                return $number;
            }
        }

        return 'CH-'.strtoupper(Str::random(12));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Payment;
use App\Models\StorefrontCartSnapshot;
use App\Models\SystemProduct;
use App\Models\User;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Services\Marketing\MarketingProductEmailBlockBuilder;
use App\Services\Marketing\TransactionalMarketingMail;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Storefront\StorefrontOrderQuoteService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /api/v1/store/checkout — создание заказа и платежа для витрины (JWT покупателя).
 */
class StorefrontCheckoutController extends Controller
{
    public function store(
        Request $request,
        PublicSystemCatalogService $catalog,
        PaymentProviderManager $manager,
        StorefrontOrderQuoteService $quoteService,
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
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        $provider = strtolower((string) ($data['provider'] ?? $defaultProvider));

        try {
            $responsePayload = DB::transaction(function () use (
                $user,
                $data,
                $provider,
                $catalog,
                $quoteService,
                $manager
            ) {
                $quote = $quoteService->quote(
                    $user,
                    $data['items'],
                    $data['coupon_code'] ?? null,
                    true,
                    $catalog
                );

                if (! ($quote['ok'] ?? false)) {
                    return [
                        '__error' => true,
                        'error' => $quote['error'] ?? 'Ошибка расчёта заказа',
                        'code' => $quote['code'] ?? null,
                        'product_id' => $quote['product_id'] ?? null,
                    ];
                }

                $totalRub = (int) $quote['total_amount'];
                $totalFloat = round($totalRub, 2);

                if ($totalRub <= 0) {
                    return [
                        '__error' => true,
                        'error' => 'Некорректная сумма заказа',
                        'code' => null,
                        'product_id' => null,
                    ];
                }

                $discountRub = (int) $quote['discount_rub'];
                $couponSnapshot = $discountRub > 0 ? ($quote['coupon_snapshot'] ?? null) : null;
                $couponId = $discountRub > 0 && isset($quote['coupon']) && $quote['coupon'] !== null
                    ? $quote['coupon']->id
                    : null;

                $returnToken = Str::random(32);
                $number = $this->generateUniqueOrderNumber();

                $lines = $quote['lines'];

                $order = CustomerOrder::create([
                    'user_id' => $user->id,
                    'coupon_id' => $couponId,
                    'number' => $number,
                    'status' => 'awaiting_payment',
                    'subtotal_amount' => (int) $quote['subtotal_catalog_rub'],
                    'discount_amount' => $discountRub,
                    'coupon_snapshot' => $couponSnapshot,
                    'delivery_amount' => (int) $quote['delivery_amount'],
                    'bonus_spent_amount' => 0,
                    'total_amount' => $totalRub,
                    'currency' => 'RUB',
                    'payment_status' => 'pending',
                    'delivery_provider' => $quote['delivery_provider'],
                    'delivery_type' => $quote['delivery_type'],
                    'delivery_snapshot' => array_merge(
                        is_array($quote['delivery_snapshot_base'] ?? null) ? $quote['delivery_snapshot_base'] : [],
                        [
                            'subtotal_snapshot_rub' => (int) $quote['subtotal_catalog_rub'],
                            'discount_rub' => $discountRub,
                        ]
                    ),
                    'paid_at' => null,
                ]);

                foreach ($lines as $line) {
                    /** @var SystemProduct $sp */
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
                $feBase = FrontendUrl::base();
                $tokQ = $returnToken !== '' ? '&return_token='.urlencode($returnToken) : '';
                $checkout = $providerService->createCheckout(null, $totalFloat, [
                    'payment_id' => $payment->id,
                    'return_token' => $returnToken,
                    'description' => 'Заказ '.$order->number,
                    'line_item_name' => 'Заказ '.$order->number,
                    'success_url' => $feBase.'/payment/success?payment_id='.$payment->id.$tokQ,
                    'cancel_url' => $feBase.'/payment/fail?payment_id='.$payment->id.$tokQ,
                ]);

                $payment->update([
                    'provider_id' => $checkout['provider_id'] ?? null,
                ]);

                return [
                    '__error' => false,
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

            $payload = ['error' => 'Не удалось создать оплату'];
            if ($e instanceof \RuntimeException) {
                $detail = trim($e->getMessage());
                if ($detail !== '') {
                    $payload['details'] = $detail;
                }
            }

            return response()->json($payload, 422);
        }

        if (! empty($responsePayload['__error'])) {
            $body = ['error' => (string) ($responsePayload['error'] ?? 'Ошибка')];
            $c = $responsePayload['code'] ?? null;
            if ($c !== null && $c !== '') {
                $body['code'] = $c;
            }
            $pid = $responsePayload['product_id'] ?? null;
            if ($pid !== null && $pid !== '') {
                $body['product_id'] = $pid;
            }

            return response()->json($body, 422);
        }

        if (empty($responsePayload['checkout_url'])) {
            return response()->json(['error' => 'Платёжный провайдер не вернул ссылку'], 422);
        }

        unset($responsePayload['__error']);

        $orderId = (int) ($responsePayload['order_id'] ?? 0);
        if ($orderId > 0) {
            try {
                StorefrontCartSnapshot::query()->where('user_id', $user->id)->delete();

                $order = CustomerOrder::with('items')->find($orderId);
                if ($order !== null) {
                    /** @var MarketingProductEmailBlockBuilder $blocks */
                    $blocks = app(MarketingProductEmailBlockBuilder::class);
                    $productsHtml = $blocks->buildFromCustomerOrder($order);
                    $totalRub = (int) round((float) $order->total_amount);
                    $orderTotalFmt = number_format($totalRub, 0, ',', ' ').' ₽';
                    $feOrder = rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/');
                    app(TransactionalMarketingMail::class)->trySendTrigger('order_created', $user, [
                        'products_block' => $productsHtml,
                        'order_number' => (string) $order->number,
                        'order_total' => $orderTotalFmt,
                        'order_link' => $feOrder.'/person/order/'.$order->id,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
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

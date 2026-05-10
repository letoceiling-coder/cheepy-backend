<?php

namespace App\Services\Storefront;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Фиксация использования промокода после успешной оплаты (идемпотентно по order_id).
 */
final class StorefrontCouponRedemptionRecorder
{
    public function recordForPaymentIfNeeded(Payment $payment): void
    {
        $orderId = $payment->customer_order_id;
        if ($orderId === null) {
            return;
        }

        DB::transaction(function () use ($orderId): void {
            $order = CustomerOrder::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order || $order->coupon_id === null || (int) $order->discount_amount <= 0) {
                return;
            }

            if (CouponRedemption::query()->where('order_id', $order->id)->exists()) {
                return;
            }

            /** @var Coupon|null $coupon */
            $coupon = Coupon::query()->whereKey((int) $order->coupon_id)->lockForUpdate()->first();
            if (! $coupon) {
                return;
            }

            CouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'discount_amount' => (int) $order->discount_amount,
                'redeemed_at' => now(),
            ]);

            $coupon->increment('used_count');
        });
    }
}

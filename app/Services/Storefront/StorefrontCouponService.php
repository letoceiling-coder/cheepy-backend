<?php

namespace App\Services\Storefront;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerProfile;
use App\Models\User;

final class StorefrontCouponService
{
    public function normalizeCode(?string $code): ?string
    {
        $c = strtoupper(trim((string) $code));

        return $c === '' ? null : $c;
    }

    /**
     * Снимок промокода для заказа / аудита (поля БД могут измениться после создания заказа).
     *
     * @return array<string, mixed>
     */
    public function snapshotForOrder(Coupon $coupon): array
    {
        return [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (int) $coupon->discount_value,
            'target' => $coupon->target,
            'min_order_amount' => (int) $coupon->min_order_amount,
        ];
    }

    /**
     * @return array{discount_rub: int, coupon: ?Coupon, error: ?string}
     */
    public function resolveDiscount(User $user, ?string $codeRaw, int $subtotalRub, bool $lockForUpdate): array
    {
        $norm = $this->normalizeCode($codeRaw);
        if ($norm === null) {
            return ['discount_rub' => 0, 'coupon' => null, 'error' => null];
        }

        $query = Coupon::query()->whereRaw('UPPER(TRIM(code)) = ?', [$norm]);
        /** @var Coupon|null $coupon */
        $coupon = $lockForUpdate ? $query->lockForUpdate()->first() : $query->first();

        if (! $coupon) {
            return ['discount_rub' => 0, 'coupon' => null, 'error' => 'Промокод не найден'];
        }

        $err = $this->validateEligibility($user, $coupon, $subtotalRub);
        if ($err !== null) {
            return ['discount_rub' => 0, 'coupon' => null, 'error' => $err];
        }

        return [
            'discount_rub' => $this->computeDiscountRub($coupon, $subtotalRub),
            'coupon' => $coupon,
            'error' => null,
        ];
    }

    public function computeDiscountRub(Coupon $coupon, int $subtotalRub): int
    {
        if ($subtotalRub <= 0) {
            return 0;
        }

        if ($coupon->discount_type === 'percent') {
            $pct = max(0, min(100, (int) $coupon->discount_value));

            return (int) min($subtotalRub, (int) floor($subtotalRub * $pct / 100));
        }

        $fixed = max(0, (int) $coupon->discount_value);

        return (int) min($subtotalRub, $fixed);
    }

    private function validateEligibility(User $user, Coupon $coupon, int $subtotalRub): ?string
    {
        if (! $coupon->is_active) {
            return 'Промокод недоступен';
        }

        $now = now();
        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return 'Промокод ещё не активен';
        }

        if ($coupon->expires_at !== null) {
            $expiresEnd = $coupon->expires_at->copy()->endOfDay();
            if ($now->gt($expiresEnd)) {
                return 'Промокод истёк';
            }
        }

        $min = max(0, (int) $coupon->min_order_amount);
        if ($subtotalRub < $min) {
            return 'Не достигнута минимальная сумма заказа для этого промокода ('.number_format($min, 0, ',', '').' ₽)';
        }

        $target = strtolower(trim((string) ($coupon->target ?: 'all')));
        if ($target === 'new') {
            $paidOrders = CustomerOrder::query()
                ->where('user_id', $user->id)
                ->where('payment_status', 'paid')
                ->exists();
            if ($paidOrders) {
                return 'Промокод только для первого оплаченного заказа';
            }
        } elseif ($target === 'vip') {
            $profile = CustomerProfile::query()->where('user_id', $user->id)->first();
            $vip = (bool) data_get($profile?->preferences, 'vip');
            if (! $vip) {
                return 'Промокод недоступен для вашего аккаунта';
            }
        }

        $maxUses = $coupon->max_uses;
        if ($maxUses !== null && $maxUses >= 1) {
            $paidGlobal = $this->paidRedemptionsCountForCoupon($coupon->id);
            if ($paidGlobal >= $maxUses) {
                return 'Лимит использований промокода исчерпан';
            }
        }

        $maxPerUser = (int) ($coupon->max_uses_per_user ?: 1);
        if ($maxPerUser < 1) {
            $maxPerUser = 1;
        }
        $paidForUser = $this->paidRedemptionsCountForCouponAndUser($coupon->id, $user->id);
        if ($paidForUser >= $maxPerUser) {
            return 'Вы уже использовали этот промокод';
        }

        return null;
    }

    private function paidRedemptionsCountForCoupon(int $couponId): int
    {
        return CouponRedemption::query()
            ->where('coupon_id', $couponId)
            ->whereHas('order', static function ($q): void {
                /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\CustomerOrder> $q */
                $q->where('payment_status', 'paid');
            })
            ->count();
    }

    private function paidRedemptionsCountForCouponAndUser(int $couponId, int $userId): int
    {
        return CouponRedemption::query()
            ->where('coupon_id', $couponId)
            ->where('user_id', $userId)
            ->whereHas('order', static function ($q): void {
                /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\CustomerOrder> $q */
                $q->where('payment_status', 'paid');
            })
            ->count();
    }
}

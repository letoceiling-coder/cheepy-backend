<?php

namespace App\Services\Storefront;

use App\Models\BonusRule;
use App\Models\CustomerOrder;
use App\Models\ReferralEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Начисление бонусных рублей пригласившему после первой оплаты приглашённого.
 */
final class StorefrontReferralRewardService
{
    public function ensureDefaultRuleRow(): void
    {
        BonusRule::query()->firstOrCreate(
            ['key' => 'referral_reward'],
            [
                'title' => 'Реферальное вознаграждение',
                'is_active' => true,
                'config' => [
                    'referrer_reward_rub' => 500,
                ],
            ]
        );
    }

    public function referrerRewardRub(): int
    {
        $this->ensureDefaultRuleRow();
        $row = BonusRule::query()->where('key', 'referral_reward')->where('is_active', true)->first();
        if ($row === null || ! is_array($row->config)) {
            return 500;
        }

        return max(0, (int) data_get($row->config, 'referrer_reward_rub', 500));
    }

    public function grantReferrerBonusAfterFirstPaidOrder(CustomerOrder $order): void
    {
        $amount = $this->referrerRewardRub();
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($order, $amount): void {
            $paidCount = CustomerOrder::query()
                ->where('user_id', $order->user_id)
                ->where('payment_status', 'paid')
                ->lockForUpdate()
                ->count();

            if ($paidCount !== 1) {
                return;
            }

            /** @var ReferralEvent|null $ev */
            $ev = ReferralEvent::query()
                ->where('referred_user_id', $order->user_id)
                ->where('event_type', 'registration')
                ->whereNull('reward_granted_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($ev === null) {
                return;
            }

            $referrer = User::query()->whereKey($ev->referrer_user_id)->first();
            if (! $referrer) {
                return;
            }

            app(CustomerWalletService::class)->credit(
                $referrer,
                $amount,
                'referral_bonus',
                'Бонус за первый оплаченный заказ приглашённого (заказ №'.(string) $order->number.')',
                [
                    'customer_order_id' => $order->id,
                    'referred_user_id' => $order->user_id,
                    'referral_event_id' => $ev->id,
                ],
            );

            $ev->forceFill([
                'reward_amount' => $amount,
                'reward_granted_at' => now(),
            ])->save();
        });
    }
}

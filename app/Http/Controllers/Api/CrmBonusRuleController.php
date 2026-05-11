<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonusRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmBonusRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->ensureDefaults();

        return response()->json([
            'data' => BonusRule::query()->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $rule = BonusRule::query()->where('key', $key)->firstOrFail();
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'config' => ['nullable', 'array'],
        ]);
        $rule->update($data);

        return response()->json($rule->refresh());
    }

    private function ensureDefaults(): void
    {
        $defaults = [
            'purchase_bonus' => [
                'title' => 'Бонусы за покупки',
                'config' => [
                    'eligible_share_percent' => 30,
                    'min_product_amount' => 1000,
                    'bonus_percent' => 5,
                    'random_launch_months' => 2,
                    'disable_accrual_when_bonus_spent' => true,
                ],
            ],
            'review_bonus' => [
                'title' => 'Бонусы за отзывы',
                'config' => [
                    'amount' => 25,
                    'min_product_amount' => 500,
                    'requires_purchase' => true,
                ],
            ],
            'mini_game_bonus' => [
                'title' => 'Бонусы за мини-игры',
                'config' => [
                    'requires_validated_prize_event' => true,
                ],
            ],
            'seller_bonus' => [
                'title' => 'Бонусы от продавца',
                'config' => [
                    'enabled_for_seller_campaigns' => false,
                ],
            ],
            'referral_reward' => [
                'title' => 'Реферальное вознаграждение',
                'config' => [
                    'referrer_reward_rub' => 500,
                ],
            ],
        ];

        foreach ($defaults as $key => $row) {
            BonusRule::query()->firstOrCreate(
                ['key' => $key],
                ['title' => $row['title'], 'is_active' => true, 'config' => $row['config']]
            );
        }
    }
}

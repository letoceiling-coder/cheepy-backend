<?php

namespace App\Services\Marketing;

use App\Models\CustomerProfile;
use App\Models\SystemProduct;
use App\Models\User;
use Illuminate\Support\Arr;

/** Подбор товаров по сохранённым интересам (категории) в профиле — до 5 шт., случайно. */
class MarketingInterestProductBlock
{
    public function htmlBlockForUser(User $user): string
    {
        $profile = CustomerProfile::query()->where('user_id', $user->id)->first();
        $ids = [];
        if ($profile !== null && is_array($profile->preferences)) {
            $ids = Arr::wrap($profile->preferences['catalog_category_ids'] ?? []);
            $ids = array_values(array_filter(array_map('intval', $ids), fn ($x) => $x > 0));
            $ids = array_slice(array_unique($ids), 0, 40);
        }

        $pick = collect();
        if ($ids !== []) {
            $pick = SystemProduct::query()
                ->whereIn('status', [SystemProduct::STATUS_APPROVED, SystemProduct::STATUS_PUBLISHED])
                ->whereIn('category_id', $ids)
                ->with(['photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
                ->inRandomOrder()
                ->limit(5)
                ->get();
        }

        if ($pick->isEmpty()) {
            $pick = SystemProduct::query()
                ->whereIn('status', [SystemProduct::STATUS_APPROVED, SystemProduct::STATUS_PUBLISHED])
                ->with(['photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
                ->inRandomOrder()
                ->limit(5)
                ->get();
        }

        $lines = [];
        foreach ($pick as $p) {
            $lines[] = ['product' => $p, 'quantity' => 1];
        }

        return app(MarketingProductEmailBlockBuilder::class)->buildFromCheckoutLines($lines);
    }
}

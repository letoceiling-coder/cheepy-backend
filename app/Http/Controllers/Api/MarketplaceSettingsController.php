<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use App\Services\MarketplaceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceSettingsController extends Controller
{
    public function __construct(private MarketplaceSettingsService $settings)
    {
    }

    public function publicSettings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->publicSettings()]);
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->all(),
            'categories' => $this->categoryTree(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketplace_name' => ['sometimes', 'string', 'max:255'],
            'support_emails' => ['sometimes', 'array'],
            'support_emails.*.email' => ['nullable', 'email', 'max:255'],
            'support_emails.*.description' => ['nullable', 'string', 'max:255'],
            'support_phones' => ['sometimes', 'array'],
            'support_phones.*.phone' => ['nullable', 'string', 'max:64'],
            'support_phones.*.description' => ['nullable', 'string', 'max:255'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'maintenance_enabled' => ['sometimes', 'boolean'],
            'maintenance_delay_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'seller_registration_enabled' => ['sometimes', 'boolean'],
            'default_commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'category_commissions' => ['sometimes', 'array'],
        ]);

        return response()->json([
            'message' => 'Настройки маркетплейса сохранены',
            'data' => $this->settings->update($data),
            'categories' => $this->categoryTree(),
        ]);
    }

    public function refreshCurrencies(): JsonResponse
    {
        return response()->json(['data' => $this->settings->refreshCurrencyRates()]);
    }

    private function categoryTree(): array
    {
        $rows = CatalogCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'sort_order', 'is_active']);
        $byParent = $rows->groupBy(fn (CatalogCategory $c) => (string) ($c->parent_id ?? 0));

        $build = function (?int $parentId) use (&$build, $byParent): array {
            return ($byParent[(string) ($parentId ?? 0)] ?? collect())
                ->map(fn (CatalogCategory $c) => [
                    'id' => (int) $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
                    'children' => $build((int) $c->id),
                ])
                ->values()
                ->all();
        };

        return $build(null);
    }
}

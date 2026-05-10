<?php

namespace App\Services\Storefront;

use App\Models\Coupon;
use App\Models\User;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Services\MarketplaceSettingsService;

/**
 * Единый расчёт корзины каталога: доставка + промокод (для превью в корзине и для checkout).
 */
final class StorefrontOrderQuoteService
{
    private const DELIVERY_FLAT_RUB = 299;

    public function __construct(
        private readonly StorefrontCouponService $couponService,
        private readonly StorefrontDeliveryQuoteService $deliveryQuotes,
        private readonly MarketplaceSettingsService $marketplaceSettings,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $validatedRows  rows like checkout validate
     * @return array<string, mixed>
     */
    public function quote(
        User $user,
        array $validatedRows,
        ?string $couponCodeRaw,
        bool $lockCoupon,
        PublicSystemCatalogService $catalog,
    ): array {
        $built = $this->buildLines($catalog, $validatedRows);
        if (! ($built['ok'] ?? false)) {
            return array_merge($built, ['ok' => false]);
        }

        /** @var list<array<string, mixed>> $lines */
        $lines = $built['lines'];
        $subtotalRub = (int) array_sum(array_column($lines, 'line_total_rub'));

        $cartLinesOnly = [];
        foreach ($lines as $l) {
            $cartLinesOnly[] = ['product' => $l['product'], 'quantity' => $l['quantity']];
        }

        $couponResolution = $this->couponService->resolveDiscount($user, $couponCodeRaw, $subtotalRub, $lockCoupon);
        if ($couponResolution['error']) {
            return [
                'ok' => false,
                'error' => $couponResolution['error'],
                'code' => 'invalid_coupon',
            ];
        }

        /** @var Coupon|null $coupon */
        $coupon = $couponResolution['coupon'];
        $discountRub = (int) $couponResolution['discount_rub'];
        $subtotalAfterCoupon = max(0, $subtotalRub - $discountRub);

        $qb = $this->deliveryQuotes->buildQuotesForCartLines($user, $cartLinesOnly);

        if ($qb['needs_address'] ?? false) {
            return [
                'ok' => false,
                'error' => 'Добавьте адрес доставки в личном кабинете (раздел «Адреса доставки»), чтобы оформить заказ.',
                'code' => 'needs_delivery_address',
            ];
        }

        $freeThresholdRub = $this->marketplaceSettings->effectiveFreeDeliveryThresholdRub();
        $snapshotThreshold = $freeThresholdRub !== null ? ['threshold_rub' => $freeThresholdRub] : [];

        if ($freeThresholdRub !== null && $subtotalAfterCoupon >= $freeThresholdRub) {
            $deliveryRub = 0;
            $deliveryType = 'free_threshold';
            $deliverySnapshot = array_merge([
                'mode' => 'free_threshold',
            ], $snapshotThreshold);
            $deliveryProvider = null;
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
                $deliverySnapshot = array_merge([
                    'mode' => 'integrations_min',
                    'integration' => $deliveryProvider,
                    'provider_title' => is_array($cq) ? ($cq['provider_title'] ?? null) : null,
                    'service_code' => is_array($cq) ? ($cq['service_code'] ?? null) : null,
                    'quoted_price_rub' => round((float) $cheapest, 2),
                ], $snapshotThreshold);
            } else {
                $deliveryRub = self::DELIVERY_FLAT_RUB;
                $deliveryType = 'flat_fallback';
                $deliverySnapshot = array_merge([
                    'mode' => 'flat_fallback',
                    'flat_rub' => self::DELIVERY_FLAT_RUB,
                    'reason' => 'no_carrier_quotes',
                ], $snapshotThreshold);
                $deliveryProvider = null;
            }
        }

        $totalRub = $subtotalAfterCoupon + $deliveryRub;

        return [
            'ok' => true,
            'lines' => $lines,
            'coupon' => $coupon,
            'coupon_snapshot' => $coupon ? $this->couponService->snapshotForOrder($coupon) : null,
            'subtotal_catalog_rub' => $subtotalRub,
            'discount_rub' => $discountRub,
            'subtotal_after_coupon_rub' => $subtotalAfterCoupon,
            'delivery_amount' => $deliveryRub,
            'delivery_provider' => $deliveryProvider,
            'delivery_type' => $deliveryType,
            'delivery_snapshot_base' => array_merge($deliverySnapshot, [
                'subtotal_catalog_rub' => $subtotalRub,
                'subtotal_after_coupon_rub' => $subtotalAfterCoupon,
                'coupon_code' => $coupon?->code,
            ]),
            'total_amount' => $totalRub,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $validatedRows
     * @return array{ok: bool, lines?: list<array<string, mixed>>, error?: string, product_id?: string}
     */
    private function buildLines(PublicSystemCatalogService $catalog, array $validatedRows): array
    {
        $lines = [];
        foreach ($validatedRows as $row) {
            try {
                $sp = $catalog->findVisibleSystemProductByPublicId((string) $row['product_id']);
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'error' => 'Один из товаров недоступен для заказа',
                    'product_id' => (string) $row['product_id'],
                ];
            }
            $sp->loadMissing(['photos']);
            $unitRub = $catalog->priceForStorefront($sp);
            if ($unitRub <= 0) {
                return [
                    'ok' => false,
                    'error' => 'Для товара не задана цена',
                    'product_id' => (string) $row['product_id'],
                ];
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

        return ['ok' => true, 'lines' => $lines];
    }
}

<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\SystemProduct;

/**
 * Detects when donor product changes and marks linked system_products as needs_review.
 */
class DonorSyncAwarenessService
{
    /**
     * Call when a donor product (products) is updated (e.g. by parser).
     * Compares products.updated_at with product_sources.donor_updated_at.
     * If donor changed: mark system_product status = needs_review.
     */
    public function onDonorProductUpdated(Product $product): void
    {
        $sources = ProductSource::where('donor_product_id', $product->id)->with('systemProduct')->get();

        if ($sources->isEmpty()) {
            return;
        }

        $donorUpdatedAt = $product->updated_at;

        foreach ($sources as $source) {
            $lastSeen = $source->donor_updated_at;
            $systemProduct = $source->systemProduct;
            if ($systemProduct === null) {
                continue;
            }

            if ($lastSeen === null || $donorUpdatedAt->gt($lastSeen)) {
                // Не снимаем published автоматически — витрина остаётся стабильной; фиксируем дрейф донора.
                if ($systemProduct->status !== SystemProduct::STATUS_PUBLISHED) {
                    $systemProduct->update(['status' => SystemProduct::STATUS_NEEDS_REVIEW]);
                }
            }

            $source->update(['donor_updated_at' => $donorUpdatedAt]);
        }
    }
}

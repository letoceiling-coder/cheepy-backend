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
     * Поля контента донора, изменение которых должно отражаться в CRM.
     * Технические поля (parsed_at, photos_downloaded, photos_count) исключены —
     * иначе каждый прогон парсера двигал бы updated_at и сбрасывал бы все связанные
     * system_products в needs_review без реальных изменений.
     */
    private const CONTENT_FIELDS = ['title', 'price', 'price_raw', 'description', 'photos', 'color', 'size_range', 'characteristics'];

    /**
     * Call when a donor product (products) is updated (e.g. by parser).
     * Marks linked system_products as needs_review only when actual content fields changed.
     */
    public function onDonorProductUpdated(Product $product): void
    {
        $contentChanged = $product->wasChanged(self::CONTENT_FIELDS);

        $sources = ProductSource::where('donor_product_id', $product->id)->with('systemProduct')->get();

        if ($sources->isEmpty()) {
            return;
        }

        $donorUpdatedAt = $product->updated_at;

        foreach ($sources as $source) {
            $systemProduct = $source->systemProduct;
            if ($systemProduct === null) {
                continue;
            }

            if ($contentChanged && $systemProduct->status !== SystemProduct::STATUS_PUBLISHED) {
                // Не снимаем published автоматически — витрина остаётся стабильной; фиксируем дрейф донора.
                $systemProduct->update(['status' => SystemProduct::STATUS_NEEDS_REVIEW]);
            }

            // donor_updated_at двигаем всегда, чтобы было видно «когда последний раз парсер видел донора».
            $source->update(['donor_updated_at' => $donorUpdatedAt]);
        }
    }
}

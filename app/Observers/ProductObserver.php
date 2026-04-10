<?php

namespace App\Observers;

use App\Jobs\EnsureSystemProductFromDonorJob;
use App\Models\Product;
use App\Models\ProductSource;
use App\Services\Catalog\DonorSyncAwarenessService;

class ProductObserver
{
    public function __construct(
        private DonorSyncAwarenessService $awarenessService
    ) {}

    public function created(Product $product): void
    {
        EnsureSystemProductFromDonorJob::dispatch($product->id);
    }

    public function updated(Product $product): void
    {
        $this->awarenessService->onDonorProductUpdated($product);

        if (! filter_var(config('cheepy_catalog.auto_ingest_from_parser'), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $hasParserSource = ProductSource::query()
            ->where('donor_product_id', $product->id)
            ->where('source', ProductSource::SOURCE_PARSER)
            ->exists();

        if (! $hasParserSource) {
            EnsureSystemProductFromDonorJob::dispatch($product->id);
        }
    }
}

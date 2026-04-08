<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Catalog\DonorSyncAwarenessService;

class ProductObserver
{
    public function __construct(
        private DonorSyncAwarenessService $awarenessService
    ) {}

    public function updated(Product $product): void
    {
        $this->awarenessService->onDonorProductUpdated($product);
    }
}

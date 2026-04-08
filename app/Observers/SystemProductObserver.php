<?php

namespace App\Observers;

use App\Models\SystemProduct;
use App\Services\Catalog\ProductCache;

class SystemProductObserver
{
    public function created(SystemProduct $product): void
    {
        $this->invalidate($product);
    }

    public function updated(SystemProduct $product): void
    {
        $this->invalidate($product);
    }

    public function deleted(SystemProduct $product): void
    {
        $this->invalidate($product);
    }

    private function invalidate(SystemProduct $product): void
    {
        $tags = $product->category_id !== null ? ['category_' . $product->category_id] : [];
        ProductCache::flushTags($tags);
    }
}

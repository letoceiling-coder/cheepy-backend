<?php

namespace App\Observers;

use App\Models\SystemProductAttribute;
use App\Services\Catalog\ProductCache;

class SystemProductAttributeObserver
{
    public function created(SystemProductAttribute $attribute): void
    {
        $this->invalidate($attribute);
    }

    public function updated(SystemProductAttribute $attribute): void
    {
        $this->invalidate($attribute);
    }

    public function deleted(SystemProductAttribute $attribute): void
    {
        $this->invalidate($attribute);
    }

    private function invalidate(SystemProductAttribute $attribute): void
    {
        $product = $attribute->systemProduct;
        if ($product === null) {
            return;
        }
        ProductCache::flushTags(['product_' . $product->id]);
        if ($product->category_id !== null) {
            ProductCache::flushTags(['category_' . $product->category_id]);
        }
    }
}

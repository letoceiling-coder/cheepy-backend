<?php

namespace App\Listeners;

use App\Events\CatalogCategoryUpdated;
use Illuminate\Support\Facades\Log;

class LogCatalogCategoryUpdated
{
    public function handle(CatalogCategoryUpdated $event): void
    {
        Log::channel('single')->info('catalog.category.updated', [
            'catalog_category_id' => $event->category->id,
            'slug' => $event->category->slug,
        ]);
    }
}

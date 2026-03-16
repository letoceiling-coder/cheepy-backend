<?php

namespace App\Listeners;

use App\Events\CatalogCategoryCreated;
use Illuminate\Support\Facades\Log;

class LogCatalogCategoryCreated
{
    public function handle(CatalogCategoryCreated $event): void
    {
        Log::channel('single')->info('catalog.category.created', [
            'catalog_category_id' => $event->category->id,
            'slug' => $event->category->slug,
        ]);
    }
}

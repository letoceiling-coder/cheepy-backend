<?php

namespace App\Listeners;

use App\Events\CatalogCategoryDeleted;
use Illuminate\Support\Facades\Log;

class LogCatalogCategoryDeleted
{
    public function handle(CatalogCategoryDeleted $event): void
    {
        Log::channel('single')->info('catalog.category.deleted', [
            'catalog_category_id' => $event->category->id,
            'slug' => $event->category->slug,
        ]);
    }
}

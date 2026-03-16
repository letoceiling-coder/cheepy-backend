<?php

namespace App\Listeners;

use App\Events\CatalogMappingCreated;
use Illuminate\Support\Facades\Log;

class LogCatalogMappingCreated
{
    public function handle(CatalogMappingCreated $event): void
    {
        Log::channel('single')->info('catalog.mapping.created', [
            'mapping_id' => $event->mapping->id,
            'donor_category_id' => $event->mapping->donor_category_id,
            'catalog_category_id' => $event->mapping->catalog_category_id,
        ]);
    }
}

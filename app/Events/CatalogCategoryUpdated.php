<?php

namespace App\Events;

use App\Models\CatalogCategory;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CatalogCategoryUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public CatalogCategory $category
    ) {}
}

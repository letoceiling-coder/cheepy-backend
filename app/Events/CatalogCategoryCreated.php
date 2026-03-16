<?php

namespace App\Events;

use App\Models\CatalogCategory;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CatalogCategoryCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public CatalogCategory $category
    ) {}
}

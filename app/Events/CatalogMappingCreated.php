<?php

namespace App\Events;

use App\Models\CategoryMapping;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CatalogMappingCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public CategoryMapping $mapping
    ) {}
}

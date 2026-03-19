<?php

namespace App\Observers;

use App\Jobs\AutoMapDonorCategoryJob;
use App\Models\DonorCategory;

class DonorCategoryObserver
{
    public function created(DonorCategory $donorCategory): void
    {
        AutoMapDonorCategoryJob::dispatch($donorCategory->id);
    }
}

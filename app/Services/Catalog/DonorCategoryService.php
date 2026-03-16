<?php

namespace App\Services\Catalog;

use App\Models\DonorCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DonorCategoryService
{
    public function listPaginated(int $perPage = 50): LengthAwarePaginator
    {
        return DonorCategory::with('parent', 'mapping.catalogCategory')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function getTree(): Collection
    {
        return DonorCategory::whereNull('parent_id')
            ->with('children')
            ->orderBy('name')
            ->get();
    }

    public function findById(int $id): ?DonorCategory
    {
        return DonorCategory::with('mapping.catalogCategory')->find($id);
    }
}

<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\CategoryMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CategoryMappingService
{
    public function listPaginated(int $perPage = 50): LengthAwarePaginator
    {
        return CategoryMapping::with('donorCategory', 'catalogCategory')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function create(array $data): CategoryMapping
    {
        $mapping = CategoryMapping::create($data);
        event(new \App\Events\CatalogMappingCreated($mapping));
        return $mapping;
    }

    public function delete(CategoryMapping $mapping): void
    {
        $mapping->delete();
    }

    public function resolveCatalogCategoryId(int $donorCategoryId): ?int
    {
        $mapping = CategoryMapping::where('donor_category_id', $donorCategoryId)->first();
        return $mapping?->catalog_category_id;
    }

    public function resolveCatalogCategory(int $donorCategoryId): ?CatalogCategory
    {
        $mapping = CategoryMapping::with('catalogCategory')
            ->where('donor_category_id', $donorCategoryId)
            ->first();
        return $mapping?->catalogCategory;
    }
}

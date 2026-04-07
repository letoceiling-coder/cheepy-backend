<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogCategoryService
{
    public function getTree(): Collection
    {
        return CatalogCategory::whereNull('parent_id')
            ->where('is_active', true)
            ->with('children')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function listPaginated(int $perPage = 50): LengthAwarePaginator
    {
        $productsCountSub = DB::table('system_products')
            ->selectRaw('count(*)')
            ->whereColumn('system_products.category_id', 'catalog_categories.id');

        return CatalogCategory::with('parent')
            ->selectRaw('catalog_categories.*')
            ->selectSub($productsCountSub, 'products_count')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): CatalogCategory
    {
        $category = CatalogCategory::create($data);
        event(new \App\Events\CatalogCategoryCreated($category));
        return $category;
    }

    public function update(CatalogCategory $category, array $data): CatalogCategory
    {
        $category->update($data);
        event(new \App\Events\CatalogCategoryUpdated($category));
        return $category->fresh();
    }

    public function delete(CatalogCategory $category): void
    {
        event(new \App\Events\CatalogCategoryDeleted($category));
        $category->delete();
    }

    public function findById(int $id): ?CatalogCategory
    {
        return CatalogCategory::find($id);
    }
}

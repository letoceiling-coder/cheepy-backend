<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\CategoryMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Collection;

class CategoryMappingService
{
    /**
     * @param  ?int  $minConfidence  Applied when > 0 (query param was truthy).
     * @param  ?string  $status  "unmapped" → empty page (use suggestions API); "mapped" or null → list rows.
     */
    public function listPaginated(int $perPage = 50, ?int $minConfidence = null, ?string $status = null): LengthAwarePaginator
    {
        if ($status === 'unmapped') {
            $page = max(1, (int) request()->get('page', 1));

            return new ConcretePaginator(
                new Collection,
                0,
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        $query = CategoryMapping::with('donorCategory', 'catalogCategory')->orderBy('id');

        if ($minConfidence !== null && $minConfidence > 0) {
            $query->where('confidence', '>=', $minConfidence);
        }

        return $query->paginate($perPage);
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

    /**
     * Auto-mapping pipeline: insert or update non-manual mapping only.
     * Caller must verify no manual mapping exists.
     */
    public function applyAutomaticMapping(
        int $donorCategoryId,
        int $catalogCategoryId,
        int $confidence,
        ?CategoryMapping $existing = null,
    ): CategoryMapping {
        $existing ??= CategoryMapping::query()->where('donor_category_id', $donorCategoryId)->first();

        $payload = [
            'donor_category_id' => $donorCategoryId,
            'catalog_category_id' => $catalogCategoryId,
            'confidence' => $confidence,
            'is_manual' => false,
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return $this->create($payload);
    }
}

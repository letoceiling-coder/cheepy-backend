<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\Category;

class CatalogSyncService
{
    protected int $created = 0;

    protected int $updated = 0;

    protected int $skipped = 0;

    /**
     * Sync categories (source) into catalog_categories (target).
     * Parent categories are processed before children. Slug is the unique key.
     * Never deletes records.
     */
    public function sync(bool $dryRun = false): array
    {
        $this->created = 0;
        $this->updated = 0;
        $this->skipped = 0;

        $categories = Category::query()
            ->orderByRaw('COALESCE(parent_id, 0) ASC')
            ->orderBy('id')
            ->get();

        /** @var array<int, string> slug by source category id (for parent lookup) */
        $slugById = $categories->keyBy('id')->map->slug->all();

        foreach ($categories as $category) {
            $catalogParentId = null;
            if ($category->parent_id) {
                $parentSlug = $slugById[$category->parent_id] ?? null;
                if ($parentSlug) {
                    $parent = CatalogCategory::where('slug', $parentSlug)->first();
                    $catalogParentId = $parent?->id;
                }
            }

            $data = [
                'name' => $category->name,
                'slug' => $category->slug,
                'parent_id' => $catalogParentId,
                'sort_order' => (int) $category->sort_order,
                'icon' => $category->icon,
                'is_active' => true,
            ];

            if ($dryRun) {
                $exists = CatalogCategory::where('slug', $category->slug)->exists();
                if ($exists) {
                    $this->updated++;
                } else {
                    $this->created++;
                }
                continue;
            }

            $model = CatalogCategory::updateOrCreate(['slug' => $category->slug], $data);
            if ($model->wasRecentlyCreated) {
                $this->created++;
            } else {
                $this->updated++;
            }
        }

        return [
            'total' => $categories->count(),
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
        ];
    }
}

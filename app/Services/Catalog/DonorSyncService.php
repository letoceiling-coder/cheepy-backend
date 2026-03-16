<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\DonorCategory;

class DonorSyncService
{
    protected int $created = 0;

    protected int $updated = 0;

    /**
     * Sync categories (source) into donor_categories (target).
     * Uses external_id (source categories.id) as unique key. Parents before children.
     * Never deletes records. Does not touch category_mapping.
     */
    public function sync(): array
    {
        $this->created = 0;
        $this->updated = 0;

        $categories = Category::query()
            ->orderByRaw('COALESCE(parent_id, 0) ASC')
            ->orderBy('id')
            ->get();

        /** @var array<int, int> source category id => donor_categories.id (for parent lookup) */
        $donorIdBySourceId = [];

        foreach ($categories as $category) {
            $donorParentId = null;
            if ($category->parent_id && isset($donorIdBySourceId[$category->parent_id])) {
                $donorParentId = $donorIdBySourceId[$category->parent_id];
            }

            $externalId = (string) $category->id;

            $data = [
                'name' => $category->name,
                'slug' => $category->slug,
                'parent_id' => $donorParentId,
                'source_url' => $category->url,
                'parser_enabled' => (bool) $category->enabled,
            ];

            $model = DonorCategory::updateOrCreate(['external_id' => $externalId], $data);

            if ($model->wasRecentlyCreated) {
                $this->created++;
            } else {
                $this->updated++;
            }

            $donorIdBySourceId[$category->id] = $model->id;
        }

        return [
            'total' => $categories->count(),
            'created' => $this->created,
            'updated' => $this->updated,
        ];
    }
}

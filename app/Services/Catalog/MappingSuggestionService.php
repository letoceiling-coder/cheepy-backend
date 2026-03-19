<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\DonorCategory;
use Illuminate\Support\Collection;

class MappingSuggestionService
{
    /**
     * Suggest best catalog category match for each donor category.
     * Does not create or modify category_mapping.
     *
     * @return array<int, array{donor_id: int, donor_name: string, catalog_id: int, catalog_name: string, score: int}>
     */
    public function suggest(int $limit = 100): array
    {
        $donors = DonorCategory::with('parent')->orderBy('id')->get();
        $catalogs = CatalogCategory::with('parent')->get();

        $suggestions = [];
        $count = 0;

        foreach ($donors as $donor) {
            if ($count >= $limit) {
                break;
            }

            $best = $this->findBestMatch($donor, $catalogs);
            if ($best !== null) {
                $suggestions[] = [
                    'donor_id' => $donor->id,
                    'donor_name' => $donor->name,
                    'catalog_id' => $best['catalog']->id,
                    'catalog_name' => $best['catalog']->name,
                    'score' => $best['score'],
                ];
                $count++;
            }
        }

        return $suggestions;
    }

    /**
     * Single-donor suggestion for AutoMappingService (same scoring as bulk suggest).
     *
     * @return array{catalog_category_id: int, confidence: int}|null
     */
    public function suggestForDonorCategory(DonorCategory $donor): ?array
    {
        $donor->loadMissing('parent');
        $catalogs = CatalogCategory::with('parent')->get();
        $best = $this->findBestMatch($donor, $catalogs);
        if ($best === null) {
            return null;
        }

        return [
            'catalog_category_id' => (int) $best['catalog']->id,
            'confidence' => (int) $best['score'],
        ];
    }

    /**
     * @param  Collection<int, CatalogCategory>  $catalogs
     * @return array{catalog: CatalogCategory, score: int}|null
     */
    private function findBestMatch(DonorCategory $donor, Collection $catalogs): ?array
    {
        $bestCatalog = null;
        $bestScore = -1;

        foreach ($catalogs as $catalog) {
            $score = $this->scoreMatch($donor, $catalog);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCatalog = $catalog;
            }
        }

        if ($bestCatalog === null || $bestScore < 0) {
            return null;
        }

        return ['catalog' => $bestCatalog, 'score' => $bestScore];
    }

    private function scoreMatch(DonorCategory $donor, CatalogCategory $catalog): int
    {
        // 1. Exact slug match → 100
        if ($donor->slug === $catalog->slug) {
            return 100;
        }

        // 2. Similarity on names (0–100)
        similar_text($donor->name, $catalog->name, $percent);
        $score = (int) round($percent);

        // 3. Parent names match → +10 (cap at 100)
        $donorParentName = $donor->relationLoaded('parent') && $donor->parent
            ? $donor->parent->name
            : null;
        $catalogParentName = $catalog->relationLoaded('parent') && $catalog->parent
            ? $catalog->parent->name
            : null;

        if ($donorParentName !== null && $catalogParentName !== null) {
            similar_text($donorParentName, $catalogParentName, $parentPercent);
            if ($parentPercent >= 80.0) {
                $score = min(100, $score + 10);
            }
        }

        return $score;
    }
}

<?php

namespace App\Services\Catalog;

use App\Models\CategoryMapping;

class MappingApplyService
{
    private const MIN_SCORE = 95;

    /**
     * Apply high-confidence suggestions to category_mapping.
     * Only score >= 95; does not overwrite existing mappings; does not delete.
     * Does not modify donor_categories or catalog_categories.
     *
     * @return array{total: int, created: int, skipped: int}
     */
    public function applyAuto(int $limit = 500): array
    {
        $suggestionService = app(MappingSuggestionService::class);
        $suggestions = $suggestionService->suggest($limit);

        $candidates = array_filter($suggestions, fn (array $s) => $s['score'] >= self::MIN_SCORE);
        $total = count($candidates);

        $created = 0;
        $skipped = 0;

        foreach ($candidates as $s) {
            $exists = CategoryMapping::where('donor_category_id', $s['donor_id'])->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

            CategoryMapping::create([
                'donor_category_id' => $s['donor_id'],
                'catalog_category_id' => $s['catalog_id'],
                'confidence' => $s['score'],
                'is_manual' => false,
            ]);
            $created++;
        }

        return [
            'total' => $total,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}

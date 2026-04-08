<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;

class AiQualityService
{
    public function recordPrediction(
        int $donorCategoryId,
        ?int $predictedCatalogCategoryId,
        int $predictedConfidence,
        string $algorithmVersion,
    ): void {
        DB::table('ai_quality')->insert([
            'donor_category_id' => $donorCategoryId,
            'predicted_catalog_category_id' => $predictedCatalogCategoryId,
            'algorithm_version' => $algorithmVersion,
            'predicted_confidence' => $predictedConfidence,
            'predicted_at' => now(),
            'overridden' => false,
            'override_catalog_category_id' => null,
            'overridden_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markManualOverride(int $donorCategoryId, int $catalogCategoryId): void
    {
        $row = DB::table('ai_quality')
            ->where('donor_category_id', $donorCategoryId)
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return;
        }

        DB::table('ai_quality')->where('id', $row->id)->update([
            'overridden' => true,
            'override_catalog_category_id' => $catalogCategoryId,
            'overridden_at' => now(),
            'updated_at' => now(),
        ]);
    }
}


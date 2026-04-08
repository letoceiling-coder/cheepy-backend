<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;
use App\Models\DonorCategory;
use App\Services\AI\AiMappingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class MappingSuggestionService
{
    public function __construct(
        private AiMappingService $aiMappingService,
    ) {}

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
     * @return array{
     *   catalog_category_id: int,
     *   confidence: int,
     *   ai_score: float,
     *   legacy_score: float,
     *   final_score: float,
     *   boost_applied: float,
     *   decision_reason: string
     * }|null
     */
    public function suggestForDonorCategory(DonorCategory $donor, string $algorithmVersion = AutoMappingConfig::VERSION): ?array
    {
        $donor->loadMissing('parent');
        $catalogs = CatalogCategory::with('parent')->get();
        $best = $this->findBestMatch($donor, $catalogs, $algorithmVersion);
        if ($best === null) {
            return null;
        }

        $explain = $best['explain'] ?? [
            'ai_score' => 0.0,
            'legacy_score' => (float) $best['score'],
            'boost' => 0.0,
            'final_score' => (float) $best['score'],
            'reason' => 'legacy_only_no_ai_match',
            'time_ai_matching_ms' => 0.0,
            'cache_hit' => false,
            'candidates_count' => 0,
        ];

        Log::info('ai_mapping.telemetry', [
            'donor_category_id' => (int) $donor->id,
            'time_ai_matching_ms' => $explain['time_ai_matching_ms'],
            'cache' => $explain['cache_hit'] ? 'hit' : 'miss',
            'candidates_count' => $explain['candidates_count'],
            'ai_score' => $explain['ai_score'],
            'legacy_score' => $explain['legacy_score'],
            'final_score' => $explain['final_score'],
            'boost_applied' => $explain['boost'],
            'reason' => $explain['reason'],
            'algorithm_version' => $algorithmVersion,
        ]);

        return [
            'catalog_category_id' => (int) $best['catalog']->id,
            'confidence' => (int) $best['score'],
            'ai_score' => (float) $explain['ai_score'],
            'legacy_score' => (float) $explain['legacy_score'],
            'final_score' => (float) $explain['final_score'],
            'boost_applied' => (float) $explain['boost'],
            'decision_reason' => (string) $explain['reason'],
        ];
    }

    /**
     * @param  Collection<int, CatalogCategory>  $catalogs
     * @return array{
     *   catalog: CatalogCategory,
     *   score: int,
     *   explain?: array{
     *     ai_score:float,
     *     legacy_score:float,
     *     boost:float,
     *     final_score:float,
     *     reason:string,
     *     time_ai_matching_ms:float,
     *     cache_hit:bool,
     *     candidates_count:int
     *   }
     * }|null
     */
    private function findBestMatch(DonorCategory $donor, Collection $catalogs, string $algorithmVersion = AutoMappingConfig::VERSION): ?array
    {
        $legacyScores = [];
        foreach ($catalogs as $catalog) {
            $legacyScores[] = [
                'catalog' => $catalog,
                'legacy' => $this->legacyScore($donor, $catalog),
            ];
        }

        usort($legacyScores, static fn (array $a, array $b): int => $b['legacy'] <=> $a['legacy']);
        $top = array_slice($legacyScores, 0, 20);
        /** @var Collection<int, CatalogCategory> $topCatalogs */
        $topCatalogs = collect(array_map(static fn (array $row): CatalogCategory => $row['catalog'], $top));

        $aiMatch = null;

        try {
            $aiMatch = $this->aiMappingService->findBestMatchAmong((string) $donor->name, $topCatalogs, (int) $donor->id);
        } catch (\Throwable) {
            $aiMatch = null;
        }

        $bestCatalog = null;
        $bestScore = -1;
        $bestExplain = null;

        foreach ($catalogs as $catalog) {
            $scored = $this->scoreMatch($donor, $catalog, $aiMatch, $algorithmVersion);
            $score = $scored['score'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCatalog = $catalog;
                $bestExplain = $scored['explain'];
            }
        }

        if ($bestCatalog === null || $bestScore < 0) {
            return null;
        }

        $result = ['catalog' => $bestCatalog, 'score' => $bestScore];
        if (is_array($bestExplain)) {
            $result['explain'] = $bestExplain;
        }

        return $result;
    }

    /**
     * @param  array{catalog_category_id:int, catalog_name:string, score:float}|null  $aiMatch
     */
    private function scoreMatch(
        DonorCategory $donor,
        CatalogCategory $catalog,
        ?array $aiMatch = null,
        string $algorithmVersion = AutoMappingConfig::VERSION,
    ): array
    {
        $legacy = $this->legacyScore($donor, $catalog);

        return $this->blendWithAiScore($catalog, $legacy, $aiMatch, $algorithmVersion);
    }

    private function legacyScore(DonorCategory $donor, CatalogCategory $catalog): int
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

    /**
     * @param  array{
     *   catalog_category_id:int,
     *   catalog_name:string,
     *   score:float,
     *   boost_applied?:float,
     *   time_ai_matching_ms?:float,
     *   cache_hit?:bool,
     *   candidates_count?:int
     * }|null  $aiMatch
     */
    private function blendWithAiScore(
        CatalogCategory $catalog,
        int $legacyScore,
        ?array $aiMatch,
        string $algorithmVersion = AutoMappingConfig::VERSION,
    ): array
    {
        if ($aiMatch === null || (int) $aiMatch['catalog_category_id'] !== (int) $catalog->id) {
            return [
                'score' => $legacyScore,
                'explain' => $this->aiMappingService->explainMatch(
                    0.0,
                    (float) $legacyScore,
                    0.0,
                    (float) $legacyScore,
                    'legacy_only_no_ai_match',
                    (float) ($aiMatch['time_ai_matching_ms'] ?? 0.0),
                    (bool) ($aiMatch['cache_hit'] ?? false),
                    (int) ($aiMatch['candidates_count'] ?? 0),
                ),
            ];
        }

        // Convert cosine [-1..1] to [0..100], then blend.
        $aiScore = (float) round((($aiMatch['score'] + 1.0) / 2.0) * 100.0, 6);
        $weightAi = $this->resolveAiWeight((float) $aiMatch['score'], $algorithmVersion);
        $weightLegacy = 1.0 - $weightAi;
        $combinedRaw = ($legacyScore * $weightLegacy) + ($aiScore * $weightAi);
        $combined = (int) round($combinedRaw);

        $combinedClamped = max(0, min(100, $combined));
        $reason = $weightAi >= 0.5
            ? "ai_high_confidence_weight_{$weightAi}_{$algorithmVersion}"
            : "ai_standard_weight_{$weightAi}_{$algorithmVersion}";

        return [
            'score' => $combinedClamped,
            'explain' => $this->aiMappingService->explainMatch(
                $aiScore,
                (float) $legacyScore,
                (float) ($aiMatch['boost_applied'] ?? 0.0),
                (float) $combinedRaw,
                $reason,
                (float) ($aiMatch['time_ai_matching_ms'] ?? 0.0),
                (bool) ($aiMatch['cache_hit'] ?? false),
                (int) ($aiMatch['candidates_count'] ?? 0),
            ),
        ];
    }

    private function resolveAiWeight(float $aiCosineScore, string $algorithmVersion): float
    {
        if ($aiCosineScore > 0.9) {
            return $algorithmVersion === AutoMappingConfig::V2 ? 0.6 : 0.5;
        }

        return $algorithmVersion === AutoMappingConfig::V2 ? 0.4 : 0.3;
    }
}

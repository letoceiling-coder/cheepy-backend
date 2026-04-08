<?php

namespace App\Services\Catalog;

use App\Enums\AutoMappingDecision;
use App\Models\AutoMappingLog;
use App\Models\CategoryMapping;
use App\Models\DonorCategory;
use App\Services\AI\AiMetricsService;
use App\Services\AI\AiQualityService;
use App\Support\AutoMappingCommandContext;
use Illuminate\Support\Facades\DB;

class AutoMappingService
{
    public function __construct(
        private MappingSuggestionService $suggestionService,
        private CategoryMappingService $mappingService,
        private AiQualityService $qualityService,
        private AiMetricsService $metricsService,
        private ?AutoMappingCommandContext $commandContext = null,
    ) {}

    public function process(int $donorCategoryId, bool $ignoreIdempotency = false): void
    {
        $algorithmVersion = $this->pickAlgorithmVersion($donorCategoryId);

        if ($this->commandContext !== null) {
            $this->commandContext->reprocessedCount++;
        }

        $donor = DonorCategory::query()->find($donorCategoryId);
        if (! $donor) {
            $this->writeLog(
                $donorCategoryId,
                null,
                0,
                AutoMappingDecision::Rejected,
                'donor_category_not_found',
                $ignoreIdempotency,
                null,
                null,
                null,
                null,
                'donor_category_not_found',
                $algorithmVersion
            );

            return;
        }

        $existing = CategoryMapping::query()->where('donor_category_id', $donorCategoryId)->first();
        if ($existing && $existing->is_manual) {
            $this->writeLog(
                $donorCategoryId,
                $existing->catalog_category_id,
                (int) ($existing->confidence ?? 0),
                AutoMappingDecision::Rejected,
                'manual_mapping_preserved',
                $ignoreIdempotency,
                null,
                null,
                null,
                null,
                'manual_mapping_preserved',
                $algorithmVersion
            );

            return;
        }

        $suggestion = $this->suggestionService->suggestForDonorCategory($donor, $algorithmVersion);
        if ($suggestion === null) {
            $this->writeLog(
                $donorCategoryId,
                null,
                0,
                AutoMappingDecision::Rejected,
                'no_catalog_match',
                $ignoreIdempotency,
                null,
                null,
                null,
                null,
                'no_catalog_match',
                $algorithmVersion
            );

            return;
        }

        $catalogId = (int) $suggestion['catalog_category_id'];
        $confidence = (int) $suggestion['confidence'];
        $aiScore = isset($suggestion['ai_score']) ? (float) $suggestion['ai_score'] : null;
        $legacyScore = isset($suggestion['legacy_score']) ? (float) $suggestion['legacy_score'] : null;
        $finalScore = isset($suggestion['final_score']) ? (float) $suggestion['final_score'] : (float) $confidence;
        $boostApplied = isset($suggestion['boost_applied']) ? (float) $suggestion['boost_applied'] : null;
        $decisionReason = isset($suggestion['decision_reason']) ? (string) $suggestion['decision_reason'] : null;

        $this->qualityService->recordPrediction($donorCategoryId, $catalogId, $confidence, $algorithmVersion);

        $decision = $this->decide($confidence);
        $reason = null;

        if ($decision === AutoMappingDecision::AutoApplied) {
            DB::transaction(function () use ($donorCategoryId, $catalogId, $confidence, $existing): void {
                $this->mappingService->applyAutomaticMapping($donorCategoryId, $catalogId, $confidence, $existing);
            });
            $this->metricsService->recordAutoApplied((float) $confidence, $algorithmVersion);
            $this->writeLog(
                $donorCategoryId,
                $catalogId,
                $confidence,
                AutoMappingDecision::AutoApplied,
                null,
                $ignoreIdempotency,
                $aiScore,
                $legacyScore,
                $finalScore,
                $boostApplied,
                $decisionReason ?? 'auto_applied_by_confidence_threshold',
                $algorithmVersion
            );

            return;
        }

        if ($decision === AutoMappingDecision::ManualRequired) {
            $reason = 'confidence_below_auto_threshold';
        } else {
            $reason = 'confidence_below_minimum';
        }

        $this->writeLog(
            $donorCategoryId,
            $catalogId,
            $confidence,
            $decision,
            $reason,
            $ignoreIdempotency,
            $aiScore,
            $legacyScore,
            $finalScore,
            $boostApplied,
            $decisionReason ?? $reason,
            $algorithmVersion
        );
    }

    private function decide(int $confidence): AutoMappingDecision
    {
        if ($confidence >= 90) {
            return AutoMappingDecision::AutoApplied;
        }
        if ($confidence >= 70) {
            return AutoMappingDecision::ManualRequired;
        }

        return AutoMappingDecision::Rejected;
    }

    private function writeLog(
        int $donorCategoryId,
        ?int $suggestedCatalogId,
        int $confidence,
        AutoMappingDecision $decision,
        ?string $reason,
        bool $ignoreIdempotency,
        ?float $aiScore = null,
        ?float $legacyScore = null,
        ?float $finalScore = null,
        ?float $boostApplied = null,
        ?string $decisionReason = null,
        ?string $algorithmVersion = null,
    ): void {
        $algorithmVersion ??= AutoMappingConfig::VERSION;
        if (! $ignoreIdempotency && $this->isDuplicateOfLastLog($donorCategoryId, $suggestedCatalogId, $confidence, $decision, $algorithmVersion)) {
            if ($this->commandContext !== null) {
                $this->commandContext->skippedDuplicateLogs++;
            }

            return;
        }

        AutoMappingLog::query()->create([
            'donor_category_id' => $donorCategoryId,
            'suggested_catalog_category_id' => $suggestedCatalogId,
            'confidence' => $confidence,
            'ai_score' => $aiScore,
            'legacy_score' => $legacyScore,
            'final_score' => $finalScore,
            'boost_applied' => $boostApplied,
            'decision' => $decision,
            'reason' => $reason,
            'decision_reason' => $decisionReason ?? $reason,
            'algorithm_version' => $algorithmVersion,
            'created_at' => now(),
        ]);

        if ($this->commandContext !== null) {
            $this->commandContext->logsWrittenCount++;
        }
    }

    private function isDuplicateOfLastLog(
        int $donorCategoryId,
        ?int $suggestedCatalogId,
        int $confidence,
        AutoMappingDecision $decision,
        string $algorithmVersion,
    ): bool {
        $last = AutoMappingLog::query()
            ->where('donor_category_id', $donorCategoryId)
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return false;
        }

        $lastAlgo = (string) ($last->algorithm_version ?? AutoMappingConfig::VERSION);
        if ($lastAlgo !== $algorithmVersion) {
            return false;
        }

        $lastCatalog = $last->suggested_catalog_category_id !== null
            ? (int) $last->suggested_catalog_category_id
            : null;
        $newCatalog = $suggestedCatalogId !== null ? (int) $suggestedCatalogId : null;

        if ($lastCatalog !== $newCatalog) {
            return false;
        }

        if ($last->decision !== $decision) {
            return false;
        }

        if (abs((int) $last->confidence - $confidence) >= AutoMappingConfig::CONFIDENCE_SIGNIFICANT_DELTA) {
            return false;
        }

        return true;
    }

    private function pickAlgorithmVersion(int $donorCategoryId): string
    {
        return (abs(crc32((string) $donorCategoryId)) % 2) === 0
            ? AutoMappingConfig::V1
            : AutoMappingConfig::V2;
    }
}

<?php

namespace App\Services\Catalog;

use App\Enums\AutoMappingDecision;
use App\Models\DonorCategory;
use App\Models\AutoMappingLog;
use App\Services\AI\AiMetricsService;
use App\Services\AI\AiQualityService;
use App\Services\AI\AiMappingService;
use App\Support\AutoMappingCommandContext;
use Illuminate\Support\Facades\Cache;

/**
 * Persists user-driven mapping feedback (manual CRM saves) into auto_mapping_logs.
 */
class MappingFeedbackService
{
    public function __construct(
        private ?AutoMappingCommandContext $commandContext = null,
        private ?AiQualityService $qualityService = null,
        private ?AiMetricsService $metricsService = null,
    ) {}

    public function logManualOverride(int $donorCategoryId, int $catalogCategoryId, int $confidence): void
    {
        if ($this->isDuplicateOfLastLog($donorCategoryId, $catalogCategoryId, $confidence, AutoMappingDecision::ManualOverride)) {
            if ($this->commandContext !== null) {
                $this->commandContext->skippedDuplicateLogs++;
            }

            return;
        }

        AutoMappingLog::query()->create([
            'donor_category_id' => $donorCategoryId,
            'suggested_catalog_category_id' => $catalogCategoryId,
            'confidence' => $confidence,
            'decision' => AutoMappingDecision::ManualOverride,
            'reason' => 'user_changed_mapping',
            'algorithm_version' => AutoMappingConfig::VERSION,
            'created_at' => now(),
        ]);

        if ($this->commandContext !== null) {
            $this->commandContext->logsWrittenCount++;
        }

        if ($this->qualityService !== null) {
            $this->qualityService->markManualOverride($donorCategoryId, $catalogCategoryId);
        }
        if ($this->metricsService !== null) {
            $version = (string) (AutoMappingLog::query()
                ->where('donor_category_id', $donorCategoryId)
                ->orderByDesc('id')
                ->value('algorithm_version') ?? AutoMappingConfig::VERSION);
            $this->metricsService->recordManualOverride($version);
        }

        $this->invalidateDonorEmbeddingCache($donorCategoryId);
    }

    private function invalidateDonorEmbeddingCache(int $donorCategoryId): void
    {
        Cache::tags(['ai_donor_'.$donorCategoryId])->flush();

        // Backward-compat: clear old key format if present.
        Cache::forget(AiMappingService::donorEmbeddingCacheKey('', $donorCategoryId));
        $donorName = DonorCategory::query()->whereKey($donorCategoryId)->value('name');
        if (is_string($donorName) && $donorName !== '') {
            Cache::forget(AiMappingService::donorEmbeddingCacheKey($donorName, null));
        }
    }

    private function isDuplicateOfLastLog(
        int $donorCategoryId,
        ?int $suggestedCatalogId,
        int $confidence,
        AutoMappingDecision $decision,
    ): bool {
        $last = AutoMappingLog::query()
            ->where('donor_category_id', $donorCategoryId)
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return false;
        }

        $lastAlgo = (string) ($last->algorithm_version ?? AutoMappingConfig::VERSION);
        if ($lastAlgo !== AutoMappingConfig::VERSION) {
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
}

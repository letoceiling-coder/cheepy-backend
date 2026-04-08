<?php

namespace App\Services\AI;

use App\Enums\AutoMappingDecision;
use App\Models\AutoMappingLog;
use App\Models\CatalogCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class AiMappingService
{
    public function __construct(
        private EmbeddingService $embeddingService,
    ) {}

    /**
     * @return array{
     *   catalog_category_id:int,
     *   catalog_name:string,
     *   score:float,
     *   boost_applied:float,
     *   time_ai_matching_ms:float,
     *   cache_hit:bool,
     *   candidates_count:int
     * }|null
     */
    public function findBestMatch(string $donorName, ?int $donorCategoryId = null): ?array
    {
        $catalogs = CatalogCategory::query()
            ->whereNotNull('embedding')
            ->select(['id', 'name', 'embedding'])
            ->orderBy('id')
            ->get();

        return $this->findBestMatchAmong($donorName, $catalogs, $donorCategoryId);
    }

    /**
     * @param  Collection<int, CatalogCategory>  $candidates
     * @return array{
     *   catalog_category_id:int,
     *   catalog_name:string,
     *   score:float,
     *   boost_applied:float,
     *   time_ai_matching_ms:float,
     *   cache_hit:bool,
     *   candidates_count:int
     * }|null
     */
    public function findBestMatchAmong(string $donorName, Collection $candidates, ?int $donorCategoryId = null): ?array
    {
        $donorPrompt = 'donor: '.$donorName;
        $cacheKey = self::donorEmbeddingCacheKey($donorName, $donorCategoryId);
        $cacheTag = 'ai_donor_'.(int) ($donorCategoryId ?? 0);
        $startedAt = microtime(true);
        $cacheHit = false;

        $cacheStore = Cache::tags([$cacheTag]);
        $cachedVector = $cacheStore->get($cacheKey);
        if (is_array($cachedVector) && $cachedVector !== []) {
            /** @var array<int, float> $donorVector */
            $donorVector = array_map(static fn ($v): float => (float) $v, $cachedVector);
            $cacheHit = true;
        } else {
            $donorVector = $this->embeddingService->embed($donorPrompt);
            $cacheStore->put($cacheKey, $donorVector, now()->addHours(24));
        }

        $feedback = $this->manualOverrideFeedback($donorCategoryId);
        $feedbackTotal = (int) ($feedback['_total'] ?? 0);
        $candidatesCount = $candidates->count();

        $bestId = null;
        $bestName = null;
        $bestScore = -1.0;
        $bestBoost = 0.0;

        foreach ($candidates as $row) {
            if (! is_array($row->embedding) || $row->embedding === []) {
                continue;
            }
            $score = $this->cosineSimilarity($donorVector, $row->embedding);

            $catalogId = (int) $row->id;
            if ($feedbackTotal > 0 && isset($feedback[$catalogId])) {
                $item = $feedback[$catalogId];
                $count = (int) ($item['count'] ?? 0);
                $avgConfidence = (float) ($item['avg_confidence'] ?? 0.0);
                $dynamic = min(0.3, 0.1 + ($avgConfidence / 100.0) * 0.2);
                $proportional = $count / $feedbackTotal;
                $boost = $dynamic * $proportional;
                $score = min(1.0, $score + $boost);
            } else {
                $boost = 0.0;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $catalogId;
                $bestName = (string) $row->name;
                $bestBoost = (float) $boost;
            }
        }

        if ($bestId === null) {
            return null;
        }

        return [
            'catalog_category_id' => $bestId,
            'catalog_name' => (string) $bestName,
            'score' => max(-1.0, min(1.0, $bestScore)),
            'boost_applied' => $bestBoost,
            'time_ai_matching_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'cache_hit' => $cacheHit,
            'candidates_count' => $candidatesCount,
        ];
    }

    /**
     * @return array{
     *   ai_score:float,
     *   legacy_score:float,
     *   boost:float,
     *   final_score:float,
     *   reason:string,
     *   time_ai_matching_ms:float,
     *   cache_hit:bool,
     *   candidates_count:int
     * }
     */
    public function explainMatch(
        float $aiScore,
        float $legacyScore,
        float $boost,
        float $finalScore,
        string $reason,
        float $timeAiMatchingMs,
        bool $cacheHit,
        int $candidatesCount,
    ): array {
        return [
            'ai_score' => round($aiScore, 6),
            'legacy_score' => round($legacyScore, 6),
            'boost' => round($boost, 6),
            'final_score' => round($finalScore, 6),
            'reason' => $reason,
            'time_ai_matching_ms' => round($timeAiMatchingMs, 2),
            'cache_hit' => $cacheHit,
            'candidates_count' => $candidatesCount,
        ];
    }

    public static function donorEmbeddingCacheKey(string $donorName, ?int $donorCategoryId = null): string
    {
        $hashSource = $donorCategoryId !== null
            ? 'donor_id:'.$donorCategoryId
            : mb_strtolower(trim('donor: '.$donorName), 'UTF-8');

        return 'ai_embedding:donor:'.sha1($hashSource);
    }

    /**
     * @return array<int|string, array{count:int, avg_confidence:float}|int>
     */
    private function manualOverrideFeedback(?int $donorCategoryId): array
    {
        if ($donorCategoryId === null) {
            return ['_total' => 0];
        }

        $rows = AutoMappingLog::query()
            ->where('donor_category_id', $donorCategoryId)
            ->where('decision', AutoMappingDecision::ManualOverride)
            ->whereNotNull('suggested_catalog_category_id')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['suggested_catalog_category_id', 'confidence']);

        if ($rows->isEmpty()) {
            return ['_total' => 0];
        }

        $stats = [];
        foreach ($rows as $row) {
            $catalogId = (int) $row->suggested_catalog_category_id;
            if (! isset($stats[$catalogId])) {
                $stats[$catalogId] = ['count' => 0, 'sum_confidence' => 0.0];
            }
            $stats[$catalogId]['count']++;
            $stats[$catalogId]['sum_confidence'] += (float) ($row->confidence ?? 0);
        }

        $total = 0;
        foreach ($stats as $catalogId => $item) {
            $count = (int) $item['count'];
            $total += $count;
            $stats[$catalogId] = [
                'count' => $count,
                'avg_confidence' => $count > 0 ? (float) ($item['sum_confidence'] / $count) : 0.0,
            ];
        }
        $stats['_total'] = $total;

        return $stats;
    }

    /**
     * Cosine similarity in range [-1, 1].
     *
     * @param  array<int, float|int>  $a
     * @param  array<int, float|int>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}


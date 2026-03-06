<?php

namespace App\Services;

use App\Models\AttributeDictionary;
use App\Models\ProductAttribute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Provides faceted filter data for catalog pages.
 *
 * Results are cached in Redis: filters:category:{id}
 * Cache is invalidated by AttributeExtractionService::invalidateFilterCaches()
 * or explicitly via clearCategoryCache().
 *
 * Facet format:
 *   [
 *     ['attribute_key' => 'size', 'display_name' => 'Размер', 'values' => [
 *       ['value' => 'M', 'count' => 120],
 *       ...
 *     ]],
 *     ...
 *   ]
 */
class AttributeFacetService
{
    private const CACHE_TTL = 3600; // 1 hour

    // Only these keys are shown in facet filters (ordered)
    private const FACET_KEYS = [
        'size', 'color', 'material', 'country_of_origin', 'brand',
        'pack_quantity', 'gender', 'season', 'fit',
    ];

    /**
     * Get facets for a category. Cached.
     *
     * @param  int|null $categoryId  null = global (all categories)
     * @param  float    $minConf     minimum confidence threshold
     * @return array
     */
    public function getFacetsByCategory(?int $categoryId, float $minConf = 0.6): array
    {
        $cacheKey = 'filters:category:' . ($categoryId ?? 'all');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($categoryId, $minConf) {
            return $this->buildFacets($categoryId, $minConf);
        });
    }

    /**
     * Force-rebuild and cache facets for a category.
     */
    public function rebuildCategoryFacets(?int $categoryId): array
    {
        $cacheKey = 'filters:category:' . ($categoryId ?? 'all');
        Cache::forget($cacheKey);
        return $this->getFacetsByCategory($categoryId);
    }

    /**
     * Clear cached facets for a single category.
     */
    public function clearCategoryCache(?int $categoryId): void
    {
        Cache::forget('filters:category:' . ($categoryId ?? 'all'));
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────

    private function buildFacets(?int $categoryId, float $minConf): array
    {
        // Load display names from attribute_rules (first match per key)
        $displayNames = \App\Models\AttributeRule::select('attribute_key', 'display_name')
            ->orderBy('priority')
            ->get()
            ->mapWithKeys(fn($r) => [$r->attribute_key => $r->display_name]);

        // Load dictionary sort orders for correct value ordering
        $dictOrder = AttributeDictionary::all()
            ->groupBy('attribute_key')
            ->map(fn($g) => $g->mapWithKeys(fn($d) => [mb_strtolower($d->value) => $d->sort_order]));

        $facets = [];

        foreach (self::FACET_KEYS as $attrKey) {
            $query = ProductAttribute::query()
                ->where('attr_name', $this->displayName($attrKey, $displayNames))
                ->where('confidence', '>=', $minConf)
                ->select('attr_value', DB::raw('COUNT(*) as count'), DB::raw('MAX(confidence) as max_conf'))
                ->groupBy('attr_value')
                ->orderByDesc('count');

            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                // Try with attr_name containing partial key match
                $query2 = ProductAttribute::query()
                    ->whereRaw('LOWER(attr_name) LIKE ?', ['%' . strtolower($attrKey) . '%'])
                    ->where('confidence', '>=', $minConf)
                    ->select('attr_value', DB::raw('COUNT(*) as count'))
                    ->groupBy('attr_value')
                    ->orderByDesc('count');
                if ($categoryId) $query2->where('category_id', $categoryId);
                $rows = $query2->get();
            }

            if ($rows->isEmpty()) continue;

            // Sort values by dictionary order, then by count
            $orderMap = $dictOrder->get($attrKey, collect())->toArray();
            $values = $rows->map(fn($r) => [
                'value'      => $r->attr_value,
                'count'      => (int) $r->count,
                'sort_order' => $orderMap[mb_strtolower($r->attr_value)] ?? 999,
            ])->sortBy('sort_order')->values()
              ->map(fn($r) => ['value' => $r['value'], 'count' => $r['count']]);

            $facets[] = [
                'attribute_key' => $attrKey,
                'display_name'  => $displayNames->get($attrKey, ucfirst($attrKey)),
                'values'        => $values->toArray(),
            ];
        }

        return $facets;
    }

    private function displayName(string $attrKey, $displayNames): string
    {
        return $displayNames->get($attrKey, $attrKey);
    }
}

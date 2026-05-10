<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Кэш id бесплатных моделей OpenRouter после успешного GET /v1/models (CRM).
 */
final class OpenRouterFreeModelCache
{
    private const KEY = 'crm_openrouter_free_model_ids';

    private const TTL_SECONDS = 43_200; // 12 ч

    /**
     * @param  list<string>  $ids
     */
    public static function remember(array $ids): void
    {
        Cache::put(self::KEY, array_values(array_unique($ids)), now()->addSeconds(self::TTL_SECONDS));
    }

    /** @return list<string> */
    public static function get(): array
    {
        $v = Cache::get(self::KEY, []);

        return is_array($v) ? array_values(array_filter($v, static fn ($x) => is_string($x) && trim($x) !== '')) : [];
    }

    /** id из последней подгрузки каталога (без суффикса :free допускаются при цене 0 в API). */
    public static function allows(string $modelId): bool
    {
        $modelId = trim($modelId);
        $cache = self::get();

        return $modelId !== '' && in_array($modelId, $cache, true);
    }
}

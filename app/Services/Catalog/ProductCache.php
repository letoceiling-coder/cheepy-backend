<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;

class ProductCache
{
    public const TAG_PRODUCTS = 'products';

    /**
     * @param  array<string>  $tags  e.g. ['products', 'category_5'] or ['products', 'product_123']
     */
    public static function remember(string $key, int $ttl, callable $callback, array $tags = [self::TAG_PRODUCTS]): mixed
    {
        $tags = array_values(array_unique(array_merge([self::TAG_PRODUCTS], $tags)));
        try {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        } catch (\BadMethodCallException $e) {
            return Cache::remember($key, $ttl, $callback);
        }
    }

    /**
     * @param  array<string>  $tags  e.g. ['products', 'category_5']
     */
    public static function flushTags(array $tags): void
    {
        $tags = array_values(array_unique(array_merge([self::TAG_PRODUCTS], $tags)));
        try {
            Cache::tags($tags)->flush();
        } catch (\BadMethodCallException $e) {
            Cache::flush();
        }
    }
}

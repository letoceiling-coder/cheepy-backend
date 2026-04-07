<?php

namespace App\Support;

use App\Models\ParserSetting;
use RuntimeException;

/**
 * Builds parser_jobs.options (SSOT for worker). Reads ParserSetting + config only here — not in jobs/services.
 */
class ParserJobOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function buildFromSettings(?ParserSetting $setting = null): array
    {
        $s = $setting ?? ParserSetting::current();
        $rawIds = $s->default_category_ids;
        $ids = is_array($rawIds) ? array_values(array_filter(array_map('intval', $rawIds))) : [];

        $options = [
            'categories' => $ids,
            'linked_only' => (bool) $s->default_linked_only,
            'products_per_category' => (int) ($s->default_products_per_category ?? 0),
            'max_pages' => (int) ($s->default_max_pages ?? 0),
            'no_details' => (bool) ($s->default_no_details ?? false),
            'save_photos' => (bool) $s->download_photos,
            'save_to_db' => true,
            'queue_threshold' => (int) $s->queue_threshold,
            'runtime' => self::runtimePayload($s),
        ];

        self::assertNonEmpty($options);

        return $options;
    }

    /**
     * API start: DB defaults + request overrides. Refreshes runtime snapshot.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function buildForApiStart(array $overrides, ?ParserSetting $setting = null): array
    {
        $base = self::buildFromSettings($setting);

        if (array_key_exists('categories', $overrides)) {
            $base['categories'] = array_values(array_filter(array_map('intval', (array) $overrides['categories'])));
        }
        if (array_key_exists('linked_only', $overrides)) {
            $base['linked_only'] = (bool) $overrides['linked_only'];
        }
        if (array_key_exists('products_per_category', $overrides)) {
            $base['products_per_category'] = (int) $overrides['products_per_category'];
        }
        if (array_key_exists('max_pages', $overrides)) {
            $base['max_pages'] = (int) $overrides['max_pages'];
        }
        if (array_key_exists('no_details', $overrides)) {
            $base['no_details'] = (bool) $overrides['no_details'];
        }
        if (array_key_exists('save_photos', $overrides)) {
            $base['save_photos'] = (bool) $overrides['save_photos'];
        }
        if (array_key_exists('save_to_db', $overrides)) {
            $base['save_to_db'] = (bool) $overrides['save_to_db'];
        }
        if (array_key_exists('category_slug', $overrides)) {
            $base['category_slug'] = $overrides['category_slug'];
        }
        if (array_key_exists('seller_slug', $overrides)) {
            $base['seller_slug'] = $overrides['seller_slug'];
        }

        $s = $setting ?? ParserSetting::current();
        $base['runtime'] = self::runtimePayload($s);
        self::assertNonEmpty($base);

        return $base;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function assertNonEmpty(array $options): void
    {
        if ($options === []) {
            throw new RuntimeException('CRITICAL: OPTIONS EMPTY - JOB BLOCKED');
        }
    }

    /**
     * Для full/daemon — обязателен непустой categories; menu_only/seller — без проверки; category — slug или categories.
     *
     * @param  array<string, mixed>  $options
     */
    public static function assertCategoriesForJob(string $type, array $options): void
    {
        if ($type === 'menu_only' || $type === 'seller') {
            return;
        }
        if ($type === 'category') {
            $slug = trim((string) ($options['category_slug'] ?? ''));
            $cats = $options['categories'] ?? [];
            if ($slug !== '' || (is_array($cats) && $cats !== [])) {
                return;
            }
            throw new RuntimeException('CRITICAL: NO CATEGORIES IN OPTIONS');
        }
        if (empty($options['categories']) || ! is_array($options['categories'])) {
            throw new RuntimeException('CRITICAL: NO CATEGORIES IN OPTIONS');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function assertWorkerOptions(array $options): void
    {
        foreach (['categories', 'linked_only', 'products_per_category', 'max_pages', 'no_details', 'save_photos', 'save_to_db', 'runtime'] as $k) {
            if (! array_key_exists($k, $options)) {
                throw new RuntimeException('OPTIONS BROKEN: missing ' . $k);
            }
        }
        if (! is_array($options['runtime'] ?? null)
            || ! isset($options['runtime']['http_client'])
            || ! is_array($options['runtime']['http_client'])) {
            throw new RuntimeException('CRITICAL: NO HTTP CONFIG IN OPTIONS');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function runtimePayload(ParserSetting $s): array
    {
        $sadovod = config('sadovod');
        $parserRate = config('parser_rate', []);
        if (! is_array($sadovod)) {
            $sadovod = [];
        }
        if (! is_array($parserRate)) {
            $parserRate = [];
        }

        return [
            'sadovod' => $sadovod,
            'parser_rate' => $parserRate,
            'http_client' => self::buildHttpClientConfig($s, $sadovod, $parserRate),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildHttpClientConfig(ParserSetting $s, array $sadovod, array $parserRate): array
    {
        $agents = config('parser_user_agents.agents', []);
        if (! is_array($agents)) {
            $agents = [];
        }
        $defaultUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $retryBackoff = $parserRate['retry_backoff_seconds'] ?? null;
        if (! is_array($retryBackoff)) {
            $retryBackoff = [2, 5, 10];
        }
        $blockCodes = $parserRate['block_codes'] ?? null;
        if (! is_array($blockCodes)) {
            $blockCodes = [403, 429];
        }

        return [
            'base_url' => $sadovod['base_url'] ?? 'https://sadovodbaza.ru',
            'verify_ssl' => $sadovod['verify_ssl'] ?? true,
            'delay_min_ms' => (int) $s->request_delay_min,
            'delay_max_ms' => (int) $s->request_delay_max,
            'timeout_seconds' => (int) $s->timeout_seconds,
            'request_delay_ms' => (int) ($sadovod['request_delay_ms'] ?? 500),
            'product_broadcast_every' => (int) ($sadovod['product_broadcast_every'] ?? 20),
            'user_agents' => ! empty($agents) ? $agents : [$defaultUa],
            'max_requests_per_minute' => (int) ($parserRate['max_requests_per_minute'] ?? 300),
            'max_requests_per_second' => array_key_exists('max_requests_per_second', $parserRate) ? $parserRate['max_requests_per_second'] : null,
            'retry_count' => (int) ($parserRate['retry_count'] ?? 3),
            'retry_backoff_seconds' => $retryBackoff,
            'block_codes' => $blockCodes,
            'proxy_url' => $s->proxy_url !== null && $s->proxy_url !== '' ? (string) $s->proxy_url : '',
            'use_proxy' => (bool) config('parser.use_proxy', false),
        ];
    }
}

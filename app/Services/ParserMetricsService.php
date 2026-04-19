<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class ParserMetricsService
{
    private const KEY_REQUESTS = 'parser:metrics:requests';
    private const KEY_BLOCKED = 'parser:metrics:blocked';
    private const KEY_RETRIES = 'parser:metrics:retries';
    private const TTL_MINUTE = 90; // slightly over 1 min for rolling window
    // Cum-счётчики раньше росли вечно. 24ч TTL — окно «за последние сутки», метрика
    // авто-чистится при простое, не превращается в общий накопитель за всё время.
    private const TTL_DAILY = 86400;

    public static function incrementRequests(): void
    {
        try {
            $key = self::KEY_REQUESTS . ':' . date('Y-m-d-H-i');
            Redis::incr($key);
            Redis::expire($key, self::TTL_MINUTE);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function incrementBlocked(): void
    {
        try {
            Redis::incr(self::KEY_BLOCKED);
            Redis::expire(self::KEY_BLOCKED, self::TTL_DAILY);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function incrementRetries(): void
    {
        try {
            Redis::incr(self::KEY_RETRIES);
            Redis::expire(self::KEY_RETRIES, self::TTL_DAILY);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function getRequestsPerMinute(): int
    {
        try {
            $key = self::KEY_REQUESTS . ':' . date('Y-m-d-H-i');
            return (int) Redis::get($key) ?: 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function getBlockedRequests(): int
    {
        try {
            return (int) Redis::get(self::KEY_BLOCKED) ?: 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function getRetryCount(): int
    {
        try {
            return (int) Redis::get(self::KEY_RETRIES) ?: 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function getMetrics(): array
    {
        return [
            'requests_per_minute' => self::getRequestsPerMinute(),
            'blocked_requests' => self::getBlockedRequests(),
            'retry_count' => self::getRetryCount(),
        ];
    }

    public static function getProductsPerMinute(): float
    {
        try {
            $running = \App\Models\ParserJob::whereIn('status', ['running', 'pending'])
                ->latest('id')
                ->first();

            if ($running && $running->started_at) {
                $minutes = max(1.0, (float) $running->started_at->diffInSeconds(now()) / 60.0);
                // saved_products reflects actual parser throughput (including updates),
                // while Product::count by parsed_at undercounts when the same product is updated repeatedly.
                return round(((int) $running->saved_products) / $minutes, 2);
            }

            $count = \App\Models\Product::where('parsed_at', '>=', now()->subHour())->count();
            return round($count / 60, 2);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

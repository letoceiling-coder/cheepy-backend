<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class ParserMetricsService
{
    private const KEY_REQUESTS = 'parser:metrics:requests';
    private const KEY_BLOCKED = 'parser:metrics:blocked';
    private const KEY_RETRIES = 'parser:metrics:retries';
    private const TTL_MINUTE = 90; // slightly over 1 min for rolling window

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
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function incrementRetries(): void
    {
        try {
            Redis::incr(self::KEY_RETRIES);
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
}

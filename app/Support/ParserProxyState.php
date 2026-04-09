<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class ParserProxyState
{
    private const BLOCKED_UNTIL_KEY = 'parser:proxy_blocked_until';
    private const BLOCK_REASON_KEY = 'parser:proxy_block_reason';
    private const BLOCK_STREAK_KEY = 'parser:proxy_block_streak';
    private const BLOCK_LAST_ACTION_KEY = 'parser:proxy_block_last_action';
    private const BLOCK_LAST_URL_KEY = 'parser:proxy_block_last_url';

    private const STREAK_THRESHOLD = 3;
    private const COOLDOWN_SECONDS = 600;
    private const STREAK_TTL_SECONDS = 1800;

    public static function isBlocked(): bool
    {
        $untilTs = (int) Cache::get(self::BLOCKED_UNTIL_KEY, 0);
        return $untilTs > time();
    }

    public static function blockedUntilIso(): ?string
    {
        $untilTs = (int) Cache::get(self::BLOCKED_UNTIL_KEY, 0);
        if ($untilTs <= time()) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($untilTs)->toIso8601String();
    }

    public static function reason(): ?string
    {
        $reason = Cache::get(self::BLOCK_REASON_KEY);
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public static function streak(): int
    {
        return (int) Cache::get(self::BLOCK_STREAK_KEY, 0);
    }

    public static function lastAction(): ?string
    {
        $action = Cache::get(self::BLOCK_LAST_ACTION_KEY);
        return is_string($action) && $action !== '' ? $action : null;
    }

    public static function lastUrl(): ?string
    {
        $url = Cache::get(self::BLOCK_LAST_URL_KEY);
        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function mark429(string $url = ''): void
    {
        $streak = self::streak() + 1;
        Cache::put(self::BLOCK_STREAK_KEY, $streak, now()->addSeconds(self::STREAK_TTL_SECONDS));

        if ($url !== '') {
            Cache::put(self::BLOCK_LAST_URL_KEY, $url, now()->addSeconds(self::STREAK_TTL_SECONDS));
        }

        if ($streak >= self::STREAK_THRESHOLD) {
            $untilTs = time() + self::COOLDOWN_SECONDS;
            Cache::put(self::BLOCKED_UNTIL_KEY, $untilTs, now()->addSeconds(self::COOLDOWN_SECONDS));
            Cache::put(self::BLOCK_REASON_KEY, 'http_429_from_donor', now()->addSeconds(self::COOLDOWN_SECONDS));
            Cache::put(self::BLOCK_LAST_ACTION_KEY, 'proxy_cooldown_applied', now()->addSeconds(self::COOLDOWN_SECONDS));
        }
    }

    public static function clearOnHealthyResponse(): void
    {
        Cache::forget(self::BLOCK_STREAK_KEY);
        Cache::forget(self::BLOCKED_UNTIL_KEY);
        Cache::forget(self::BLOCK_REASON_KEY);
        Cache::put(self::BLOCK_LAST_ACTION_KEY, 'proxy_recovered', now()->addMinutes(30));
    }

    /**
     * @return array{
     *   blocked: bool,
     *   blocked_until: ?string,
     *   reason: ?string,
     *   streak: int,
     *   last_action: ?string,
     *   last_url: ?string
     * }
     */
    public static function snapshot(): array
    {
        return [
            'blocked' => self::isBlocked(),
            'blocked_until' => self::blockedUntilIso(),
            'reason' => self::reason(),
            'streak' => self::streak(),
            'last_action' => self::lastAction(),
            'last_url' => self::lastUrl(),
        ];
    }
}


<?php

namespace App\Services\Parser;

use App\Models\ParserSetting;
use App\Support\ParserProxyState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HttpClient
{
    private const MAX_ATTEMPTS = 3;

    private const BAD_PROXY_TTL_SECONDS = 60;

    /**
     * Раньше каждые N запросов воркер засыпал на 10с, что съедало пропускную
     * способность непредсказуемо. Темп сейчас ограничивается верхним
     * SadovodParser\HttpClient::applyRateLimit + случайной задержкой ниже,
     * поэтому глобальный cooldown не нужен.
     */
    private bool $networkModeLogged = false;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X)',
        'Mozilla/5.0 (X11; Linux x86_64)',
        'Mozilla/5.0 (Windows NT 10.0; WOW64)',
    ];

    /**
     * @param  list<string>  $proxyUrlsFromOptions  Snapshot from options.runtime.http_client.proxy_urls
     */
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryCount = 3,
        private readonly int $delayMinMs = 1500,
        private readonly int $delayMaxMs = 3000,
        private readonly array $proxyUrlsFromOptions = [],
        private readonly bool $useProxyFromOptions = false,
    ) {
    }

    private function badProxyCacheKey(string $proxy): string
    {
        return 'bad_proxy:' . hash('sha256', $proxy);
    }

    private function markProxyBad(string $proxy): void
    {
        Cache::put($this->badProxyCacheKey($proxy), true, now()->addSeconds(self::BAD_PROXY_TTL_SECONDS));
    }

    private function isProxyBad(string $proxy): bool
    {
        return (bool) Cache::get($this->badProxyCacheKey($proxy), false);
    }

    /**
     * @param  list<string>  $pool
     */
    private function pickRandomHealthyProxy(array $pool): ?string
    {
        $good = [];
        foreach ($pool as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if (! $this->isProxyBad($p)) {
                $good[] = $p;
            }
        }
        if ($good === []) {
            return null;
        }

        return $good[array_rand($good)];
    }

    /**
     * Options snapshot merged with parser_settings (DB wins for missing in snapshot).
     *
     * @return list<string>
     */
    private function mergedProxyPool(): array
    {
        $settings = ParserSetting::current();
        $opt = $this->proxyUrlsFromOptions;
        $db = is_array($settings->proxy_urls) ? $settings->proxy_urls : [];
        $merged = array_merge(
            is_array($opt) ? $opt : [],
            is_array($db) ? $db : []
        );
        $merged = array_values(array_unique(array_filter(array_map(static fn ($u) => trim((string) $u), $merged))));
        if ($merged === []) {
            $single = trim((string) ($settings->proxy_url ?? ''));
            if ($single !== '') {
                $merged = [$single];
            }
        }

        return $merged;
    }

    /**
     * @return array{use_proxy: bool, proxy_pool: list<string>}
     */
    private function effectiveNetwork(): array
    {
        $settings = ParserSetting::current();
        $useProxy = $this->useProxyFromOptions || (bool) $settings->proxy_enabled;
        $pool = $this->mergedProxyPool();
        if ($useProxy && $pool === []) {
            Log::warning('[NETWORK] proxy_enabled but proxy pool empty, using direct only');
            $useProxy = false;
        }

        return ['use_proxy' => $useProxy, 'proxy_pool' => $pool];
    }

    private function shouldMarkProxyBad(Throwable $e): bool
    {
        $m = mb_strtolower($e->getMessage());

        return str_contains($m, 'timed out')
            || str_contains($m, 'connection timed out')
            || str_contains($m, 'curl error 28')
            || str_contains($m, 'operation timed out')
            || str_contains($m, 'connection refused')
            || str_contains($m, 'curl error 7')
            || str_contains($m, 'could not connect')
            || str_contains($m, 'connection reset');
    }

    /**
     * Donor fetch: random delay, cooldown every 100 requests, timeout clamped 10–15s.
     * Random healthy proxy per attempt; bad proxies excluded 60s; fallback to direct if none healthy.
     *
     * @throws Throwable
     */
    public function get(string $url, array $headers = []): string
    {
        // Единственный sleep между запросами этого воркера. Верхний слой
        // SadovodParser\HttpClient::applyRateLimit добавляет глобальный cap
        // (max_requests_per_second), но не задержку — сложение sleep'ов запрещено.
        $delayMs = random_int($this->delayMinMs, $this->delayMaxMs);
        usleep($delayMs * 1000);

        $net = $this->effectiveNetwork();
        $useProxy = $net['use_proxy'];
        $pool = $net['proxy_pool'];

        if ($useProxy && ParserProxyState::isBlocked()) {
            $blockedUntil = ParserProxyState::blockedUntilIso();
            Log::warning('PROXY COOLDOWN ACTIVE', [
                'url' => $url,
                'blocked_until' => $blockedUntil,
                'reason' => ParserProxyState::reason(),
            ]);
            throw new RuntimeException('PROXY_BLOCKED_COOLDOWN_ACTIVE');
        }

        // Раньше был жёсткий потолок 15с — игнорировал ParserSetting.timeout_seconds
        // (60 по умолчанию) и резал нормальные запросы донора. Оставляем минимум 10с
        // и потолок 60с, чтобы не висеть бесконечно при дохлом прокси.
        $effectiveTimeout = max(10, min(60, $this->timeoutSeconds));

        if (! $this->networkModeLogged) {
            $this->networkModeLogged = true;
            Log::warning('[NETWORK MODE]', [
                'proxy_enabled' => $useProxy,
                'proxy_pool_count' => count($pool),
                'sequence' => $useProxy
                    ? 'random healthy proxy per attempt (or direct if none alive)'
                    : 'direct × 3',
            ]);
        }

        $lastThrowable = null;

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $attempt = $i + 1;
            $pick = null;
            if ($useProxy && $pool !== []) {
                $pick = $this->pickRandomHealthyProxy($pool);
            }
            $directOnly = ! $useProxy || $pick === null;
            $isFallback = $useProxy && $pick === null && $pool !== [];

            Log::warning('PROXY USED', [
                'proxy' => $directOnly ? 'direct' : $pick,
                'is_fallback' => $isFallback,
                'attempt' => $attempt,
            ]);

            try {
                $body = $this->executeOnce($url, $headers, $effectiveTimeout, ! $directOnly, $pick ?? '');
                if ($body !== '') {
                    ParserProxyState::clearOnHealthyResponse();
                    return $body;
                }
                throw new RuntimeException('EMPTY_BODY');
            } catch (Throwable $e) {
                $lastThrowable = $e;
                $errorMsg = $e->getMessage();
                if (! $directOnly && str_starts_with($errorMsg, 'HTTP_NON_200:429')) {
                    ParserProxyState::mark429($url);
                }
                if ($pick !== null && $this->shouldMarkProxyBad($e)) {
                    $this->markProxyBad($pick);
                }
                Log::warning('HTTP ATTEMPT FAILED', [
                    'mode' => $directOnly ? 'direct' : 'proxy',
                    'attempt' => $attempt,
                    'proxy_used' => $pick !== null,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep(2 * $attempt);
                }
            }
        }

        Log::critical('NETWORK FAILED', [
            'proxy_enabled' => $useProxy,
            'proxy_pool_count' => count($pool),
            'attempts' => self::MAX_ATTEMPTS,
            'last_error' => $lastThrowable?->getMessage(),
        ]);

        throw new RuntimeException('NETWORK FAILED AFTER RETRIES', 0, $lastThrowable ?? null);
    }

    private function executeOnce(
        string $url,
        array $headers,
        int $timeoutSeconds,
        bool $useProxy,
        string $proxyUrl,
    ): string {
        $options = [
            'timeout' => $timeoutSeconds,
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];

        if ($useProxy && $proxyUrl !== '') {
            $options['proxy'] = $proxyUrl;
        }

        $response = Http::timeout($timeoutSeconds)
            ->withOptions($options)
            ->withHeaders(array_merge([
                'User-Agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Connection' => 'keep-alive',
            ], $headers))
            ->get($url);

        $status = $response->status();
        if ($status !== 200) {
            throw new RuntimeException('HTTP_NON_200:'.$status);
        }

        return (string) $response->body();
    }

}

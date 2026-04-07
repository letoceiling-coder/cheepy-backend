<?php

namespace App\Services\Parser;

use App\Models\ParserSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HttpClient
{
    private const MAX_ATTEMPTS = 3;

    private const REQUEST_COOLDOWN_THRESHOLD = 100;

    private const REQUEST_COOLDOWN_SECONDS = 10;

    private int $requestCount = 0;

    private bool $networkModeLogged = false;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X)',
        'Mozilla/5.0 (X11; Linux x86_64)',
        'Mozilla/5.0 (Windows NT 10.0; WOW64)',
    ];

    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryCount = 3,
        private readonly int $delayMinMs = 1500,
        private readonly int $delayMaxMs = 3000,
        private readonly ?string $proxyUrlFromOptions = null,
        private readonly bool $useProxyFromOptions = false,
    ) {
    }

    /**
     * Effective proxy from job snapshot (options.runtime.http_client) merged with parser_settings.
     *
     * @return array{use_proxy: bool, proxy_url: string, url_from: string}
     */
    private function effectiveNetworkFromSettings(): array
    {
        $settings = ParserSetting::current();
        $useProxy = $this->useProxyFromOptions || (bool) $settings->proxy_enabled;

        $optUrl = trim((string) ($this->proxyUrlFromOptions ?? ''));
        $dbUrl = trim((string) ($settings->proxy_url ?? ''));
        $proxyUrl = $optUrl !== '' ? $optUrl : $dbUrl;
        $urlFrom = $optUrl !== '' ? 'options' : ($dbUrl !== '' ? 'parser_settings' : 'none');

        if ($useProxy && $proxyUrl === '') {
            Log::warning('[NETWORK] proxy_enabled but proxy_url empty, using direct only');
            $useProxy = false;
        }

        return [
            'use_proxy' => $useProxy,
            'proxy_url' => $proxyUrl,
            'url_from' => $urlFrom,
        ];
    }

    /**
     * Donor fetch: random delay, cooldown every 100 requests, timeout clamped 10–15s.
     * When proxy enabled: proxy → proxy → direct; otherwise direct × 3.
     * Does not pause parser or touch ParserState.
     *
     * @throws Throwable
     */
    public function get(string $url, array $headers = []): string
    {
        $delayMs = random_int($this->delayMinMs, $this->delayMaxMs);
        usleep($delayMs * 1000);

        $this->requestCount++;
        if ($this->requestCount > self::REQUEST_COOLDOWN_THRESHOLD) {
            sleep(self::REQUEST_COOLDOWN_SECONDS);
            $this->requestCount = 0;
        }

        $net = $this->effectiveNetworkFromSettings();
        $useProxy = $net['use_proxy'];
        $proxyUrl = $net['proxy_url'];

        $effectiveTimeout = max(10, min(15, $this->timeoutSeconds));

        if (! $this->networkModeLogged) {
            $this->networkModeLogged = true;
            Log::warning('[NETWORK MODE]', [
                'proxy_enabled' => $useProxy,
                'sequence' => $useProxy
                    ? 'proxy → proxy → direct'
                    : 'direct → direct → direct',
                'proxy_url_source' => $net['url_from'],
            ]);
        }

        $lastThrowable = null;

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $attempt = $i + 1;
            $mode = $useProxy
                ? ($attempt <= 2 ? 'proxy' : 'direct')
                : 'direct';

            try {
                $body = $this->executeOnce($url, $headers, $effectiveTimeout, $mode === 'proxy', $proxyUrl);
                if ($body !== '') {
                    return $body;
                }
                throw new RuntimeException('EMPTY_BODY');
            } catch (Throwable $e) {
                $lastThrowable = $e;
                Log::warning('HTTP ATTEMPT FAILED', [
                    'mode' => $mode,
                    'attempt' => $attempt,
                    'proxy_used' => $mode === 'proxy',
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
            'proxy_url' => $proxyUrl !== '' ? $proxyUrl : '(empty)',
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

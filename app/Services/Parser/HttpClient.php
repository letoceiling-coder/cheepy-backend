<?php

namespace App\Services\Parser;

use App\Models\ParserState;
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
        private readonly ?string $proxyUrlOverride = null,
        private readonly bool $useProxyOverride = false,
    ) {
        if ($this->proxyUrlOverride === null) {
            throw new RuntimeException('CRITICAL: HTTP CLIENT USED WITHOUT JOB OPTIONS');
        }
    }

    /**
     * Donor fetch: random delay, cooldown every 100 requests, timeout clamped 10–15s.
     * When proxy is enabled: 3 attempts — proxy, proxy retry, then direct (no global parser stop on proxy flake).
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

        $state = null;
        try {
            $state = ParserState::current();
        } catch (Throwable $e) {
            $state = null;
        }
        $parserRunning = $state?->isRunning() ?? false;

        // Donor may be unreachable from server IP without proxy — do not gate proxy on ParserState::running.
        $viaProxy = $this->useProxyOverride && $this->proxyUrlOverride !== '';
        $effectiveTimeout = max(10, min(15, $this->timeoutSeconds));

        if (! $this->networkModeLogged) {
            $this->networkModeLogged = true;
            Log::warning('[NETWORK MODE]', [
                'mode' => $viaProxy ? 'proxy_then_direct' : 'direct_only',
                'sequence' => $viaProxy ? 'proxy,proxy_retry,direct' : 'direct,direct,direct',
                'parser_running' => $parserRunning,
            ]);
        }

        $lastThrowable = null;

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $attempt = $i + 1;
            // 1) proxy  2) proxy retry  3) direct (stable fallback — не останавливаем парсер из‑за прокси)
            $useProxy = $viaProxy && $attempt <= 2;

            try {
                return $this->executeOnce($url, $headers, $effectiveTimeout, $useProxy);
            } catch (Throwable $e) {
                $lastThrowable = $e;

                if ($attempt < self::MAX_ATTEMPTS) {
                    Log::warning('HTTP attempt failed', [
                        'attempt' => $attempt,
                        'next' => $attempt === 1 ? 'proxy_retry' : 'direct',
                        'mode' => $useProxy ? 'proxy' : 'direct',
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                    sleep(2 * $attempt);
                }
            }
        }

        throw $lastThrowable ?? new RuntimeException('HTTP request failed after '.self::MAX_ATTEMPTS.' attempts');
    }

    private function executeOnce(
        string $url,
        array $headers,
        int $timeoutSeconds,
        bool $useProxy,
    ): string {
        $options = [
            'timeout' => $timeoutSeconds,
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];

        if ($useProxy && $this->proxyUrlOverride !== '') {
            $options['proxy'] = $this->proxyUrlOverride;
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

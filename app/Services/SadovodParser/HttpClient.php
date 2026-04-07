<?php

namespace App\Services\SadovodParser;

use App\Services\Parser\HttpClient as ParserHttpClient;
use App\Services\ParserMetricsService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Symfony\Component\DomCrawler\Crawler;

class HttpClient
{
    private const NETWORK_TIMEOUT_STREAK_KEY = 'parser:network_timeout_streak';
    private Client $client;
    private string $baseUrl;
    private array $userAgents;
    private int $agentIndex = 0;
    private int $delayMinMs;
    private int $delayMaxMs;
    private int $maxRpm;
    private int $retryCount;
    private array $retryBackoff;
    private array $blockCodes;
    private float $minRequestInterval;
    private ?float $lastRequestAt = null;
    private ParserHttpClient $requestClient;

    /**
     * @param  array<string, mixed>  $config  Frozen snapshot from parser_jobs.options.runtime.http_client
     */
    public function __construct(array $config = [])
    {
        $required = [
            'base_url',
            'verify_ssl',
            'delay_min_ms',
            'delay_max_ms',
            'timeout_seconds',
            'user_agents',
            'max_requests_per_minute',
            'max_requests_per_second',
            'retry_count',
            'retry_backoff_seconds',
            'block_codes',
            'proxy_url',
            'use_proxy',
            'request_delay_ms',
            'product_broadcast_every',
        ];
        foreach ($required as $key) {
            if (! array_key_exists($key, $config)) {
                throw new RuntimeException('CRITICAL: http_client.'.$key.' missing');
            }
        }
        if (! is_array($config['user_agents']) || $config['user_agents'] === []) {
            throw new RuntimeException('CRITICAL: http_client.user_agents invalid');
        }
        if (! is_string($config['proxy_url'])) {
            throw new RuntimeException('CRITICAL: http_client.proxy_url must be string');
        }

        $this->baseUrl = (string) $config['base_url'];
        $this->delayMinMs = (int) $config['delay_min_ms'];
        $this->delayMaxMs = (int) $config['delay_max_ms'];
        $this->maxRpm = (int) $config['max_requests_per_minute'];
        $maxRps = $config['max_requests_per_second'];
        $this->retryCount = (int) $config['retry_count'];
        $this->retryBackoff = $config['retry_backoff_seconds'];
        if (! is_array($this->retryBackoff)) {
            throw new RuntimeException('CRITICAL: http_client.retry_backoff_seconds invalid');
        }
        $this->blockCodes = $config['block_codes'];
        if (! is_array($this->blockCodes)) {
            throw new RuntimeException('CRITICAL: http_client.block_codes invalid');
        }
        $this->minRequestInterval = is_numeric($maxRps) && (float) $maxRps > 0
            ? (1.0 / (float) $maxRps)
            : (60.0 / max(1, $this->maxRpm));

        $this->userAgents = $config['user_agents'];

        $verify = (bool) $config['verify_ssl'];
        $timeout = (int) $config['timeout_seconds'];
        $proxyUrlStr = $config['proxy_url'];
        $useProxy = (bool) $config['use_proxy'];
        $this->requestClient = new ParserHttpClient(
            timeoutSeconds: max(10, $timeout),
            retryCount: $this->retryCount,
            delayMinMs: max(100, $this->delayMinMs),
            delayMaxMs: max($this->delayMinMs, $this->delayMaxMs),
            proxyUrlFromOptions: $proxyUrlStr,
            useProxyFromOptions: $useProxy,
        );
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'verify' => $verify,
            'allow_redirects' => true,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ]);
    }

    private function getNextUserAgent(): string
    {
        $ua = $this->userAgents[$this->agentIndex % count($this->userAgents)];
        $this->agentIndex++;
        return $ua;
    }

    private function applyRateLimit(): void
    {
        if ($this->lastRequestAt !== null) {
            $elapsed = microtime(true) - $this->lastRequestAt;
            $wait = $this->minRequestInterval - $elapsed;
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
    }

    /**
     * Only treat as block for HTTP 200 if HTML clearly is a block/captcha page,
     * not the real site. Valid site contains "sadovodbaza" or menu container.
     * Do NOT treat HTTP 200 as block based on generic words like "cloudflare" or "block"
     * that can appear in normal site HTML.
     */
    /**
     * Логируем блокировку/невалидный ответ; глобально парсер не ставим на паузу (сеть может восстановиться).
     */
    private function reactDonorBlocked(string $reason, string $path, string $url, string $bodyPreview = ''): void
    {
        Log::critical('DONOR BLOCKED', [
            'reason' => $reason,
            'path' => $path,
            'url' => $url,
            'preview' => $bodyPreview !== '' ? mb_substr($bodyPreview, 0, 500) : null,
        ]);

        throw new RuntimeException('DONOR BLOCKED');
    }

    private function isTimeoutMessage(string $msg): bool
    {
        $m = mb_strtolower($msg);

        return str_contains($m, 'timed out')
            || str_contains($m, 'connection timed out')
            || str_contains($m, 'curl error 28')
            || str_contains($m, 'operation timed out');
    }

    private function detectBlock(string $html, int $statusCode): bool
    {
        if (in_array($statusCode, $this->blockCodes, true)) {
            return true;
        }
        if ($statusCode !== 200) {
            return false;
        }

        $lower = mb_strtolower($html);

        // Valid site markers: if present, this is the real page — do not block
        $validMarkers = ['sadovodbaza', 'menu-catalog', 'menu-main', 'id="w1"', 'navbar-brand'];
        foreach ($validMarkers as $m) {
            if (str_contains($lower, $m)) {
                return false;
            }
        }

        // Clear block/captcha page markers only (no generic "block" or "cloudflare" alone)
        $blockMarkers = [
            'cf-browser-verification',
            'challenge-running',
            'g-recaptcha',
            'recaptcha/api.js',
            'access denied',
            'доступ запрещён',
            'checking your browser',
            'just a moment',
        ];
        foreach ($blockMarkers as $p) {
            if (str_contains($lower, $p)) {
                Log::info('HttpClient block detected (marker)', ['marker' => $p, 'preview' => substr($html, 0, 500)]);
                return true;
            }
        }
        return false;
    }

    /**
     * @param int|null $timeoutSeconds Override default timeout (e.g. 10 for seller pages)
     * @param int|null $retries Override default retry count (e.g. 3 for seller pages)
     */
    public function get(string $path, ?int $timeoutSeconds = null, ?int $retries = null): string
    {
        $this->applyRateLimit();

        $url = $this->getAbsoluteUrl($path);

        try {
            $ua = $this->getNextUserAgent();
            $body = $this->requestClient->get($url, [
                'User-Agent' => $ua,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ]);
            $statusCode = 200;

            $lower = mb_strtolower($body);
            if (strlen($body) < 1000 || ! str_contains($lower, 'sadovodbaza')) {
                Log::warning('DONOR HTML preview (len/marker check relaxed)', [
                    'len' => strlen($body),
                    'path' => $path,
                    'url' => $url,
                    'body' => substr($body, 0, 500),
                ]);
            }

            if ($this->detectBlock($body, $statusCode)) {
                ParserMetricsService::incrementBlocked();
                $this->reactDonorBlocked('detect_block', $path, $url, $body);
            }

            if ($path === '/' || $path === '') {
                Log::debug('HttpClient response preview', ['path' => $path, 'preview' => substr($body, 0, 500)]);
            }
            Cache::put(self::NETWORK_TIMEOUT_STREAK_KEY, 0, now()->addMinutes(30));
            $this->lastRequestAt = microtime(true);
            ParserMetricsService::incrementRequests();

            return $body;
        } catch (Throwable $e) {
            if ($e->getMessage() === 'DONOR BLOCKED') {
                throw $e;
            }

            $msg = $e->getMessage();
            if (str_starts_with($msg, 'HTTP_NON_200:')) {
                ParserMetricsService::incrementBlocked();
                $this->reactDonorBlocked('invalid_response', $path, $url, '');
            }

            if ($this->isTimeoutMessage($msg)) {
                Log::warning('Parser timeout (after retries)', ['url' => $url]);
                ParserMetricsService::incrementRetries();
                $this->reactDonorBlocked('timeout', $path, $url, '');
            }

            Cache::put(self::NETWORK_TIMEOUT_STREAK_KEY, 0, now()->addMinutes(30));
            ParserMetricsService::incrementRetries();
            throw $e;
        }
    }

    /**
     * @param array{timeout?: int, retries?: int} $options e.g. ['timeout' => 10, 'retries' => 3] for seller pages
     */
    public function getCrawler(string $path, array $options = []): Crawler
    {
        $timeout = $options['timeout'] ?? null;
        $retries = $options['retries'] ?? null;
        $html = $this->get($path, $timeout, $retries);
        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');
        return $crawler;
    }

    public function getAbsoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

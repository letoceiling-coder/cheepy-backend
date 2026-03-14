<?php

namespace App\Services\SadovodParser;

use App\Models\ParserSetting;
use App\Models\ParserState;
use App\Services\Parser\ParserLogger;
use App\Services\Parser\HttpClient as ParserHttpClient;
use App\Services\ParserMetricsService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class HttpClient
{
    private const NETWORK_TIMEOUT_STREAK_KEY = 'parser:network_timeout_streak';
    private const NETWORK_TIMEOUT_THRESHOLD = 5;
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

    public function __construct(array $config = [])
    {
        $parserSettings = ParserSetting::current();
        $this->baseUrl = $config['base_url'] ?? config('sadovod.base_url', 'https://sadovodbaza.ru');
        $this->delayMinMs = (int) ($parserSettings->request_delay_min ?? ($config['delay_min_ms'] ?? config('parser_rate.delay_min_ms', 800)));
        $this->delayMaxMs = (int) ($parserSettings->request_delay_max ?? ($config['delay_max_ms'] ?? config('parser_rate.delay_max_ms', 2000)));
        $this->maxRpm = config('parser_rate.max_requests_per_minute', 300);
        $maxRps = config('parser_rate.max_requests_per_second');
        $this->retryCount = config('parser_rate.retry_count', 3);
        $this->retryBackoff = config('parser_rate.retry_backoff_seconds', [2, 5, 10]);
        $this->blockCodes = config('parser_rate.block_codes', [403, 429]);
        $this->minRequestInterval = $maxRps > 0
            ? (1.0 / $maxRps)
            : (60.0 / max(1, $this->maxRpm));

        $agents = config('parser_user_agents.agents', []);
        $this->userAgents = !empty($agents)
            ? $agents
            : [$config['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'];

        $verify = $config['verify_ssl'] ?? config('sadovod.verify_ssl', true);
        $timeout = (int) ($parserSettings->timeout_seconds ?? 60);
        $this->requestClient = new ParserHttpClient(
            timeoutSeconds: max(10, $timeout),
            retryCount: $this->retryCount,
            delayMinMs: max(100, $this->delayMinMs),
            delayMaxMs: max($this->delayMinMs, $this->delayMaxMs)
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

    private function applyDelay(): void
    {
        $delayMs = random_int($this->delayMinMs, $this->delayMaxMs);
        usleep($delayMs * 1000);
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
        $this->applyDelay();

        try {
            $ua = $this->getNextUserAgent();
            $url = $this->getAbsoluteUrl($path);
            $body = $this->requestClient->get($url, [
                'User-Agent' => $ua,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ]);
            $statusCode = 200;

            if ($this->detectBlock($body, $statusCode)) {
                ParserMetricsService::incrementBlocked();
                Log::warning('Parser: block detected', ['path' => $path, 'status' => $statusCode, 'preview' => substr($body, 0, 500)]);
                throw new \RuntimeException("Block detected: HTTP {$statusCode}");
            }

            if ($path === '/' || $path === '') {
                Log::debug('HttpClient response preview', ['path' => $path, 'preview' => substr($body, 0, 500)]);
            }
            Cache::put(self::NETWORK_TIMEOUT_STREAK_KEY, 0, now()->addMinutes(30));
            $this->lastRequestAt = microtime(true);
            ParserMetricsService::incrementRequests();
            return $body;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'timed out') || str_contains($msg, 'Connection timed out') || str_contains($msg, 'cURL error 28')) {
                Log::warning('Parser timeout', ['url' => $this->getAbsoluteUrl($path)]);
                $this->registerNetworkTimeout($this->getAbsoluteUrl($path));
            } else {
                Cache::put(self::NETWORK_TIMEOUT_STREAK_KEY, 0, now()->addMinutes(30));
            }
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

    private function registerNetworkTimeout(string $url): void
    {
        $streak = (int) Cache::get(self::NETWORK_TIMEOUT_STREAK_KEY, 0) + 1;
        Cache::put(self::NETWORK_TIMEOUT_STREAK_KEY, $streak, now()->addMinutes(30));

        ParserLogger::write('network_error', 'Parser timeout streak incremented', [
            'url' => $url,
            'attempt' => $streak,
        ]);

        if ($streak < self::NETWORK_TIMEOUT_THRESHOLD) {
            return;
        }

        $state = ParserState::current();
        if ($state->status !== ParserState::STATUS_PAUSED_NETWORK) {
            $state->update(['status' => ParserState::STATUS_PAUSED_NETWORK]);
            ParserLogger::write('network_error', 'Parser switched to PAUSED_NETWORK after timeout streak', [
                'url' => $url,
                'attempt' => $streak,
            ]);
        }
    }
}

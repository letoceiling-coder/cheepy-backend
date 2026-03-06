<?php

namespace App\Services\SadovodParser;

use App\Services\ParserMetricsService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class HttpClient
{
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

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url'] ?? config('sadovod.base_url', 'https://sadovodbaza.ru');
        $this->delayMinMs = config('parser_rate.delay_min_ms', 500);
        $this->delayMaxMs = config('parser_rate.delay_max_ms', 2000);
        $this->maxRpm = config('parser_rate.max_requests_per_minute', 60);
        $this->retryCount = config('parser_rate.retry_count', 3);
        $this->retryBackoff = config('parser_rate.retry_backoff_seconds', [2, 5, 10]);
        $this->blockCodes = config('parser_rate.block_codes', [403, 429]);
        $this->minRequestInterval = 60.0 / max(1, $this->maxRpm);

        $agents = config('parser_user_agents.agents', []);
        $this->userAgents = !empty($agents)
            ? $agents
            : [$config['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'];

        $verify = $config['verify_ssl'] ?? config('sadovod.verify_ssl', true);
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'verify' => $verify,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
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

    private function detectBlock(string $html, int $statusCode): bool
    {
        if (in_array($statusCode, $this->blockCodes, true)) {
            return true;
        }
        $captchaPatterns = ['captcha', 'капча', 'recaptcha', 'cloudflare', 'access denied', 'доступ запрещён', 'blocked'];
        $lower = mb_strtolower($html);
        foreach ($captchaPatterns as $p) {
            if (str_contains($lower, $p)) {
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

        $timeout = $timeoutSeconds ?? 30;
        $maxAttempts = ($retries ?? $this->retryCount) + 1;

        $lastError = null;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $ua = $this->getNextUserAgent();
                $response = $this->client->get($path, [
                    'headers' => ['User-Agent' => $ua],
                    'timeout' => $timeout,
                ]);
                $statusCode = $response->getStatusCode();
                $body = (string) $response->getBody();

                if ($this->detectBlock($body, $statusCode)) {
                    ParserMetricsService::incrementBlocked();
                    Log::warning('Parser: block detected', ['path' => $path, 'status' => $statusCode]);
                    $delayMs = min($this->delayMaxMs * 2, 10000);
                    usleep($delayMs * 1000);
                    throw new \RuntimeException("Block detected: HTTP {$statusCode}");
                }

                $this->lastRequestAt = microtime(true);
                ParserMetricsService::incrementRequests();
                return $body;
            } catch (RequestException $e) {
                $lastError = $e;
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                if (in_array($statusCode, $this->blockCodes, true)) {
                    ParserMetricsService::incrementBlocked();
                    Log::warning('Parser: block response', ['path' => $path, 'status' => $statusCode]);
                }
                if ($attempt < $maxAttempts - 1) {
                    ParserMetricsService::incrementRetries();
                    $backoff = $this->retryBackoff[$attempt] ?? 10;
                    sleep($backoff);
                } else {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new \RuntimeException('Request failed');
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

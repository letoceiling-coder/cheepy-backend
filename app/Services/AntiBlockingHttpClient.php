<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Anti-blocking HTTP client wrapper.
 * Apply to SadovodParser\HttpClient or use as middleware.
 *
 * Features:
 * - Request delay randomization (min-max ms)
 * - Rotating user agents
 * - Retry with exponential backoff
 * - Automatic pause if error rate increases
 */
class AntiBlockingHttpClient
{
    private int $requestCount = 0;
    private int $errorCount = 0;
    private float $lastErrorRate = 0;
    private float $errorRateThreshold = 0.1; // 10% → pause
    private int $pauseDurationSec = 60;

    public function __construct(
        private readonly int $delayMinMs,
        private readonly int $delayMaxMs,
        private readonly array $userAgents,
        private readonly int $maxRetries = 3,
        private readonly array $retryCodes = [429, 503, 408, 500]
    ) {
    }

    public function delayBeforeRequest(): void
    {
        $delayMs = random_int($this->delayMinMs, $this->delayMaxMs);
        usleep($delayMs * 1000);
    }

    public function getRandomUserAgent(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    public function recordSuccess(): void
    {
        $this->requestCount++;
    }

    public function recordError(): void
    {
        $this->requestCount++;
        $this->errorCount++;
        $rate = $this->requestCount > 0 ? $this->errorCount / $this->requestCount : 0;
        $this->lastErrorRate = $rate;

        if ($rate >= $this->errorRateThreshold && $this->requestCount >= 10) {
            Log::warning('AntiBlocking: High error rate detected, pausing', [
                'rate' => round($rate * 100, 1) . '%',
                'pause_sec' => $this->pauseDurationSec,
            ]);
            sleep($this->pauseDurationSec);
        }
    }

    public function shouldRetry(int $statusCode): bool
    {
        return in_array($statusCode, $this->retryCodes);
    }

    public function getBackoffSeconds(int $attempt): int
    {
        return (int) min(300, pow(2, $attempt) * 5); // 5, 10, 20, 40... max 300
    }
}

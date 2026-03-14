<?php

namespace App\Services\Parser;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryCount = 3,
        private readonly int $delayMinMs = 1500,
        private readonly int $delayMaxMs = 3000,
    ) {
    }

    /**
     * @throws RequestException
     */
    public function get(string $url, array $headers = []): string
    {
        usleep(random_int($this->delayMinMs * 1000, $this->delayMaxMs * 1000));

        $response = Http::timeout($this->timeoutSeconds)
            ->retry($this->retryCount, function (int $attempt): int {
                // exponential-like backoff: 5s, 10s, 15s
                return $attempt * 5000;
            })
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
            ->withHeaders(array_merge([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Connection' => 'keep-alive',
            ], $headers))
            ->get($url);

        $response->throw();

        return (string) $response->body();
    }
}

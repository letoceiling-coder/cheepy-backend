<?php

namespace App\Services\Parser;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class HttpClient
{
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
    ) {
    }

    /**
     * @throws RequestException
     */
    public function get(string $url, array $headers = []): string
    {
        usleep(random_int($this->delayMinMs * 1000, $this->delayMaxMs * 1000));

        $proxyEnabled = (bool) config('parser.proxy_enabled', true);
        $proxyUrl = (string) (config('parser.proxy') ?: config('parser.proxy_url'));
        $options = [
            'timeout' => (int) config('parser.timeout', $this->timeoutSeconds),
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];

        if ($proxyEnabled && $proxyUrl !== '') {
            $options['proxy'] = $proxyUrl;
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->retry($this->retryCount, function (int $attempt): int {
                return (int) (1000 * (2 ** max(0, $attempt - 1)));
            })
            ->withOptions($options)
            ->withHeaders(array_merge([
                'User-Agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Connection' => 'keep-alive',
            ], $headers))
            ->get($url);

        $response->throw();

        return (string) $response->body();
    }
}

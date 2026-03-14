<?php

namespace App\Services\Parser;

use App\Models\ParserSetting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class HttpClient
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    private bool $proxyEnabled;
    private ?string $proxyUrl;

    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryCount = 3,
        private readonly int $delayMinMs = 1500,
        private readonly int $delayMaxMs = 3000,
    ) {
        $settings = ParserSetting::current();
        $this->proxyEnabled = (bool) ($settings->proxy_enabled ?? config('parser.proxy_enabled', false));
        $this->proxyUrl = $settings->proxy_url ?: (config('parser.proxy') ?: config('parser.proxy_url'));
    }

    /**
     * @throws RequestException
     */
    public function get(string $url, array $headers = []): string
    {
        usleep(random_int($this->delayMinMs * 1000, $this->delayMaxMs * 1000));

        $options = [
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];

        if ($this->proxyEnabled && $this->proxyUrl) {
            $options['proxy'] = $this->proxyUrl;
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

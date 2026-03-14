<?php

namespace App\Console\Commands;

use App\Models\ParserSetting;
use App\Models\ParserState;
use App\Services\Parser\ParserLogger;
use App\Services\Parser\HttpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ParserTest extends Command
{
    protected $signature = 'parser:test {--url=https://sadovodbaza.ru : Base parser URL}';
    protected $description = 'Smoke-test parser proxy access, HTTP availability and selectors';

    public function handle(): int
    {
        $url = (string) $this->option('url');
        $settings = ParserSetting::current();
        $proxyEnabled = (bool) config('parser.proxy_enabled', true);
        $proxyUrl = (string) (config('parser.proxy') ?: config('parser.proxy_url', 'http://89.169.39.244:3128'));

        $this->line('proxy: checking...');
        if (!$proxyEnabled || $proxyUrl === '') {
            $this->error('proxy: FAIL - proxy is disabled or URL is missing');
            ParserState::current()->update([
                'status' => ParserState::STATUS_PAUSED_NETWORK,
                'last_stop' => now(),
            ]);
            ParserLogger::write('network_error', 'Proxy test failed: disabled or missing URL', [
                'url' => $url,
            ]);
            return 1;
        }
        try {
            $curlCmd = sprintf('curl -I -x %s %s -m 20', escapeshellarg($proxyUrl), escapeshellarg($url));
            $curlOut = function_exists('shell_exec') ? (string) @shell_exec($curlCmd . ' 2>&1') : '';
            if ($curlOut !== '' && !str_contains($curlOut, '200')) {
                throw new \RuntimeException(trim($curlOut));
            }
            if ($curlOut === '') {
                Http::timeout(20)->withOptions([
                    'proxy' => $proxyUrl,
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ])->get($url)->throw();
            }
            $this->line('proxy: OK');
        } catch (\Throwable $e) {
            $this->error('proxy: FAIL - ' . $e->getMessage());
            ParserState::current()->update([
                'status' => ParserState::STATUS_PAUSED_NETWORK,
                'last_stop' => now(),
            ]);
            ParserLogger::write('network_error', 'Proxy test failed, daemon blocked', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return 1;
        }

        $http = new HttpClient(
            timeoutSeconds: max(10, (int) $settings->timeout_seconds),
            retryCount: 3,
            delayMinMs: max(100, (int) $settings->request_delay_min),
            delayMaxMs: max(500, (int) $settings->request_delay_max),
        );

        try {
            $html = $http->get($url);
        } catch (\Throwable $e) {
            $this->error('HTTP check failed: ' . $e->getMessage());
            return 1;
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        $checks = [
            'title' => $crawler->filter('title')->count() > 0,
            'body' => $crawler->filter('body')->count() > 0,
            'catalog_links' => $crawler->filter('a[href*="/catalog/"]')->count() > 0,
            'product_links' => $crawler->filter('a[href*="/odejda/"]')->count() > 0,
        ];

        foreach ($checks as $name => $ok) {
            $this->line(sprintf('%s: %s', $name, $ok ? 'OK' : 'FAIL'));
        }

        if (in_array(false, $checks, true)) {
            $this->warn('Parser selectors need review.');
            return 1;
        }

        $this->info('Parser test passed.');
        return 0;
    }
}

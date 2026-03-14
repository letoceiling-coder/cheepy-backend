<?php

namespace App\Console\Commands;

use App\Models\ParserSetting;
use App\Services\Parser\HttpClient;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

class ParserTest extends Command
{
    protected $signature = 'parser:test {--url=https://sadovodbaza.ru : Base parser URL}';
    protected $description = 'Smoke-test parser HTTP access, HTML structure and selectors';

    public function handle(): int
    {
        $url = (string) $this->option('url');
        $settings = ParserSetting::current();
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

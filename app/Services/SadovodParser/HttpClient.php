<?php

namespace App\Services\SadovodParser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;

class HttpClient
{
    private Client $client;
    private string $baseUrl;
    private int $delayMs;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url'] ?? 'https://sadovodbaza.ru';
        $this->delayMs = $config['request_delay_ms'] ?? 500;
        $verify = $config['verify_ssl'] ?? true;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'verify' => $verify,
            'headers' => [
                'User-Agent' => $config['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
            ],
        ]);
    }

    public function get(string $path): string
    {
        usleep($this->delayMs * 1000);
        $response = $this->client->get($path);
        return (string) $response->getBody();
    }

    public function getCrawler(string $path): Crawler
    {
        $html = $this->get($path);
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

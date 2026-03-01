<?php

namespace App\Services\SadovodParser\Parsers;

use App\Services\SadovodParser\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

class SellerParser
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Parse seller page: name, pavilion, description, contacts, product URLs.
     *
     * @return array{name: string, slug: string, url: string, pavilion: string, description: string, contacts: array, products: array}
     */
    public function parse(string $path): array
    {
        $crawler = $this->http->getCrawler($path);
        $baseUrl = $this->http->getBaseUrl();

        $slug = '';
        if (preg_match('#/s/([a-z0-9\-]+)#', $path, $m)) {
            $slug = $m[1];
        }

        $name = $this->extractName($crawler);
        $pavilion = $this->extractPavilion($crawler);
        $description = $this->extractDescription($crawler);
        $contacts = $this->extractContacts($crawler);
        $products = $this->extractProductLinks($crawler, $baseUrl);

        return [
            'name' => $name,
            'slug' => $slug,
            'url' => str_starts_with($path, 'http') ? $path : rtrim($baseUrl, '/') . '/' . ltrim($path, '/'),
            'pavilion' => $pavilion,
            'description' => $description,
            'contacts' => $contacts,
            'products' => $products,
        ];
    }

    private function extractName(Crawler $crawler): string
    {
        try {
            $h1 = $crawler->filter('main h1, h1')->first();
            return trim($h1->text());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractPavilion(Crawler $crawler): string
    {
        try {
            $node = $crawler->filter('a[href*="shop/map"], [class*="pavilion"]')->first();
            return trim($node->text());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractDescription(Crawler $crawler): string
    {
        $parts = [];
        try {
            $crawler->filter('main p, main [class*="description"]')->each(function (Crawler $node) use (&$parts) {
                $t = trim($node->text());
                if (strlen($t) > 15 && !str_contains($t, 'Свяжитесь') && !str_contains($t, 'Заказать')) {
                    $parts[] = $t;
                }
            });
        } catch (\Throwable $e) {
        }
        return implode("\n", $parts);
    }

    private function extractContacts(Crawler $crawler): array
    {
        $contacts = ['phone' => '', 'whatsapp' => ''];
        try {
            $main = $crawler->filter('main')->text();
            if (preg_match('/\+7\s*\([\d\s\)\-]+/u', $main, $m)) {
                $contacts['phone'] = trim($m[0]);
            }
            $crawler->filter('main a[href*="wa.me"], main a[href*="whatsapp"]')->each(function (Crawler $node) use (&$contacts) {
                $contacts['whatsapp'] = $node->attr('href') ?? '';
            });
        } catch (\Throwable $e) {
        }
        return $contacts;
    }

    private function extractProductLinks(Crawler $crawler, string $baseUrl): array
    {
        $products = [];
        $crawler->filter('main a[href*="/odejda/"]')->each(function (Crawler $node) use (&$products, $baseUrl) {
            $href = $node->attr('href');
            if (!$href || !preg_match('#/odejda/(\d+)#', $href, $m)) {
                return;
            }
            $id = $m[1];
            if (isset($products[$id])) {
                return;
            }
            $products[$id] = [
                'id' => $id,
                'url' => str_starts_with($href, 'http') ? $href : rtrim($baseUrl, '/') . '/' . ltrim(parse_url($href, PHP_URL_PATH) ?? '', '/'),
            ];
        });
        return array_values($products);
    }
}

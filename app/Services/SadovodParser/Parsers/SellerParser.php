<?php

namespace App\Services\SadovodParser\Parsers;

use App\Services\SadovodParser\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parse seller page /s/{slug}.
 * Selectors: .shop-view h1, .shop-avatar, .shop-info__description,
 * .shop-contact__phone, .shop-contact__whatsapp, .pavilion.
 */
class SellerParser
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Parse seller page: name, pavilion, description, contacts, avatar, product URLs.
     *
     * @return array{name: string, slug: string, url: string, pavilion: string, description: string, avatar: string, contacts: array, products: array}
     */
    public function parse(string $path): array
    {
        $crawler = $this->http->getCrawler($path, ['timeout' => 10, 'retries' => 3]);
        $baseUrl = $this->http->getBaseUrl();

        $slug = '';
        if (preg_match('#/s/([a-z0-9\-]+)#', $path, $m)) {
            $slug = $m[1];
        }

        $name = $this->extractName($crawler);
        $pavilion = $this->extractPavilion($crawler);
        $description = $this->extractDescription($crawler);
        $avatar = $this->extractAvatar($crawler, $baseUrl);
        $contacts = $this->extractContacts($crawler);
        $products = $this->extractProductLinks($crawler, $baseUrl);

        $url = str_starts_with($path, 'http') ? $path : rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        return [
            'name' => $name,
            'slug' => $slug,
            'url' => $url,
            'pavilion' => $pavilion,
            'description' => $description,
            'avatar' => $avatar,
            'contacts' => $contacts,
            'products' => $products,
        ];
    }

    private function extractName(Crawler $crawler): string
    {
        try {
            $h1 = $crawler->filter('.shop-view h1')->first();
            return trim($h1->text());
        } catch (\Throwable $e) {
            try {
                $h1 = $crawler->filter('main h1, h1')->first();
                return trim($h1->text());
            } catch (\Throwable $e2) {
                return '';
            }
        }
    }

    private function extractPavilion(Crawler $crawler): string
    {
        try {
            $node = $crawler->filter('.pavilion')->first();
            return trim($node->text());
        } catch (\Throwable $e) {
            try {
                $node = $crawler->filter('[class*="pavilion"]')->first();
                return trim($node->text());
            } catch (\Throwable $e2) {
                return '';
            }
        }
    }

    private function extractDescription(Crawler $crawler): string
    {
        try {
            $node = $crawler->filter('.shop-info__description')->first();
            return trim($node->text());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractAvatar(Crawler $crawler, string $baseUrl): string
    {
        try {
            $nodes = $crawler->filter('.shop-avatar img');
            if ($nodes->count() > 0) {
                $src = $nodes->first()->attr('src');
                if ($src) {
                    return str_starts_with($src, 'http') ? $src : rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private function extractContacts(Crawler $crawler): array
    {
        $contacts = ['phone' => '', 'whatsapp' => ''];
        try {
            $phoneNodes = $crawler->filter('.shop-contact__phone');
            if ($phoneNodes->count() > 0) {
                $contacts['phone'] = trim($phoneNodes->first()->text());
            }
        } catch (\Throwable $e) {
        }
        try {
            $waNodes = $crawler->filter('.shop-contact__whatsapp');
            if ($waNodes->count() > 0) {
                $href = $waNodes->first()->attr('href');
                if ($href) {
                    $contacts['whatsapp'] = $href;
                }
            }
        } catch (\Throwable $e) {
        }
        if (empty($contacts['phone']) || empty($contacts['whatsapp'])) {
            $crawler->filter('main a[href*="wa.me"], main a[href*="whatsapp"]')->each(function (Crawler $node) use (&$contacts) {
                if (empty($contacts['whatsapp'])) {
                    $contacts['whatsapp'] = $node->attr('href') ?? '';
                }
            });
            if (empty($contacts['phone'])) {
                $main = $crawler->filter('main')->text();
                if (preg_match('/\+7\s*\([\d\s\)\-]+/u', $main, $m)) {
                    $contacts['phone'] = trim($m[0]);
                }
            }
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

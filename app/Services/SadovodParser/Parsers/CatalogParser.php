<?php

namespace App\Services\SadovodParser\Parsers;

use App\Services\SadovodParser\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

class CatalogParser
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Get product URLs from a category (with optional limit and max pages).
     *
     * @param int $productsLimit 0 = no limit. Stop after collecting this many products.
     * @param int $maxPages 0 = no limit. Stop after this many catalog pages.
     * @return array{products: array<int, array{url: string, id: string, title: string, price: string}>, subcategories: array}
     */
    public function parseCategory(string $catalogPath, int $productsLimit = 0, int $maxPages = 0): array
    {
        $products = [];
        $subcategories = [];
        $page = 1;
        $maxPages = $maxPages > 0 ? $maxPages : 999;

        do {
            $path = $catalogPath . (str_contains($catalogPath, '?') ? '&' : '?') . 'page=' . $page;
            $crawler = $this->http->getCrawler($path);

            $pageProducts = $this->extractProductsFromListing($crawler);
            if (empty($pageProducts)) {
                break;
            }

            foreach ($pageProducts as $p) {
                $products[$p['id']] = $p;
                if ($productsLimit > 0 && count($products) >= $productsLimit) {
                    break 2;
                }
            }

            if ($page === 1) {
                $subcategories = $this->extractSubcategories($crawler);
            }

            $hasNext = $this->hasNextPage($crawler) && $page < $maxPages;
            $page++;
        } while ($hasNext && count($pageProducts) > 0);

        return [
            'products' => array_values($products),
            'subcategories' => $subcategories,
        ];
    }

    private function extractProductsFromListing(Crawler $crawler): array
    {
        $list = [];
        $baseUrl = $this->http->getBaseUrl();

        // Product links: a[href*="/odejda/"] (product URL pattern)
        $crawler->filter('a[href*="/odejda/"]')->each(function (Crawler $node) use (&$list, $baseUrl) {
            $href = $node->attr('href');
            if (!$href) {
                return;
            }
            $path = parse_url($href, PHP_URL_PATH);
            if (!$path || !preg_match('#/odejda/(\d+)#', $path, $m)) {
                return;
            }
            $id = $m[1];
            if (isset($list[$id])) {
                return;
            }
            $title = trim($node->attr('title') ?? $node->text() ?? '');
            $photos = [];
            try {
                $imgNode = $node->filter('img')->first();
                if ($imgNode->count() > 0) {
                    $src = $imgNode->attr('src') ?? $imgNode->attr('data-src');
                    if ($src) {
                        $photoUrl = str_starts_with($src, 'http') ? $src : rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
                        $photos[] = $photoUrl;
                    }
                    if ($title === '' && $imgNode->attr('alt')) {
                        $title = trim(preg_replace('/\s*САДОВОД.*$/u', '', $imgNode->attr('alt')));
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            $price = '';
            $current = $node->getNode(0)->nextSibling;
            while ($current) {
                if ($current->nodeType === XML_ELEMENT_NODE) {
                    $text = trim($current->textContent ?? '');
                    if (preg_match('/\d+\s*₽/u', $text)) {
                        $price = preg_replace('/^.*?(\d[\d\s]*\s*₽).*$/us', '$1', $text);
                        $price = trim(preg_replace('/\s+/u', ' ', $price));
                    } elseif ($title === '' && $text !== '' && strlen($text) < 200 && !preg_match('/^[\d\s₽]+$/u', $text)) {
                        $title = $text;
                    }
                }
                $current = $current->nextSibling;
            }
            $list[$id] = [
                'id' => $id,
                'url' => str_starts_with($href, 'http') ? $href : rtrim($baseUrl, '/') . '/' . ltrim($path, '/'),
                'path' => $path,
                'title' => $title,
                'price' => $price,
                'photos' => $photos,
            ];
        });

        return array_values($list);
    }

    private function extractSubcategories(Crawler $crawler): array
    {
        $sub = [];
        try {
            $crawler->filter('select option[value], .category-filter option')->each(function (Crawler $node) use (&$sub) {
                $value = $node->attr('value');
                $text = trim($node->text());
                if ($value !== null && $value !== '' && $text !== '' && $text !== 'Все товары') {
                    $sub[] = ['value' => $value, 'title' => $text];
                }
            });
        } catch (\Throwable $e) {
            // ignore
        }
        return $sub;
    }

    private function hasNextPage(Crawler $crawler): bool
    {
        try {
            $found = false;
            $crawler->filter('a[href*="page="]')->each(function (Crawler $node) use (&$found) {
                $href = $node->attr('href') ?? '';
                $text = trim($node->text());
                if (preg_match('/page=(\d+)/', $href, $m) && (int) $m[1] > 1) {
                    $found = true;
                }
                if (stripos($text, 'след') !== false || stripos($text, 'next') !== false) {
                    $found = true;
                }
            });
            if ($found) {
                return true;
            }
            if ($crawler->filter('a[rel="next"]')->count() > 0) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }
}

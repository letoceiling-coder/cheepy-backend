<?php

namespace App\Services\SadovodParser\Parsers;

use App\Services\SadovodParser\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

class ProductParser
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Parse product page: photos, characteristics, description, seller link.
     *
     * @return array{id: string, title: string, price: string, photos: array, characteristics: array, description: string, category: array, seller: array}
     */
    public function parse(string $path): array
    {
        $crawler = $this->http->getCrawler($path);
        $baseUrl = $this->http->getBaseUrl();

        $id = '';
        if (preg_match('#/odejda/(\d+)#', $path, $m)) {
            $id = $m[1];
        }

        $title = $this->extractTitle($crawler);
        $price = $this->extractPrice($crawler);
        $photos = $this->extractPhotos($crawler, $baseUrl);
        $characteristics = $this->extractCharacteristics($crawler);
        $description = $this->extractDescription($crawler);
        $category = $this->extractCategory($crawler);
        $seller = $this->extractSellerBlock($crawler);

        return [
            'id' => $id,
            'title' => $title,
            'price' => $price,
            'photos' => $photos,
            'characteristics' => $characteristics,
            'description' => $description,
            'category' => $category,
            'seller' => $seller,
        ];
    }

    private function extractTitle(Crawler $crawler): string
    {
        try {
            $h1 = $crawler->filter('main h1, .product-title h1, h1')->first();
            return trim($h1->text());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractPrice(Crawler $crawler): string
    {
        try {
            $nodes = $crawler->filter('main')->reduce(function (Crawler $node) {
                $text = $node->text();
                return preg_match('/\d+\s*₽/u', $text);
            });
            if ($nodes->count() > 0) {
                $text = $nodes->first()->text();
                if (preg_match('/[\d\s]+₽/u', $text, $m)) {
                    return trim($m[0]);
                }
            }
            $crawler->filter('[class*="price"]')->each(function (Crawler $n) use (&$price) {
                $t = $n->text();
                if (preg_match('/[\d\s]+₽/u', $t, $m)) {
                    $price = trim($m[0]);
                }
            });
        } catch (\Throwable $e) {
        }
        $price = '';
        $crawler->filter('body')->each(function (Crawler $n) use (&$price) {
            $t = $n->text();
            if (preg_match('/Цена\s*[\d\s]+₽/u', $t, $m)) {
                $price = trim(preg_replace('/Цена\s*/u', '', $m[0]));
            }
        });
        return $price;
    }

    private function extractPhotos(Crawler $crawler, string $baseUrl): array
    {
        $photos = [];
        try {
            $crawler->filter('main img[src*="odejda"], .product-gallery img, .carousel img, [class*="gallery"] img')->each(function (Crawler $node) use (&$photos, $baseUrl) {
                $src = $node->attr('src') ?? $node->attr('data-src');
                if (!$src) {
                    return;
                }
                $url = str_starts_with($src, 'http') ? $src : $baseUrl . '/' . ltrim($src, '/');
                if (!in_array($url, $photos, true)) {
                    $photos[] = $url;
                }
            });
        } catch (\Throwable $e) {
        }
        if (empty($photos)) {
            $crawler->filter('main img')->each(function (Crawler $node) use (&$photos, $baseUrl) {
                $src = $node->attr('src') ?? $node->attr('data-src');
                if ($src && (str_contains($src, 'odejda') || str_contains($src, 'upload'))) {
                    $url = str_starts_with($src, 'http') ? $src : $baseUrl . '/' . ltrim($src, '/');
                    if (!in_array($url, $photos, true)) {
                        $photos[] = $url;
                    }
                }
            });
        }
        return $photos;
    }

    private function extractCharacteristics(Crawler $crawler): array
    {
        $chars = [];
        $text = $crawler->filter('main')->text();
        if (preg_match('/Цвет\s*([^\n]+)/u', $text, $m)) {
            $chars['color'] = trim($m[1]);
        }
        if (preg_match('/Размер[^\d]*([\d\s\-]+)/ui', $text, $m)) {
            $chars['size'] = trim($m[1]);
        }
        if (preg_match('/Категория[^\n]*/u', $text, $m)) {
            $chars['category_label'] = trim($m[0]);
        }
        $crawler->filter('main p, main [class*="description"]')->each(function (Crawler $node) use (&$chars) {
            $t = $node->text();
            if (str_contains($t, 'Размер') || str_contains($t, 'Цвет') || str_contains($t, 'Подкладка')) {
                foreach (explode("\n", $t) as $line) {
                    $line = trim($line);
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $chars[trim($k)] = trim($v);
                    }
                }
            }
        });
        return $chars;
    }

    private function extractDescription(Crawler $crawler): string
    {
        try {
            $block = $crawler->filter('main [class*="О товаре"], main .product-description, main p');
            $texts = [];
            $block->each(function (Crawler $node) use (&$texts) {
                $t = trim($node->text());
                if (strlen($t) > 20 && !str_contains($t, 'Свяжитесь')) {
                    $texts[] = $t;
                }
            });
            return implode("\n", array_slice($texts, 0, 5));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractCategory(Crawler $crawler): array
    {
        $cat = ['title' => '', 'url' => ''];
        try {
            $crawler->filter('main a[href*="/catalog/"]')->each(function (Crawler $node) use (&$cat) {
                $href = $node->attr('href');
                $text = trim($node->text());
                if ($href && $text && strlen($text) < 100) {
                    $cat['title'] = $text;
                    $cat['url'] = $href;
                }
            });
        } catch (\Throwable $e) {
        }
        return $cat;
    }

    /**
     * Extract seller block: name, url, phone, whatsapp from product page.
     */
    private function extractSellerBlock(Crawler $crawler): array
    {
        $seller = [
            'name' => '',
            'url' => '',
            'slug' => '',
            'phone' => '',
            'whatsapp' => '',
            'pavilion' => '',
        ];
        try {
            $crawler->filter('main a[href*="/s/"]')->each(function (Crawler $node) use (&$seller) {
                $href = $node->attr('href');
                if ($href && preg_match('#/s/([a-z0-9\-]+)#', $href, $m)) {
                    $seller['url'] = $href;
                    $seller['slug'] = $m[1];
                    $seller['name'] = trim($node->text());
                }
            });
            $mainText = $crawler->filter('main')->text();
            if (preg_match('/\+7\s*\([\d\s\)\-]+/u', $mainText, $m)) {
                $seller['phone'] = trim($m[0]);
            }
            $crawler->filter('main a[href*="wa.me"], main a[href*="whatsapp"]')->each(function (Crawler $node) use (&$seller) {
                $seller['whatsapp'] = $node->attr('href') ?? '';
            });
            if (preg_match('/Корпус\s+[^\n]+/u', $mainText, $m)) {
                $seller['pavilion'] = trim($m[0]);
            }
        } catch (\Throwable $e) {
        }
        return $seller;
    }
}

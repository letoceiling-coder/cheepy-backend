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
     * @return array{id: string, title: string, price: string, photos: array, characteristics: array, description: string, category: array, seller: array, similar_external_ids: list<string>}
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
        $similarExternalIds = $this->extractSimilarExternalIds($crawler, $id);

        return [
            'id' => $id,
            'title' => $title,
            'price' => $price,
            'photos' => $photos,
            'characteristics' => $characteristics,
            'description' => $description,
            'category' => $category,
            'seller' => $seller,
            'similar_external_ids' => $similarExternalIds,
        ];
    }

    /**
     * «Похожие» / другие цвета: ссылки из блока .similar_products → /odejda/{id}.
     *
     * @return list<string>
     */
    private function extractSimilarExternalIds(Crawler $crawler, string $currentId): array
    {
        $ids = [];
        try {
            $crawler->filter('.similar_products a[href*="/odejda/"]')->each(function (Crawler $a) use (&$ids) {
                $href = (string) ($a->attr('href') ?? '');
                if (preg_match('#/odejda/(\d+)#', $href, $m)) {
                    $ids[] = $m[1];
                }
            });
        } catch (\Throwable $e) {
        }

        $out = [];
        $seen = [];
        foreach ($ids as $ext) {
            if ($ext === $currentId) {
                continue;
            }
            if (isset($seen[$ext])) {
                continue;
            }
            $seen[$ext] = true;
            $out[] = $ext;
        }

        return $out;
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
        $baseUrl = rtrim($baseUrl, '/');

        // sadovodbaza.ru: основные кадры только в Swiper; «похожие цвета» (.similar_products) и «Товары магазина»
        // (.product-list / .shop-product) — другие товары, их нельзя смешивать с галереей карточки.
        $gallerySelectors = [
            '.product-view .mySwiper img',
            '.product-view .swiper-slide img',
            '.product-gallery img',
            'main .carousel img',
            'main [class*="swiper"] .swiper-slide img',
        ];

        foreach ($gallerySelectors as $selector) {
            try {
                $batch = $this->collectProductPhotoUrls($crawler->filter($selector), $baseUrl, false);
                if ($batch !== []) {
                    return $batch;
                }
            } catch (\Throwable $e) {
                // неверный селектор / пустой узел
            }
        }

        try {
            $batch = $this->collectProductPhotoUrls($crawler->filter('main img'), $baseUrl, true);
            if ($batch !== []) {
                return $batch;
            }
        } catch (\Throwable $e) {
        }

        return [];
    }

    /**
     * @param  bool  $fallbackRules  если true — только uploaded_files / img_big, без _img_mini (превью других цветов)
     */
    private function collectProductPhotoUrls(Crawler $images, string $baseUrl, bool $fallbackRules): array
    {
        $out = [];
        $images->each(function (Crawler $node) use (&$out, $baseUrl, $fallbackRules) {
            if ($this->isInsideExcludedPhotoContainer($node)) {
                return;
            }
            $src = $node->attr('src') ?? $node->attr('data-src');
            if (! $src || str_contains($src, 'data:image')) {
                return;
            }
            $lower = strtolower($src);
            if ($fallbackRules) {
                if (! str_contains($lower, 'uploaded_files') && ! str_contains($lower, 'img_big')) {
                    return;
                }
                if (str_contains($lower, '_img_mini')) {
                    return;
                }
            }
            $url = str_starts_with($src, 'http') ? $src : $baseUrl.'/'.ltrim($src, '/');
            if (! in_array($url, $out, true)) {
                $out[] = $url;
            }
        });

        return $out;
    }

    /**
     * Исключаем миниатюры других цветов, сетку «Товары магазина», аватар продавца и т.п.
     */
    private function isInsideExcludedPhotoContainer(Crawler $imgNode): bool
    {
        $n = $imgNode->getNode(0);
        $needles = [
            'similar_products',
            'similar_product',
            'product-list',
            'shop-product',
            'shop-product-item',
            'shop-product-img',
            'shop-avatar',
            'shop-contact',
        ];
        while ($n) {
            if ($n instanceof \DOMElement) {
                $class = $n->getAttribute('class');
                if ($class !== '') {
                    foreach ($needles as $needle) {
                        if (str_contains($class, $needle)) {
                            return true;
                        }
                    }
                }
            }
            $n = $n->parentNode;
        }

        return false;
    }

    private function extractCharacteristics(Crawler $crawler): array
    {
        $chars = [];

        try {
            $colorNode = $crawler->filter('.color-label a, [class*="color-label"] a, a[href*="?color="], a[href*="&color="]')->first();
            if ($colorNode->count() > 0) {
                $color = $this->cleanAttributeValue($colorNode->text());
                if ($color !== '') {
                    $chars['color'] = $color;
                    $chars['Цвет'] = $color;
                }
            }
        } catch (\Throwable $e) {
            // ignore selector parse errors
        }

        try {
            $text = $crawler->filter('main')->text();
        } catch (\Throwable $e) {
            $text = '';
        }

        if ($text !== '' && !isset($chars['color']) && preg_match('/Цвет\s*([^\n\r]+)/u', $text, $m)) {
            $color = $this->cleanAttributeValue($m[1]);
            if ($color !== '') {
                $chars['color'] = $color;
                $chars['Цвет'] = $color;
            }
        }
        if ($text !== '' && preg_match('/Размер(?:ный ряд)?[^\d]*([\d\s\-–]+)/ui', $text, $m)) {
            $size = $this->cleanAttributeValue($m[1]);
            if ($size !== '') {
                $chars['size'] = $size;
                $chars['Размер'] = $size;
            }
        }
        if ($text !== '' && preg_match('/Категория[^\n]*/u', $text, $m)) {
            $chars['category_label'] = trim($m[0]);
        }
        $crawler->filter('main p, main [class*="description"]')->each(function (Crawler $node) use (&$chars) {
            $t = $node->text();
            if (str_contains($t, 'Размер') || str_contains($t, 'Цвет') || str_contains($t, 'Подкладка')) {
                foreach (explode("\n", $t) as $line) {
                    $line = trim($line);
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $key = trim($k);
                        $value = $this->cleanAttributeValue($v);
                        if ($value !== '') {
                            $chars[$key] = $value;
                        }
                    }
                }
            }
        });
        return $chars;
    }

    private function cleanAttributeValue(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        // Stop at common trailing UI fragments accidentally captured from page text.
        $value = preg_replace('/\s*(Размер(?:ный ряд)?|Добавить в корзину|Категория)\b.*$/ui', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B,.;:-");

        return mb_substr($value, 0, 120);
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
     * Extract seller block from product page only.
     * Selectors: a[href^="/s/"], .pavilion2, .shop-avatar.
     * Do NOT extract description/phone/whatsapp from product page — those come from seller page only.
     */
    private function extractSellerBlock(Crawler $crawler): array
    {
        $seller = [
            'seller_name' => '',
            'seller_url' => '',
            'seller_slug' => '',
            'pavilion' => '',
            'avatar' => '',
        ];
        try {
            $linkNodes = $crawler->filter('a[href^="/s/"]');
            if ($linkNodes->count() > 0) {
                $link = $linkNodes->first();
                $href = $link->attr('href');
                if ($href) {
                    $path = parse_url($href, PHP_URL_PATH) ?: $href;
                    $seller['seller_slug'] = basename(rtrim($path, '/'));
                    $seller['seller_url'] = $path;
                    $seller['seller_name'] = trim($link->text());
                }
            }
            $pavNodes = $crawler->filter('.pavilion2');
            if ($pavNodes->count() > 0) {
                $seller['pavilion'] = trim($pavNodes->first()->text());
            }
            $avatarNodes = $crawler->filter('.shop-avatar img');
            if ($avatarNodes->count() > 0) {
                $src = $avatarNodes->first()->attr('src');
                if ($src) {
                    $seller['avatar'] = $src;
                }
            }
        } catch (\Throwable $e) {
        }
        return $seller;
    }
}

<?php
/**
 * Parser + Seller + Dashboard fixes.
 * Run on server: php scripts/parser-seller-fix.php
 */
$base = '/var/www/online-parser.siteaacess.store';

// 1. ProductParser - fix extractSellerBlock: add .pavilion2, avatar, full source_url
$pp = $base . '/app/Services/SadovodParser/Parsers/ProductParser.php';
$c = file_get_contents($pp);

// Replace extractSellerBlock to add pavilion2 and avatar
$oldExtract = "    private function extractSellerBlock(Crawler \$crawler): array
    {
        \$seller = [
            'name' => '',
            'url' => '',
            'slug' => '',
            'phone' => '',
            'whatsapp' => '',
            'pavilion' => '',
        ];
        try {
            \$crawler->filter('main a[href*=\"/s/\"]')->each(function (Crawler \$node) use (&\$seller) {
                \$href = \$node->attr('href');
                if (\$href && preg_match('#/s/([a-z0-9\-]+)#', \$href, \$m)) {
                    \$seller['url'] = \$href;
                    \$seller['slug'] = \$m[1];
                    \$seller['name'] = trim(\$node->text());
                }
            });
            \$mainText = \$crawler->filter('main')->text();
            if (preg_match('/\+7\s*\([\d\s\)\-]+/u', \$mainText, \$m)) {
                \$seller['phone'] = trim(\$m[0]);
            }
            \$crawler->filter('main a[href*=\"wa.me\"], main a[href*=\"whatsapp\"]')->each(function (Crawler \$node) use (&\$seller) {
                \$seller['whatsapp'] = \$node->attr('href') ?? '';
            });
            if (preg_match('/Корпус\s+[^\n]+/u', \$mainText, \$m)) {
                \$seller['pavilion'] = trim(\$m[0]);
            }
        } catch (\Throwable \$e) {
        }
        return \$seller;
    }";

$newExtract = "    private function extractSellerBlock(Crawler \$crawler): array
    {
        \$seller = [
            'name' => '',
            'url' => '',
            'slug' => '',
            'phone' => '',
            'whatsapp' => '',
            'pavilion' => '',
            'avatar' => '',
        ];
        try {
            \$baseUrl = rtrim(\$this->http->getBaseUrl(), '/');
            \$crawler->filter('main a[href*=\"/s/\"]')->each(function (Crawler \$node) use (&\$seller, \$baseUrl) {
                \$href = \$node->attr('href');
                if (\$href && preg_match('#/s/([a-z0-9\-]+)#', \$href, \$m)) {
                    \$seller['url'] = str_starts_with(\$href, 'http') ? \$href : \$baseUrl . '/' . ltrim(\$href, '/');
                    \$seller['slug'] = \$m[1];
                    \$seller['name'] = trim(\$node->text());
                }
            });
            \$crawler->filter('main img.shop-avatar, main img.shop-avatar-products')->each(function (Crawler \$node) use (&\$seller, \$baseUrl) {
                \$src = \$node->attr('src');
                if (\$src) {
                    \$seller['avatar'] = str_starts_with(\$src, 'http') ? \$src : \$baseUrl . '/' . ltrim(\$src, '/');
                }
            });
            \$mainText = \$crawler->filter('main')->text();
            if (preg_match('/\+7\s*\([\d\s\)\-]+/u', \$mainText, \$m)) {
                \$seller['phone'] = trim(\$m[0]);
            }
            \$crawler->filter('main a[href*=\"wa.me\"], main a[href*=\"whatsapp\"]')->each(function (Crawler \$node) use (&\$seller) {
                \$seller['whatsapp'] = \$node->attr('href') ?? '';
            });
            if (preg_match('/Корпус\s+[^\n]+/u', \$mainText, \$m)) {
                \$seller['pavilion'] = trim(\$m[0]);
            }
            if (\$seller['pavilion'] === '' && \$crawler->filter('main .pavilion2')->count() > 0) {
                \$seller['pavilion'] = trim(\$crawler->filter('main .pavilion2')->first()->text());
            }
        } catch (\Throwable \$e) {
        }
        return \$seller;
    }";

if (str_contains($c, "if (preg_match('/Корпус\s+[^\n]+/u'")) {
    $c = str_replace($oldExtract, $newExtract, $c);
    file_put_contents($pp, $c);
    echo "ProductParser: extractSellerBlock updated (pavilion2, avatar, full url)\n";
}

// 2. DatabaseParserService - upsertSeller: handle avatar, ensure source_url full
$dps = $base . '/app/Services/DatabaseParserService.php';
$c2 = file_get_contents($dps);
if (!str_contains($c2, "sellerData['avatar']")) {
    $c2 = str_replace(
        "        return Seller::updateOrCreate(\n            ['slug' => \$slug],\n            [\n                'name' => mb_substr(\$sellerData['name'], 0, 499),",
        "        \$baseUrl = rtrim(config('sadovod.base_url', 'https://sadovodbaza.ru'), '/');\n        \$sourceUrl = \$sellerData['url'] ?? \$baseUrl . '/s/' . \$slug;\n        if (\$sourceUrl && !str_starts_with(\$sourceUrl, 'http')) {\n            \$sourceUrl = \$baseUrl . '/' . ltrim(\$sourceUrl, '/');\n        }\n\n        return Seller::updateOrCreate(\n            ['slug' => \$slug],\n            [\n                'name' => mb_substr(\$sellerData['name'], 0, 499),",
        $c2
    );
    $c2 = str_replace("'source_url' => \$sellerData['url'] ?? null,", "'source_url' => \$sourceUrl ?? null,", $c2);
    file_put_contents($dps, $c2);
    echo "DatabaseParserService: upsertSeller source_url fix\n";
}

echo "Done.\n";

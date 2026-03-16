<?php
$path = '/var/www/online-parser.siteaacess.store/app/Services/SadovodParser/Parsers/ProductParser.php';
$c = file_get_contents($path);

// 1. Add pavilion2 fallback after the Корпус block
$c = str_replace(
    "            if (preg_match('/Корпус\s+[^\n]+/u', \$mainText, \$m)) {\n                \$seller['pavilion'] = trim(\$m[0]);\n            }\n        } catch",
    "            if (preg_match('/Корпус\s+[^\n]+/u', \$mainText, \$m)) {\n                \$seller['pavilion'] = trim(\$m[0]);\n            }\n            if (\$seller['pavilion'] === '' && \$crawler->filter('main .pavilion2')->count() > 0) {\n                \$seller['pavilion'] = trim(\$crawler->filter('main .pavilion2')->first()->text());\n            }\n        } catch",
    $c
);

// 2. Fix url to be full - add baseUrl in the a[href] filter
$c = str_replace(
    "\$crawler->filter('main a[href*=\"/s/\"]')->each(function (Crawler \$node) use (&\$seller) {\n                \$href = \$node->attr('href');\n                if (\$href && preg_match('#/s/([a-z0-9\-]+)#', \$href, \$m)) {\n                    \$seller['url'] = \$href;",
    "\$baseUrl = rtrim(\$this->http->getBaseUrl(), '/');\n            \$crawler->filter('main a[href*=\"/s/\"]')->each(function (Crawler \$node) use (&\$seller, \$baseUrl) {\n                \$href = \$node->attr('href');\n                if (\$href && preg_match('#/s/([a-z0-9\-]+)#', \$href, \$m)) {\n                    \$seller['url'] = str_starts_with(\$href, 'http') ? \$href : \$baseUrl . '/' . ltrim(\$href, '/');",
    $c
);

file_put_contents($path, $c);
echo "ProductParser patched: pavilion2, full url\n";

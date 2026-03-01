<?php
/**
 * Финальный тест с реальными данными из БД
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\Category;
use App\Models\Seller;
use App\Models\ParserJob;
use App\Models\ParserLog;

$BASE = 'http://127.0.0.1:8888';

function req(string $method, string $url, array $data = [], ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if (!empty($data)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true) ?? $body];
}

function ok(string $name, bool $pass, string $info = ''): void {
    echo ($pass ? '✅' : '❌') . " $name" . ($info ? " → $info" : '') . "\n";
}

echo "\n=========================================\n";
echo "   ФИНАЛЬНЫЙ ТЕСТ sadavod-laravel API\n";
echo "=========================================\n\n";

// Получить реальные ID из БД
$firstProduct = Product::orderBy('id')->first();
$productId = $firstProduct?->external_id ?? '13679582';
$firstCategory = Category::whereNotNull('external_slug')->where('enabled', true)->orderBy('products_count', 'desc')->first();
$catSlug = $firstCategory?->external_slug ?? 'platya';
$firstSeller = Seller::first();
$sellerSlug = $firstSeller?->slug ?? 'test-seller';

echo "Используем данные из БД:\n";
echo "  Product: $productId (title=" . ($firstProduct->title ?? 'n/a') . ")\n";
echo "  Category: $catSlug (count=" . ($firstCategory->products_count ?? 0) . ")\n";
echo "  Seller: $sellerSlug\n\n";

// Login
$r = req('POST', "$BASE/api/v1/auth/login", ['email' => 'admin@sadavod.loc', 'password' => 'admin123']);
$token = $r['body']['token'] ?? null;
ok('Auth login', $r['status'] === 200, "role=" . ($r['body']['user']['role'] ?? '?'));

// Dashboard
$r = req('GET', "$BASE/api/v1/dashboard", [], $token);
$dash = $r['body'];
ok('Dashboard', $r['status'] === 200,
    "products={$dash['products']['total']} categories={$dash['categories']['total']} sellers={$dash['sellers']['total']}");

// Products with filters
$r = req('GET', "$BASE/api/v1/products?per_page=3&sort_by=price_raw&sort_dir=asc&category_id={$firstCategory->id}", [], $token);
ok('Products filtered', $r['status'] === 200, "total=" . ($r['body']['meta']['total'] ?? 0));

// Single product
$r = req('GET', "$BASE/api/v1/products/$productId", [], $token);
ok("Product detail ($productId)", $r['status'] === 200, $r['body']['title'] ?? 'n/a');

// Categories tree
$r = req('GET', "$BASE/api/v1/categories?tree=true", [], $token);
ok('Categories tree', $r['status'] === 200, "root_count=" . count($r['body']['data'] ?? []));

// Category available filters
$r = req('GET', "$BASE/api/v1/categories/{$firstCategory->id}/filters", [], $token);
ok("Category {$firstCategory->id} filters", $r['status'] === 200, "attrs=" . count($r['body']['attributes'] ?? []));

// Seller detail
if ($firstSeller) {
    $r = req('GET', "$BASE/api/v1/sellers/$sellerSlug", [], $token);
    ok("Seller ($sellerSlug)", $r['status'] === 200, $r['body']['name'] ?? 'n/a');
}

// Public API
$r = req('GET', "$BASE/api/v1/public/menu");
ok('Public menu', $r['status'] === 200, "categories=" . count($r['body']['categories'] ?? []));

$r = req('GET', "$BASE/api/v1/public/categories/$catSlug/products?per_page=3");
ok("Public category products ($catSlug)", $r['status'] === 200,
    "total=" . ($r['body']['meta']['total'] ?? 0) . " filters=" . count($r['body']['filters'] ?? []));

$r = req('GET', "$BASE/api/v1/public/products/$productId");
ok("Public product ($productId)", $r['status'] === 200, $r['body']['product']['title'] ?? ($r['body']['error'] ?? 'n/a'));

$r = req('GET', "$BASE/api/v1/public/featured?limit=6");
ok('Public featured', $r['status'] === 200, "count=" . count($r['body']['data'] ?? []));

$r = req('GET', "$BASE/api/v1/public/search?" . http_build_query(['q' => 'панама']));
ok('Public search (панама)', $r['status'] === 200, "found=" . ($r['body']['meta']['total'] ?? 0));

// Settings
$r = req('GET', "$BASE/api/v1/settings?group=parser", [], $token);
ok('Settings parser group', $r['status'] === 200, "keys=" . count($r['body']['data']['parser'] ?? []));

// Logs
$r = req('GET', "$BASE/api/v1/logs?per_page=5", [], $token);
ok('Logs', $r['status'] === 200, "total=" . ($r['body']['meta']['total'] ?? 0));

// Parser status
$r = req('GET', "$BASE/api/v1/parser/status", [], $token);
ok('Parser status', $r['status'] === 200, "running=" . ($r['body']['is_running'] ? 'yes' : 'no'));

// Parser jobs history
$r = req('GET', "$BASE/api/v1/parser/jobs", [], $token);
ok('Parser jobs history', $r['status'] === 200, "total=" . ($r['body']['total'] ?? 0));

// Web catalog
$r = req('GET', "$BASE/?category=$catSlug");
ok("Web catalog /?category=$catSlug", $r['status'] === 200, "HTML len=" . strlen(is_string($r['body']) ? $r['body'] : ''));

$r = req('GET', "$BASE/product/$productId");
ok("Web product /product/$productId", $r['status'] === 200);

// Direct DB stats
echo "\n--- БД напрямую ---\n";
echo "✅ Products: " . Product::count() . " (active: " . Product::where('status','active')->count() . ")\n";
echo "✅ Categories: " . Category::count() . " (root: " . Category::whereNull('parent_id')->count() . ")\n";
echo "✅ Sellers: " . Seller::count() . "\n";
echo "✅ Parser jobs: " . ParserJob::count() . "\n";
echo "✅ Parser logs: " . ParserLog::count() . "\n";

$topCats = Category::where('products_count', '>', 0)->orderByDesc('products_count')->take(5)->get();
if ($topCats->isNotEmpty()) {
    echo "\nТоп категорий по товарам:\n";
    foreach ($topCats as $c) {
        echo "  {$c->name}: {$c->products_count}\n";
    }
}

echo "\n=========================================\n";
echo "           ТЕСТ ЗАВЕРШЁН\n";
echo "=========================================\n";

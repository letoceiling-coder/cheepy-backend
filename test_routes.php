<?php
/**
 * Тест всех роутов API через HTTP
 */

$BASE = 'http://127.0.0.1:8888';
$token = null;
$results = [];

function req(string $method, string $url, array $data = [], ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body'   => json_decode($body, true) ?? $body,
        'error'  => $err,
    ];
}

function test(string $name, int $expected, int $actual, mixed $body = null): void {
    $ok = $actual === $expected || ($expected === 200 && in_array($actual, [200, 201]));
    $icon = $ok ? '✅' : '❌';
    echo "$icon [$actual] $name";
    if (!$ok) {
        echo " (expected $expected)";
        if (is_array($body) && isset($body['error'])) {
            echo " → " . $body['error'];
        }
    }
    echo "\n";
}

echo "\n========================================\n";
echo "    ТЕСТ РОУТОВ sadavod-laravel API\n";
echo "========================================\n\n";

// ---- WEB ----
echo "--- WEB CATALOG ---\n";
$r = req('GET', "$BASE/");
test('GET / (каталог)', 200, $r['status']);

$r = req('GET', "$BASE/?category=platya");
test('GET /?category=platya', 200, $r['status']);

$r = req('GET', "$BASE/product/13588999");
test('GET /product/13588999', 200, $r['status']);

$r = req('GET', "$BASE/up");
test('GET /up (health)', 200, $r['status']);

// ---- PUBLIC API ----
echo "\n--- PUBLIC API (без авторизации) ---\n";

$r = req('GET', "$BASE/api/v1/public/menu");
test('GET /public/menu', 200, $r['status']);
$catCount = count($r['body']['categories'] ?? []);
echo "   ↳ категорий в БД: $catCount\n";

$r = req('GET', "$BASE/api/v1/public/categories/platya/products");
test('GET /public/categories/platya/products', 200, $r['status']);

$r = req('GET', "$BASE/api/v1/public/products/13588999");
test('GET /public/products/13588999', 200, $r['status']);

$r = req('GET', "$BASE/api/v1/public/search?q=платье");
test('GET /public/search?q=платье', 200, $r['status']);

$r = req('GET', "$BASE/api/v1/public/featured");
test('GET /public/featured', 200, $r['status']);

$r = req('GET', "$BASE/api/v1/public/sellers/savori-luxury-korpus-b-2a-10-tk-sadovod");
test('GET /public/sellers/{slug}', 200, $r['status']);

// ---- AUTH ----
echo "\n--- AUTH ---\n";

$r = req('POST', "$BASE/api/v1/auth/login", ['email' => 'admin@sadavod.loc', 'password' => 'admin123']);
test('POST /auth/login', 200, $r['status']);
$token = $r['body']['token'] ?? null;
if ($token) {
    echo "   ↳ JWT token получен ✓ role=" . ($r['body']['user']['role'] ?? '?') . "\n";
}

$r = req('POST', "$BASE/api/v1/auth/login", ['email' => 'bad@bad.com', 'password' => 'wrong']);
test('POST /auth/login (wrong creds = 401)', 401, $r['status']);

$r = req('GET', "$BASE/api/v1/auth/me", [], $token);
test('GET /auth/me', 200, $r['status']);

// ---- ADMIN (protected) ----
echo "\n--- ADMIN API (с JWT) ---\n";

$r = req('GET', "$BASE/api/v1/dashboard", [], $token);
test('GET /dashboard', 200, $r['status']);
if (isset($r['body']['products'])) {
    echo "   ↳ products.total=" . $r['body']['products']['total'] . " categories.total=" . $r['body']['categories']['total'] . "\n";
}

// Auth guard test
$r = req('GET', "$BASE/api/v1/dashboard");
test('GET /dashboard (без токена = 401)', 401, $r['status']);

// Parser
$r = req('GET', "$BASE/api/v1/parser/status", [], $token);
test('GET /parser/status', 200, $r['status']);
echo "   ↳ is_running=" . ($r['body']['is_running'] ? 'true' : 'false') . "\n";

$r = req('GET', "$BASE/api/v1/parser/jobs", [], $token);
test('GET /parser/jobs', 200, $r['status']);

// Products
$r = req('GET', "$BASE/api/v1/products", [], $token);
test('GET /products', 200, $r['status']);
echo "   ↳ total=" . ($r['body']['meta']['total'] ?? 0) . "\n";

$r = req('GET', "$BASE/api/v1/products?status=active&sort_by=parsed_at&per_page=5", [], $token);
test('GET /products (filters)', 200, $r['status']);

// Categories
$r = req('GET', "$BASE/api/v1/categories?tree=true", [], $token);
test('GET /categories?tree=true', 200, $r['status']);
echo "   ↳ categories=" . count($r['body']['data'] ?? []) . "\n";

$r = req('GET', "$BASE/api/v1/categories", [], $token);
test('GET /categories', 200, $r['status']);

// Sellers
$r = req('GET', "$BASE/api/v1/sellers", [], $token);
test('GET /sellers', 200, $r['status']);
echo "   ↳ sellers total=" . ($r['body']['meta']['total'] ?? 0) . "\n";

// Brands
$r = req('GET', "$BASE/api/v1/brands", [], $token);
test('GET /brands', 200, $r['status']);

// Brand create
$r = req('POST', "$BASE/api/v1/brands", ['name' => 'TestBrand', 'slug' => 'test-brand'], $token);
test('POST /brands (create)', 201, $r['status']);
$brandId = $r['body']['id'] ?? null;

if ($brandId) {
    $r = req('PUT', "$BASE/api/v1/brands/$brandId", ['name' => 'TestBrand Updated', 'status' => 'inactive'], $token);
    test("PUT /brands/$brandId", 200, $r['status']);

    $r = req('DELETE', "$BASE/api/v1/brands/$brandId", [], $token);
    test("DELETE /brands/$brandId", 200, $r['status']);
}

// Excluded
$r = req('GET', "$BASE/api/v1/excluded", [], $token);
test('GET /excluded', 200, $r['status']);

$r = req('POST', "$BASE/api/v1/excluded", [
    'pattern' => 'тест_исключение',
    'type' => 'word',
    'action' => 'hide',
    'scope' => 'global',
], $token);
test('POST /excluded', 201, $r['status']);
$ruleId = $r['body']['id'] ?? null;

$r = req('POST', "$BASE/api/v1/excluded/test", [
    'text' => 'Тут есть тест_исключение в тексте',
    'field' => 'title',
], $token);
test('POST /excluded/test', 200, $r['status']);
if (isset($r['body']['hide'])) {
    echo "   ↳ hide=" . ($r['body']['hide'] ? 'true' : 'false') . " result='" . ($r['body']['result'] ?? '') . "'\n";
}

if ($ruleId) {
    $r = req('DELETE', "$BASE/api/v1/excluded/$ruleId", [], $token);
    test("DELETE /excluded/$ruleId", 200, $r['status']);
}

// Filters
$r = req('GET', "$BASE/api/v1/filters", [], $token);
test('GET /filters', 200, $r['status']);

// Logs
$r = req('GET', "$BASE/api/v1/logs", [], $token);
test('GET /logs', 200, $r['status']);

$r = req('GET', "$BASE/api/v1/logs?level=error", [], $token);
test('GET /logs?level=error', 200, $r['status']);

// Settings
$r = req('GET', "$BASE/api/v1/settings", [], $token);
test('GET /settings', 200, $r['status']);
if (isset($r['body']['data']['parser'])) {
    echo "   ↳ groups: " . implode(', ', array_keys($r['body']['data'])) . "\n";
}

$r = req('GET', "$BASE/api/v1/settings?group=parser", [], $token);
test('GET /settings?group=parser', 200, $r['status']);

$r = req('PUT', "$BASE/api/v1/settings", ['settings' => ['site_name' => 'Садовод Тест']], $token);
test('PUT /settings (update)', 200, $r['status']);

// ---- PARSER LAUNCH ----
echo "\n--- ЗАПУСК ПАРСЕРА ---\n";

// Запуск menu_only (быстрый)
$r = req('POST', "$BASE/api/v1/parser/start", [
    'type' => 'menu_only',
    'save_to_db' => true,
], $token);
test('POST /parser/start (menu_only)', 201, $r['status']);
$jobId = $r['body']['job_id'] ?? null;
echo "   ↳ job_id=$jobId status=" . ($r['body']['job']['status'] ?? '?') . "\n";

if ($jobId) {
    // Ждём завершения
    $attempts = 0;
    do {
        sleep(2);
        $r2 = req('GET', "$BASE/api/v1/parser/jobs/$jobId", [], $token);
        $status = $r2['body']['status'] ?? 'unknown';
        echo "   ↳ polling: status=$status saved=" . ($r2['body']['progress']['saved'] ?? 0) . "\n";
        $attempts++;
    } while (!in_array($status, ['completed', 'failed', 'cancelled']) && $attempts < 20);

    test("GET /parser/jobs/$jobId (завершён)", 200, $r2['status']);
    echo "   ↳ FINAL: status=$status\n";

    // Проверяем что категории появились в БД
    $r3 = req('GET', "$BASE/api/v1/categories?per_page=5", [], $token);
    $catTotal = $r3['body']['total'] ?? 0;
    echo "   ↳ Категорий в БД после парсинга: $catTotal\n";

    // Проверяем /public/menu
    $r4 = req('GET', "$BASE/api/v1/public/menu");
    $publicCats = count($r4['body']['categories'] ?? []);
    echo "   ↳ Категорий в /public/menu: $publicCats\n";
}

// Попытка запустить второй парсер пока первый ещё не завершён (должно вернуть 409 или нет)
// Сначала проверим статус
$rStatus = req('GET', "$BASE/api/v1/parser/status", [], $token);
$isRunning = $r['body']['is_running'] ?? false;
if (!$isRunning) {
    // Запустим одиночную категорию
    $r = req('POST', "$BASE/api/v1/parser/start", [
        'type' => 'category',
        'category_slug' => 'platya',
        'products_per_category' => 3,
        'max_pages' => 1,
        'no_details' => true,
        'save_photos' => false,
        'save_to_db' => true,
    ], $token);
    test('POST /parser/start (category=platya, 3 товара)', 201, $r['status']);
    $jobId2 = $r['body']['job_id'] ?? null;

    if ($jobId2) {
        sleep(2);
        $rJ = req('GET', "$BASE/api/v1/parser/jobs/$jobId2", [], $token);
        echo "   ↳ category job status=" . ($rJ['body']['status'] ?? '?') . "\n";

        $attempts = 0;
        do {
            sleep(3);
            $rJ = req('GET', "$BASE/api/v1/parser/jobs/$jobId2", [], $token);
            $jStatus = $rJ['body']['status'] ?? 'unknown';
            echo "   ↳ category parsing: status=$jStatus saved=" . ($rJ['body']['progress']['saved'] ?? 0) . "\n";
            $attempts++;
        } while (!in_array($jStatus, ['completed', 'failed', 'cancelled']) && $attempts < 15);

        // Проверяем продукты в БД
        $rP = req('GET', "$BASE/api/v1/products?per_page=5", [], $token);
        echo "   ↳ Товаров в БД: " . ($rP['body']['meta']['total'] ?? 0) . "\n";
        if (!empty($rP['body']['data'])) {
            $first = $rP['body']['data'][0];
            echo "   ↳ Первый товар: " . ($first['title'] ?? '?') . " | " . ($first['price'] ?? '?') . "\n";
        }
    }
}

// Stop test
$r = req('POST', "$BASE/api/v1/parser/stop", [], $token);
echo "POST /parser/stop → " . ($r['body']['message'] ?? $r['status']) . "\n";

echo "\n========================================\n";
echo "         ТЕСТ ЗАВЕРШЁН\n";
echo "========================================\n";

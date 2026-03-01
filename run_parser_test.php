<?php
/**
 * Тест парсинга одной небольшой категории с деталями товаров
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ParserJob;
use App\Models\Product;
use App\Models\Seller;
use App\Models\ProductAttribute;
use App\Services\DatabaseParserService;

echo "=== ТЕСТ ПАРСИНГА с детальным получением товаров ===\n";
echo "Категория: panamy (Панамы) | 1 страница | 5 товаров | с деталями | без фото\n\n";

$job = ParserJob::create([
    'type' => 'category',
    'options' => [
        'category_slug' => 'panamy',
        'max_pages' => 1,
        'products_per_category' => 5,
        'no_details' => false,  // с деталями (имя продавца, описание, характеристики)
        'save_photos' => false,
        'save_to_db' => true,
    ],
    'status' => 'pending',
]);
echo "Job #{$job->id} создан\n";

$service = new DatabaseParserService($job);
$service->run();

$job->refresh();
echo "\n--- Результат Job ---\n";
echo "Status: {$job->status}\n";
echo "Saved: {$job->saved_products}\n";
echo "Errors: {$job->errors_count}\n";
if ($job->error_message) echo "Error: {$job->error_message}\n";

// Последние логи
echo "\nПоследние логи:\n";
$job->logs()->latest('logged_at')->take(10)->get()->each(fn($l) => print("  [{$l->level}] {$l->message}\n"));

// Проверяем товары с деталями
echo "\nТовары (с деталями):\n";
Product::orderBy('id', 'desc')->take(5)->with(['seller', 'category', 'attributes'])->get()->each(function($p) {
    echo "\n  [{$p->external_id}] {$p->title}\n";
    echo "    Price: {$p->price} | Color: " . ($p->color ?? 'n/a') . "\n";
    echo "    Category: " . ($p->category->name ?? 'n/a') . "\n";
    echo "    Photos: " . count($p->photos ?? []) . "\n";
    if ($p->seller) {
        echo "    Seller: {$p->seller->name} | {$p->seller->pavilion} | {$p->seller->phone}\n";
    }
    if ($p->attributes->count() > 0) {
        echo "    Attributes: " . $p->attributes->map(fn($a) => $a->attr_name . '=' . $a->attr_value)->join(', ') . "\n";
    }
    if ($p->source_link) {
        echo "    Source: {$p->source_link}\n";
    }
});

echo "\nИтого:\n";
echo "Products: " . Product::count() . "\n";
echo "Sellers: " . Seller::count() . "\n";
echo "Attributes: " . ProductAttribute::count() . "\n";

echo "\n=== ГОТОВО ===\n";

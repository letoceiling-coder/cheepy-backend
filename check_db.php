<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\Category;
use App\Models\Seller;
use App\Models\ParserJob;

echo "=== DB STATUS ===\n";
echo "Categories: " . Category::count() . "\n";
echo "Products: " . Product::count() . "\n";
echo "Active products: " . Product::where('status','active')->count() . "\n";
echo "Sellers: " . Seller::count() . "\n";

$jobs = ParserJob::orderBy('id','desc')->take(3)->get();
echo "\nLast jobs:\n";
foreach($jobs as $j) {
    echo "  #{$j->id} type={$j->type} status={$j->status} saved={$j->saved_products} errors={$j->errors_count}\n";
}

if (Product::count() > 0) {
    echo "\nSample products:\n";
    Product::orderBy('id','desc')->take(5)->get()->each(function($p) {
        echo "  [{$p->external_id}] {$p->title} | {$p->price}\n";
    });
}

<?php
/**
 * Parser live diagnostics. Run from project root: php scripts/parser-diagnostics.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$products = \DB::table('products')->count();
$job = \DB::table('parser_jobs')->orderBy('id', 'desc')->first();

echo "products=" . $products . PHP_EOL;
if ($job) {
    echo "job_id=" . $job->id . " status=" . $job->status
        . " parsed_products=" . ($job->parsed_products ?? 0)
        . " parsed_categories=" . ($job->parsed_categories ?? 0) . PHP_EOL;
} else {
    echo "job_id=none" . PHP_EOL;
}

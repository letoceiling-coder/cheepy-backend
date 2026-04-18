<?php
/**
 * Полная очистка донорского слоя парсера (см. DonorProductsWipeService).
 * Запуск из корня проекта: php scripts/truncate-parser-tables.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app->make(\App\Services\Parser\DonorProductsWipeService::class)->wipe(true);

echo 'OK products='.\DB::table('products')->count().' parser_jobs='.(\Illuminate\Support\Facades\Schema::hasTable('parser_jobs') ? \DB::table('parser_jobs')->count() : 'n/a').PHP_EOL;

<?php
/**
 * One-off script to truncate parser-related tables. Run from project root:
 * php scripts/truncate-parser-tables.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\DB::statement('SET FOREIGN_KEY_CHECKS=0');
\DB::table('parser_logs')->truncate();
\DB::table('parser_jobs')->truncate();
\DB::table('product_attributes')->truncate();
\DB::table('product_photos')->truncate();
\DB::table('products')->truncate();
\DB::statement('ALTER TABLE products AUTO_INCREMENT = 1');
\DB::statement('ALTER TABLE product_photos AUTO_INCREMENT = 1');
\DB::statement('ALTER TABLE product_attributes AUTO_INCREMENT = 1');
\DB::statement('ALTER TABLE parser_jobs AUTO_INCREMENT = 1');
\DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo "OK products=" . \DB::table('products')->count() . " parser_jobs=" . \DB::table('parser_jobs')->count();

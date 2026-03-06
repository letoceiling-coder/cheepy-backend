<?php
/**
 * Start parser for a single category. Usage: php scripts/start-parser-category.php platya
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ParserJob;
use App\Jobs\RunParserJob;

$slug = $argv[1] ?? 'platya';
$job = ParserJob::create([
    'type' => 'category',
    'options' => ['category_slug' => $slug],
    'status' => 'pending',
]);

RunParserJob::dispatch($job->id);
echo "Parser category job created: id={$job->id} slug={$slug}\n";

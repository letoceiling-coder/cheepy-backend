<?php
/**
 * Create a parser job and dispatch RunParserJob. Run from project root:
 * php scripts/start-parser-job.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ParserJob;
use App\Jobs\RunParserJob;

$job = ParserJob::create([
    'type' => 'full',
    'options' => [],
    'status' => 'pending',
]);

RunParserJob::dispatch($job->id);
echo "Parser job created and dispatched: id=" . $job->id . PHP_EOL;

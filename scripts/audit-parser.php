<?php
/**
 * Parser audit - run from Laravel root: php scripts/audit-parser.php
 */
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require_once $base . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = function($s) { echo $s . PHP_EOL; };

$out("=== PARSER AUDIT REPORT ===");
$out("Date: " . date('Y-m-d H:i:s T'));

$j = \App\Models\ParserJob::latest('id')->first();
if ($j) {
    $out("");
    $out("--- Latest ParserJob ---");
    $out("ID: {$j->id} | status: {$j->status} | type: {$j->type}");
    $out("saved_products: {$j->saved_products} | errors_count: {$j->errors_count}");
    $out("parsed_products: {$j->parsed_products} | total_categories: {$j->total_categories} | parsed_categories: {$j->parsed_categories}");
    $out("started_at: {$j->started_at} | finished_at: " . ($j->finished_at ?? 'null'));
}
$running = \App\Models\ParserJob::whereIn('status', ['running','pending'])->count();
$stopped = \App\Models\ParserJob::where('status', 'stopped')->count();
$out("Jobs: running/pending={$running} | stopped={$stopped}");

$productsTotal = \App\Models\Product::count();
$productsToday = \App\Models\Product::whereDate('parsed_at', today())->count();
$productsLastHour = \App\Models\Product::where('parsed_at', '>=', now()->subHour())->count();
$out("");
$out("--- Products ---");
$out("Total: {$productsTotal} | Today: {$productsToday} | Last hour: {$productsLastHour}");

$failedCount = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
$out("");
$out("--- Failed Jobs ---");
$out("Count: {$failedCount}");
if ($failedCount > 0) {
    $last = \Illuminate\Support\Facades\DB::table('failed_jobs')->orderByDesc('failed_at')->first();
    $out("Last failed_at: " . ($last->failed_at ?? 'n/a'));
    $dec = json_decode($last->payload ?? '{}');
    $out("Last job: " . ($dec->displayName ?? 'unknown'));
    $out("Exception: " . substr($last->exception ?? '', 0, 500));
}

$out("");
$out("--- Recent ParserLog errors (last 8) ---");
$errs = \App\Models\ParserLog::where('level','error')->orderByDesc('logged_at')->take(8)->get();
foreach ($errs as $e) {
    $out($e->logged_at . " | " . mb_substr($e->message, 0, 180));
}

$out("");
$out("--- Queues ---");
try {
    $conn = \Illuminate\Support\Facades\Queue::connection(config('queue.default'));
    $p = $conn->size('parser');
    $ph = $conn->size('photos');
    $d = $conn->size('default');
    $out("parser: {$p} | photos: {$ph} | default: {$d} | total: " . ($p+$ph+$d));
} catch (\Throwable $e) {
    $out("Error: " . $e->getMessage());
}

$out("");
$out("--- Workers ---");
$proc = trim((string) @shell_exec('ps aux 2>/dev/null | grep -E "artisan queue:work" | grep -v grep | wc -l'));
$out("queue:work processes: {$proc}");

$out("");
$out("--- ParseProductJob.php ---");
$path = $base . '/app/Jobs/ParseProductJob.php';
$c = file_get_contents($path);
$out("has isCancelled: " . (strpos($c, 'isCancelled') !== false ? 'YES' : 'NO'));
$out("has new comment (no cancel): " . (strpos($c, 'Не прерываем') !== false ? 'YES' : 'NO'));

$out("");
$out("--- getOrCreateSellerForProduct ---");
$ds = file_get_contents($base . '/app/Services/DatabaseParserService.php');
$pos = strpos($ds, 'function getOrCreateSellerForProduct');
if ($pos !== false) {
    $snip = substr($ds, $pos, 2500);
    $out("returns false explicitly: " . (strpos($snip, 'return false') !== false ? 'YES' : 'NO'));
    $out("returns null: " . (strpos($snip, 'return null') !== false ? 'YES' : 'NO'));
}

$out("");
$out("--- AppServiceProvider ---");
$ap = file_get_contents($base . '/app/Providers/AppServiceProvider.php');
$out("use ReleaseParserLockOnFinished: " . (strpos($ap, 'use App\\Listeners\\ReleaseParserLockOnFinished') !== false ? 'YES' : 'NO'));
$out("References ReleaseParserLockOnFinished: " . (strpos($ap, 'ReleaseParserLockOnFinished') !== false ? 'YES' : 'NO'));

$out("");
$out("--- Failed jobs by displayName ---");
$byJob = \Illuminate\Support\Facades\DB::table('failed_jobs')
    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) as name, COUNT(*) as cnt")
    ->groupBy('name')
    ->get();
foreach ($byJob as $r) {
    $out("  " . ($r->name ?? 'null') . ": {$r->cnt}");
}

$errToday = \App\Models\ParserLog::where('level','error')->whereDate('logged_at', today())->count();
$errLastHour = \App\Models\ParserLog::where('level','error')->where('logged_at', '>=', now()->subHour())->count();
$out("");
$out("--- ParserLog errors ---");
$out("Today: {$errToday} | Last hour: {$errLastHour}");

$out("");
$out("--- Recent ParserLog (last 15, any level) ---");
$recents = \App\Models\ParserLog::orderByDesc('logged_at')->take(15)->get(['level','message','logged_at']);
foreach ($recents as $r) {
    $out($r->logged_at . " [{$r->level}] " . mb_substr($r->message, 0, 100));
}

$out("");
$out("--- Products: parsed_at last 5/10/60 min ---");
$p5 = \App\Models\Product::where('parsed_at', '>=', now()->subMinutes(5))->count();
$p10 = \App\Models\Product::where('parsed_at', '>=', now()->subMinutes(10))->count();
$p60 = \App\Models\Product::where('parsed_at', '>=', now()->subMinutes(60))->count();
$out("Last 5 min: {$p5} | 10 min: {$p10} | 60 min: {$p60}");

$out("");
$out("--- Latest ParserJob saved_products trend ---");
$jobs = \App\Models\ParserJob::orderByDesc('id')->take(3)->get(['id','status','saved_products','updated_at']);
foreach ($jobs as $jj) {
    $out("Job #{$jj->id} status={$jj->status} saved={$jj->saved_products} updated={$jj->updated_at}");
}

$out("");
$out("=== END AUDIT ===");

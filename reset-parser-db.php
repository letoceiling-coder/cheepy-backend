<?php
// Run on server: php reset-parser-db.php
$path = '/var/www/online-parser.siteaacess.store/bootstrap/app.php';
if (!file_exists($path)) {
    echo "Wrong path\n";
    exit(1);
}
$app = require $path;
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Step 2: Stop parser jobs
$n = DB::table('parser_jobs')->whereIn('status', ['running', 'pending'])->update(['status' => 'stopped']);
echo "Updated $n parser_jobs to stopped\n";

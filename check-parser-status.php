<?php
chdir('/var/www/online-parser.siteaacess.store');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$running = Illuminate\Support\Facades\DB::table('parser_jobs')->whereIn('status', ['running', 'pending'])->exists();
echo 'parser_running: ' . ($running ? 'true' : 'false') . "\n";

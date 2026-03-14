<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use App\Models\ParserState;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class ParserDiagnostics extends Command
{
    protected $signature = 'parser:diagnostics';
    protected $description = 'Full parser system diagnostics: queues, workers, jobs, metrics';

    public function handle(): int
    {
        $conn = config('queue.default');

        $parserSize = 0;
        $defaultSize = 0;
        $photosSize = 0;
        try {
            $q = Queue::connection($conn);
            $parserSize = $q->size('parser');
            $defaultSize = $q->size('default');
            $photosSize = $q->size('photos');
        } catch (\Throwable $e) {
            $this->warn('Queue: ' . $e->getMessage());
        }

        $failedCount = 0;
        try {
            $failedCount = DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            // ignore
        }

        $running = ParserJob::whereIn('status', ['running', 'pending'])->latest()->first();
        $daemonEnabled = ParserState::current()->status === ParserState::STATUS_RUNNING;
        $productsTotal = Product::count();
        $productsToday = Product::whereDate('parsed_at', today())->count();
        $lockHeld = false;
        try {
            $lockHeld = (bool) Redis::get('parser_lock');
        } catch (\Throwable $e) {
            // ignore
        }

        $workers = 0;
        if (function_exists('shell_exec')) {
            $supervisor = (string) (@shell_exec('supervisorctl status 2>/dev/null') ?? '');
            if ($supervisor !== '') {
                foreach (preg_split('/\R/', $supervisor) as $line) {
                    if (str_contains($line, 'parser-worker') && str_contains($line, 'RUNNING')) {
                        $workers++;
                    }
                }
            } else {
                $out = [];
                exec('ps aux 2>/dev/null | grep -E "queue:work" | grep -v grep | wc -l 2>/dev/null', $out);
                $workers = (int) trim($out[0] ?? '0');
            }
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Queue parser', $parserSize],
                ['Queue default', $defaultSize],
                ['Queue photos', $photosSize],
                ['Failed jobs', $failedCount],
                ['Parser running', $running ? "yes (job #{$running->id})" : 'no'],
                ['Daemon enabled', $daemonEnabled ? 'yes' : 'no'],
                ['Lock held', $lockHeld ? 'yes' : 'no'],
                ['Products total', $productsTotal],
                ['Products today', $productsToday],
                ['Workers', $workers],
            ]
        );

        if ($running) {
            $this->line("Current: {$running->current_action}");
            $this->line("Progress: {$running->parsed_categories}/{$running->total_categories} categories, {$running->saved_products} saved");
        }

        return 0;
    }
}

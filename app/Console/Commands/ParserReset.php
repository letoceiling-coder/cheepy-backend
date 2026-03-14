<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use App\Models\ParserState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

class ParserReset extends Command
{
    protected $signature = 'parser:reset
                            {--force : Skip confirmation}
                            {--queues-only : Only clear queues and restart workers (do not stop jobs)}';
    protected $description = 'Emergency reset: stop parser, release lock, clear all queues, restart workers';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('Reset parser system? This will stop jobs, clear queues, and restart workers.')) {
            $this->info('Aborted.');
            return 0;
        }

        if (!$this->option('queues-only')) {
            $updated = ParserJob::whereIn('status', ['running', 'pending'])
                ->update(['status' => 'stopped', 'finished_at' => now()]);
            $this->info("Stopped {$updated} parser job(s).");

            ParserState::current()->update([
                'status' => ParserState::STATUS_STOPPED,
                'last_stop' => now(),
            ]);
            $this->info('parser_state=stopped.');
        }

        try {
            Redis::del('parser_lock');
            Redis::del('parser_running');
            $this->info('Lock released.');
        } catch (\Throwable $e) {
            $this->warn('Redis: ' . $e->getMessage());
        }

        $conn = config('queue.default');
        foreach (['parser', 'photos', 'default'] as $queue) {
            try {
                Artisan::call('queue:clear', [
                    'connection' => $conn,
                    '--queue' => $queue,
                    '--force' => true,
                ]);
                $this->info("Queue {$queue} cleared.");
            } catch (\Throwable $e) {
                $this->warn("Queue {$queue}: " . $e->getMessage());
            }
        }

        Artisan::call('queue:restart');
        $this->info('Workers restart signal sent.');

        return 0;
    }
}

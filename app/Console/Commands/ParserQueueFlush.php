<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

class ParserQueueFlush extends Command
{
    protected $signature = 'parser:queue-flush
                            {--connection= : Redis connection (default)}
                            {--db=0 : Redis database number}
                            {--dry-run : Show what would be done without executing}';
    protected $description = 'WARNING: Flush Redis DB (clears queues, cache, etc). Use with caution.';

    public function handle(): int
    {
        if (!$this->option('dry-run')) {
            if (!$this->confirm('This will FLUSH the entire Redis database. All queues and cached data will be lost. Continue?')) {
                $this->info('Aborted.');
                return 0;
            }
        }

        $conn = $this->option('connection') ?: 'default';

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] Would execute: Redis::connection(' . $conn . ')->flushdb()');
            return 0;
        }

        try {
            Redis::connection($conn)->flushdb();
            $this->info('Redis flushed.');
        } catch (\Throwable $e) {
            $this->error('Flush failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}

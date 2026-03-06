<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class ParserQueueStatus extends Command
{
    protected $signature = 'parser:queue-status';
    protected $description = 'Display parser queue sizes and failed jobs count';

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
            $this->warn('Queue connection failed: ' . $e->getMessage());
        }

        $failedCount = 0;
        try {
            $failedCount = DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            $this->warn('Failed jobs table not available');
        }

        $this->line('Parser queue: ' . $parserSize);
        $this->line('Default queue: ' . $defaultSize);
        $this->line('Photos queue: ' . $photosSize);
        $this->line('Failed jobs: ' . $failedCount);

        return 0;
    }
}

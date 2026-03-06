<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ParserQueueClear extends Command
{
    protected $signature = 'parser:queue-clear {--queue=parser : Queue to clear}';
    protected $description = 'Clear jobs from parser queue';

    public function handle(): int
    {
        $queue = $this->option('queue') ?: 'parser';
        $connection = config('queue.default');

        $this->info("Clearing queue: {$queue}");

        Artisan::call('queue:clear', [
            'connection' => $connection,
            '--queue' => $queue,
        ]);

        $this->info(Artisan::output());
        return 0;
    }
}

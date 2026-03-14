<?php

namespace App\Console\Commands;

use App\Jobs\ParserDaemonJob;
use App\Models\ParserState;
use Illuminate\Console\Command;

class ParserDaemonStart extends Command
{
    protected $signature = 'parser:daemon-start';
    protected $description = 'Start continuous parser daemon (sets parser_state=RUNNING, dispatches first run)';

    public function handle(): int
    {
        ParserState::current()->update([
            'status' => ParserState::STATUS_RUNNING,
            'last_start' => now(),
        ]);

        ParserDaemonJob::dispatch();

        $this->info('Parser daemon started. Next run will be scheduled 60 seconds after each full run completes.');
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ParserDaemonJob;
use App\Models\Setting;
use Illuminate\Console\Command;

class ParserDaemonStart extends Command
{
    protected $signature = 'parser:daemon-start';
    protected $description = 'Start continuous parser daemon (dispatches first run, schedules next on completion)';

    public function handle(): int
    {
        Setting::updateOrCreate(
            ['key' => 'parser_daemon_enabled'],
            ['value' => '1', 'group' => 'parser', 'type' => 'bool']
        );

        ParserDaemonJob::dispatch();

        $this->info('Parser daemon started. Next run will be scheduled 60 seconds after each full run completes.');
        return 0;
    }
}

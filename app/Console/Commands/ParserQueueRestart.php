<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ParserQueueRestart extends Command
{
    protected $signature = 'parser:queue-restart';
    protected $description = 'Restart queue workers (broadcast restart signal)';

    public function handle(): int
    {
        Artisan::call('queue:restart');
        $this->info(Artisan::output());
        $this->info('Workers will finish current job and restart.');
        return 0;
    }
}

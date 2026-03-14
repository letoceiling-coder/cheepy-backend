<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ParserWorkersRestart extends Command
{
    protected $signature = 'parser:workers-restart';
    protected $description = 'Restart queue workers (alias for parser:queue-restart)';

    public function handle(): int
    {
        Artisan::call('parser:queue-restart');
        $this->line(Artisan::output());
        return 0;
    }
}

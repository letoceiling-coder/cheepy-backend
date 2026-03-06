<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ParserRestart extends Command
{
    protected $signature = 'parser:restart';
    protected $description = 'Stop parser and restart queue workers';

    public function handle(): int
    {
        Artisan::call('parser:stop');
        $this->line(Artisan::output());

        Artisan::call('parser:queue-restart');
        $this->line(Artisan::output());

        $this->info('Parser stopped and workers will restart.');
        return 0;
    }
}

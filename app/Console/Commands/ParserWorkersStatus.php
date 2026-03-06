<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ParserWorkersStatus extends Command
{
    protected $signature = 'parser:workers-status';
    protected $description = 'Display queue worker processes and supervisor config hints';

    public function handle(): int
    {
        $processes = [];
        if (function_exists('proc_open')) {
            $output = [];
            exec('ps aux 2>/dev/null | grep -E "queue:work|artisan queue" | grep -v grep', $output);
            foreach ($output as $line) {
                if (trim($line)) {
                    $processes[] = preg_replace('/\s+/', ' ', trim($line));
                }
            }
        }

        if (empty($processes)) {
            $this->line('No queue workers detected (ps grep).');
            $this->newLine();
            $this->line('Expected Supervisor config:');
            $this->line('  - parser-worker: queue:work redis --queue=parser');
            $this->line('  - photo-worker:  queue:work redis --queue=photos');
            $this->line('Config path: /etc/supervisor/conf.d/');
            return 0;
        }

        $this->line('Running queue workers:');
        foreach ($processes as $p) {
            $this->line('  ' . $p);
        }
        $this->newLine();
        $this->line('Config path: /etc/supervisor/conf.d/');
        $this->line('  Check for: --queue=parser and --queue=photos');

        return 0;
    }
}

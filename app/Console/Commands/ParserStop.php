<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use Illuminate\Console\Command;

class ParserStop extends Command
{
    protected $signature = 'parser:stop';
    protected $description = 'Stop all running/pending parser jobs';

    public function handle(): int
    {
        $updated = ParserJob::whereIn('status', ['running', 'pending'])
            ->update(['status' => 'stopped', 'finished_at' => now()]);

        if ($updated > 0) {
            $this->info("Stopped {$updated} parser job(s).");
        } else {
            $this->info('No running or pending parser jobs.');
        }

        try {
            \Illuminate\Support\Facades\Redis::del('parser_running');
        } catch (\Throwable $e) {
            // ignore
        }

        return 0;
    }
}

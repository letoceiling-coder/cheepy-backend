<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ParserReleaseLock extends Command
{
    protected $signature = 'parser:release-lock';
    protected $description = 'Release parser_lock (allows new parser start)';

    public function handle(): int
    {
        try {
            Redis::del('parser_lock');
            Redis::del('parser_running');
            $this->info('Parser lock released.');
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return 1;
        }
        return 0;
    }
}

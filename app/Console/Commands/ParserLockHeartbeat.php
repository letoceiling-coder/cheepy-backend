<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class ParserLockHeartbeat extends Command
{
    protected $signature = 'parser:lock-heartbeat';
    protected $description = 'Refresh parser_lock TTL if parser is running (run every 30s by scheduler)';

    private const LOCK_KEY = 'parser_lock';
    private const LOCK_TTL = 7200;

    public function handle(): int
    {
        $running = ParserJob::whereIn('status', ['running', 'pending'])->exists();
        if (!$running) {
            return 0;
        }

        try {
            if (Redis::exists(self::LOCK_KEY)) {
                Redis::expire(self::LOCK_KEY, self::LOCK_TTL);
                Log::debug('Parser lock heartbeat: TTL refreshed');
            }
        } catch (\Throwable $e) {
            Log::warning('Parser lock heartbeat failed', ['error' => $e->getMessage()]);
            return 1;
        }

        return 0;
    }
}

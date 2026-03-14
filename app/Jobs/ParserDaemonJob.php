<?php

namespace App\Jobs;

use App\Models\ParserJob;
use App\Models\ParserState;
use App\Services\Parser\ParserLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class ParserDaemonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('parser');
    }

    public function handle(): void
    {
        $state = ParserState::current();
        if ($state->status !== ParserState::STATUS_RUNNING) {
            Log::info('Parser daemon blocked (state=' . $state->status . ')');
            return;
        }

        try {
            $queueParser = (int) Queue::connection(config('queue.default'))->size('parser');
            if ($queueParser > 500) {
                ParserLogger::write('warning', 'Parser daemon throttled: parser queue above threshold', [
                    'queue_size' => $queueParser,
                    'threshold' => 500,
                ]);
                self::dispatch()->delay(now()->addMinutes(5));
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Parser daemon: queue size check failed', ['error' => $e->getMessage()]);
        }

        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        if ($running) {
            Log::info('Parser daemon: run already in progress, scheduling next check in 60 seconds');
            self::dispatch()->delay(now()->addSeconds(60));
            return;
        }

        Log::info('Parser daemon iteration started');

        try {
            if (!Redis::set('parser_lock', 1, 'EX', 7200, 'NX')) {
                Log::warning('Parser daemon: could not acquire lock, skipping');
                return;
            }
        } catch (\Throwable $e) {
            Log::error('Parser daemon: Redis lock failed', ['error' => $e->getMessage()]);
            return;
        }

        $job = ParserJob::create([
            'type' => 'full',
            'options' => [],
            'status' => 'pending',
        ]);

        RunParserJob::dispatch($job->id);

        // Next iteration is scheduled by ScheduleNextParserDaemon when this run completes (ParserFinished)
    }
}

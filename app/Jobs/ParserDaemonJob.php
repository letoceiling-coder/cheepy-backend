<?php

namespace App\Jobs;

use App\Models\ParserJob;
use App\Models\ParserState;
use App\Support\ParserJobOptions;
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

        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        if ($running) {
            Log::info('Parser daemon: run already in progress, scheduling next check in 60 seconds');
            self::dispatch()->delay(now()->addSeconds(60));

            return;
        }

        Log::info('Parser daemon iteration started');

        try {
            // TTL 1200с (20 мин) вместо 2 часов: при падении воркера лок самосбросится
            // быстрее, не блокируя демон/ручной старт. Heartbeat продлевает TTL каждые 30с.
            if (! Redis::set('parser_lock', 1, 'EX', 1200, 'NX')) {
                Log::warning('Parser daemon: could not acquire lock, skipping');

                return;
            }
        } catch (\Throwable $e) {
            Log::error('Parser daemon: Redis lock failed', ['error' => $e->getMessage()]);

            return;
        }

        $options = ParserJobOptions::buildFromSettings();

        ParserJobOptions::assertCategoriesForJob('full', $options);

        Log::critical('OPTIONS BEFORE CREATE', $options);

        try {
            // Same as Redis LLEN on the parser list; Queue respects redis prefix / connection.
            $queueSize = (int) Queue::connection(config('queue.default'))->size('parser');
        } catch (\Throwable $e) {
            Redis::del('parser_lock');
            Log::warning('Parser daemon: queue size check failed', ['error' => $e->getMessage()]);

            return;
        }

        /** Жёсткий потолок: не ставить новый parser job при переполнении очереди */
        if ($queueSize > 150) {
            Redis::del('parser_lock');
            Log::critical('QUEUE BLOCKED', [
                'size' => $queueSize,
                'queue' => 'parser',
                'limit' => 150,
            ]);

            return;
        }

        $job = ParserJob::create([
            'type' => 'full',
            'options' => $options,
            'status' => 'pending',
        ]);

        RunParserJob::dispatch($job->id);
    }
}

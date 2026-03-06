<?php

namespace App\Jobs;

use App\Models\ParserJob;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        if (!Setting::get('parser_daemon_enabled', false)) {
            Log::info('Parser daemon: disabled, stopping');
            return;
        }

        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        if ($running) {
            Log::info('Parser daemon: run already in progress, scheduling next check in 60 seconds');
            self::dispatch()->delay(now()->addSeconds(60));
            return;
        }

        Log::info('Parser daemon iteration started');

        $job = ParserJob::create([
            'type' => 'full',
            'options' => [],
            'status' => 'pending',
        ]);

        RunParserJob::dispatch($job->id);

        // Next iteration is scheduled by ScheduleNextParserDaemon when this run completes (ParserFinished)
    }
}

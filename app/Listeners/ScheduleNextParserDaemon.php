<?php

namespace App\Listeners;

use App\Events\ParserFinished;
use App\Jobs\ParserDaemonJob;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ScheduleNextParserDaemon implements ShouldQueue
{
    public function handle(ParserFinished $event): void
    {
        if (!Setting::get('parser_daemon_enabled', false)) {
            return;
        }

        $job = $event->job;
        if (($job->type ?? '') !== 'full') {
            return;
        }

        Log::info('Parser daemon: scheduling next run in 60 seconds');
        ParserDaemonJob::dispatch()->delay(now()->addSeconds(60));
    }
}

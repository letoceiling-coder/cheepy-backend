<?php

namespace App\Listeners;

use App\Events\ParserFinished;
use App\Jobs\ParserDaemonJob;
use App\Models\ParserState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ScheduleNextParserDaemon implements ShouldQueue
{
    /** @var string Use parser queue so workers process it (workers listen on parser, not default). */
    public $queue = 'parser';

    public function handle(ParserFinished $event): void
    {
        if (ParserState::current()->status !== ParserState::STATUS_RUNNING) {
            Log::info('ScheduleNextParserDaemon: parser state is not RUNNING, skipping');
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

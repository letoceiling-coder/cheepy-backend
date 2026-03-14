<?php

namespace App\Listeners;

use App\Events\ParserFinished;
use App\Jobs\ParserDaemonJob;
use App\Models\ParserSetting;
use App\Models\ParserState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
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

        $settings = ParserSetting::current();
        $queueThreshold = min(500, (int) ($settings->queue_threshold ?? config('parser.queue_threshold', 500)));
        $queueSize = 0;
        try {
            $conn = Queue::connection(config('queue.default'));
            $queueSize = (int) $conn->size('parser') + (int) $conn->size('photos');
        } catch (\Throwable $e) {
            Log::warning('ScheduleNextParserDaemon: queue size check failed', ['error' => $e->getMessage()]);
        }

        if ($queueSize > $queueThreshold) {
            Log::warning('Parser daemon: queue threshold exceeded, delaying next run', [
                'queue_size' => $queueSize,
                'threshold' => $queueThreshold,
            ]);
            ParserDaemonJob::dispatch()->delay(now()->addMinutes(5));
            return;
        }

        Log::info('Parser daemon: scheduling next run in 60 seconds');
        ParserDaemonJob::dispatch()->delay(now()->addSeconds(60));
    }
}

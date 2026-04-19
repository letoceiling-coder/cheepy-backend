<?php

namespace App\Jobs;

use App\Models\ParserJob;
use App\Services\DatabaseParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Queue job to run the parser.
 * Replaces exec("php artisan parser:run {id}").
 * API compatibility: POST /parser/start still returns { job_id }.
 *
 * Queue: `parser` (same worker as ParseCategoryJob — use e.g. `queue:work --queue=parser` or `default,parser,photos` per infra).
 */
class RunParserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour
    public int $tries = 3;
    public array $backoff = [60, 300, 900]; // 1m, 5m, 15m

    public function __construct(
        public int $jobId
    ) {
        $this->onQueue('parser');
    }

    public function handle(): void
    {
        $job = ParserJob::find($this->jobId);
        if (! $job) {
            Log::warning("RunParserJob: ParserJob {$this->jobId} not found");

            return;
        }

        try {
            $job->update(['status' => 'running', 'started_at' => now()]);
            (new DatabaseParserService($job))->run();
            $job->refresh();
            if ($job->status === 'running') {
                $job->update(['status' => 'completed', 'finished_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::error("RunParserJob failed for job {$this->jobId}: ".$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            throw $e; // rethrow for retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        $job = ParserJob::find($this->jobId);
        if ($job) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }
        Log::error("RunParserJob permanently failed for job {$this->jobId}", [
            'exception' => $exception->getMessage(),
        ]);

        // Иначе parser_lock остаётся до TTL (1200с) без ParserFinished — блокирует ручной старт и демон.
        try {
            Redis::del('parser_lock');
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

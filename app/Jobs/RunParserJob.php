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

class RunParserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $parserJobId
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $job = ParserJob::find($this->parserJobId);
        if (!$job) {
            Log::warning("RunParserJob: ParserJob {$this->parserJobId} not found");
            return;
        }

        if (!in_array($job->status, ['pending', 'running'])) {
            Log::info("RunParserJob: Job {$this->parserJobId} status is {$job->status}, skipping");
            return;
        }

        $job->update(['pid' => getmypid()]);

        try {
            $service = new DatabaseParserService($job);
            $service->run();
        } catch (\Throwable $e) {
            Log::error("RunParserJob failed for job {$this->parserJobId}: " . $e->getMessage());
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $job = ParserJob::find($this->parserJobId);
        if ($job) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }
        Log::error("RunParserJob permanently failed for job {$this->parserJobId}", [
            'exception' => $exception->getMessage(),
        ]);
    }
}

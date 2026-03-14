<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ParserKillStuck extends Command
{
    protected $signature = 'parser:kill-stuck
                            {--idle-minutes=10 : Consider stuck if no activity for N minutes}';
    protected $description = 'Mark stuck parser jobs as failed, release lock, restart workers';

    public function handle(): int
    {
        $idleMinutes = (int) $this->option('idle-minutes');
        $cutoff = now()->subMinutes($idleMinutes);

        $stuck = ParserJob::whereIn('status', ['running', 'pending'])
            ->where(function ($q) use ($cutoff) {
                $q->where('updated_at', '<', $cutoff)
                    ->orWhereNull('updated_at');
            })
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck jobs found.');
            return 0;
        }

        foreach ($stuck as $job) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => 'Stuck: no activity for >' . $idleMinutes . ' minutes (killed by parser:kill-stuck)',
            ]);
            Log::warning('Parser job marked as stuck and failed', [
                'job_id' => $job->id,
                'idle_minutes' => $idleMinutes,
            ]);
            $this->warn("Job #{$job->id} marked as failed (stuck).");
        }

        try {
            Redis::del('parser_lock');
            Redis::del('parser_running');
            $this->info('Lock released.');
        } catch (\Throwable $e) {
            Log::warning('Parser kill-stuck: could not release lock', ['error' => $e->getMessage()]);
        }

        Artisan::call('queue:restart');
        $this->info('Workers restart signal sent.');

        return 0;
    }
}

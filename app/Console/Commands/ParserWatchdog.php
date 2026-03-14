<?php

namespace App\Console\Commands;

use App\Jobs\ParserDaemonJob;
use App\Models\ParserJob;
use App\Models\ParserSetting;
use App\Models\ParserState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class ParserWatchdog extends Command
{
    protected $signature = 'parser:watchdog
                            {--idle-minutes=10 : Consider parser idle after N minutes of no activity}
                            {--dry-run : Log actions only, do not execute}';
    protected $description = 'Failsafe: restart workers if queue>0 and workers=0; restart parser if idle > N min';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $idleMinutes = (int) $this->option('idle-minutes');

        $running = ParserJob::whereIn('status', ['running', 'pending'])->latest()->first();
        $queueParser = 0;
        $queuePhotos = 0;
        try {
            $conn = Queue::connection(config('queue.default'));
            $queueParser = $conn->size('parser');
            $queuePhotos = $conn->size('photos');
        } catch (\Throwable $e) {
            Log::warning('Parser watchdog: queue check failed', ['error' => $e->getMessage()]);
            return 1;
        }

        $workersCount = 0;
        $supervisorRows = [];
        if (function_exists('shell_exec')) {
            $supervisorOut = (string) (@shell_exec('supervisorctl status 2>/dev/null') ?? '');
            if ($supervisorOut !== '') {
                $supervisorRows = array_values(array_filter(preg_split('/\R/', $supervisorOut)));
            }
            $out = @shell_exec('ps aux 2>/dev/null | grep -E "artisan queue:work" | grep -v grep | wc -l');
            $workersCount = (int) trim((string) ($out ?? '0'));
        }

        $queueTotal = $queueParser + $queuePhotos;
        $settings = ParserSetting::current();
        $queueThreshold = min(500, (int) ($settings->queue_threshold ?? config('parser.queue_threshold', 500)));

        if ($queueTotal > $queueThreshold) {
            Log::warning('Parser watchdog: queue threshold reached, limiting daemon concurrency', [
                'queue_total' => $queueTotal,
                'threshold' => $queueThreshold,
            ]);
            $this->warn("Watchdog: queue overloaded ({$queueTotal}), daemon dispatch temporarily limited");
            return 0;
        }

        if (!$dryRun && !empty($supervisorRows)) {
            $desiredParser = (int) ($settings->workers_parser ?? config('parser.workers_parser', 2));
            $desiredPhotos = (int) ($settings->workers_photos ?? config('parser.workers_photos', 1));
            $runningParser = [];
            $runningPhotos = [];

            foreach ($supervisorRows as $row) {
                if (!str_contains($row, 'RUNNING')) {
                    continue;
                }
                $procName = trim((string) strtok($row, ' '));
                if (str_contains($procName, 'parser-worker-photos:')) {
                    $runningPhotos[] = $procName;
                } elseif (str_contains($procName, 'parser-worker:')) {
                    $runningParser[] = $procName;
                }
            }

            if (count($runningParser) > $desiredParser) {
                foreach (array_slice($runningParser, $desiredParser) as $proc) {
                    @shell_exec('supervisorctl stop ' . escapeshellarg($proc) . ' 2>/dev/null');
                }
                Log::warning('Parser watchdog: reduced parser workers to target', [
                    'running' => count($runningParser),
                    'target' => $desiredParser,
                ]);
            }

            if (count($runningPhotos) > $desiredPhotos) {
                foreach (array_slice($runningPhotos, $desiredPhotos) as $proc) {
                    @shell_exec('supervisorctl stop ' . escapeshellarg($proc) . ' 2>/dev/null');
                }
                Log::warning('Parser watchdog: reduced photo workers to target', [
                    'running' => count($runningPhotos),
                    'target' => $desiredPhotos,
                ]);
            }
        }

        // If queue has jobs but no workers → restart workers
        if ($queueTotal > 0 && $workersCount === 0) {
            Log::info('Parser watchdog: queue has jobs but workers=0, restarting workers', [
                'queue_parser' => $queueParser,
                'queue_photos' => $queuePhotos,
            ]);
            if (!$dryRun) {
                Artisan::call('queue:restart');
                Log::info('Parser watchdog: sent queue:restart (workers will restart via supervisor)');
            }
            $this->info('Watchdog: restarted workers (queue had jobs, workers were dead)');
            return 0;
        }

        if (ParserState::current()->status !== ParserState::STATUS_RUNNING) {
            $this->line('Watchdog: parser state is not RUNNING, will not dispatch daemon');
            return 0;
        }

        $lastActivity = $running?->updated_at ?? $running?->started_at ?? $running?->created_at;
        $idleMinutesActual = $lastActivity ? now()->diffInMinutes($lastActivity) : 0;
        $isIdle = $running && $idleMinutesActual >= $idleMinutes;
        $queueEmpty = $queueTotal === 0;

        if ($isIdle && $queueEmpty) {
            Log::info('Parser watchdog: parser idle and queue empty, restarting daemon', [
                'job_id' => $running->id,
                'idle_minutes' => $idleMinutesActual,
            ]);
            if (!$dryRun) {
                ParserJob::whereIn('status', ['running', 'pending'])->update(['status' => 'stopped', 'finished_at' => now()]);
                \Illuminate\Support\Facades\Redis::del('parser_lock');
                ParserDaemonJob::dispatch()->delay(now()->addSeconds(30));
                Log::info('Parser watchdog: restarted daemon');
            }
            $this->info('Watchdog: restarted daemon (parser was idle)');
            return 0;
        }

        $this->line('Watchdog: no action (parser active or queue not empty)');
        return 0;
    }
}

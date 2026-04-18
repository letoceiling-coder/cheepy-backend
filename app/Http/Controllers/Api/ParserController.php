<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParserJob;
use App\Models\ParserLog;
use App\Models\ParserProgress;
use App\Models\ParserSetting;
use App\Models\ParserState;
use App\Models\Product;
use App\Models\Category;
use App\Jobs\ParserDaemonJob;
use App\Jobs\RunParserJob;
use App\Services\Parser\ParserLogger;
use App\Services\DatabaseParserService;
use App\Support\ParserJobOptions;
use App\Support\ParserProxyState;
use App\Support\QueueWorkerDiagnostics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use App\Services\PhotoDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParserController extends Controller
{
    /**
     * GET /api/v1/parser/state
     * Explicit parser state (source of truth for daemon).
     */
    public function state(Request $request): JsonResponse
    {
        $ps = ParserState::current();
        $proxyState = ParserProxyState::snapshot();
        return response()->json([
            'status' => $ps->status,
            'network_mode' => $ps->network_mode,
            'locked' => $ps->locked,
            'last_start' => $ps->last_start?->toIso8601String(),
            'last_stop' => $ps->last_stop?->toIso8601String(),
            'proxy_blocked' => $proxyState['blocked'],
            'proxy_blocked_until' => $proxyState['blocked_until'],
            'proxy_block_reason' => $proxyState['reason'],
            'proxy_last_action' => $proxyState['last_action'],
            'updated_at' => $ps->updated_at->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/parser/settings
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json(ParserSetting::current());
    }

    /**
     * POST /api/v1/parser/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        Log::warning('SETTINGS RECEIVED', $request->all());

        $aliases = $request->only(['max_pages', 'categories', 'linked_only', 'products_per_category', 'no_details']);
        if (array_key_exists('max_pages', $aliases)) {
            $request->merge(['default_max_pages' => $aliases['max_pages']]);
        }
        if (array_key_exists('categories', $aliases)) {
            $request->merge(['default_category_ids' => $aliases['categories']]);
        }
        if (array_key_exists('linked_only', $aliases)) {
            $request->merge(['default_linked_only' => $aliases['linked_only']]);
        }
        if (array_key_exists('products_per_category', $aliases)) {
            $request->merge(['default_products_per_category' => $aliases['products_per_category']]);
        }
        if (array_key_exists('no_details', $aliases)) {
            $request->merge(['default_no_details' => $aliases['no_details']]);
        }

        $validated = $request->validate([
            'download_photos' => ['nullable', 'boolean'],
            'store_photo_links' => ['nullable', 'boolean'],
            'max_workers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'request_delay_min' => ['nullable', 'integer', 'min:100', 'max:10000'],
            'request_delay_max' => ['nullable', 'integer', 'min:100', 'max:15000'],
            'timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:300'],
            'workers_parser' => ['nullable', 'integer', 'min:1', 'max:20'],
            'workers_photos' => ['nullable', 'integer', 'min:1', 'max:20'],
            'proxy_enabled' => ['nullable', 'boolean'],
            'proxy_url' => ['nullable', 'string', 'max:255'],
            'proxy_urls' => ['nullable', 'array'],
            'proxy_urls.*' => ['nullable', 'string', 'max:512'],
            'queue_threshold' => ['nullable', 'integer', 'min:10', 'max:1000000'],
            'default_max_pages' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'default_products_per_category' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'default_linked_only' => ['nullable', 'boolean'],
            'default_category_ids' => ['nullable', 'array'],
            'default_category_ids.*' => ['integer', 'min:1'],
            'default_no_details' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('proxy_urls', $validated) && is_array($validated['proxy_urls'])) {
            $urls = array_values(array_filter(array_map('trim', $validated['proxy_urls'])));
            if ($urls !== []) {
                $validated['proxy_url'] = $urls[0];
            }
        }

        $settings = ParserSetting::current();
        $settings->update($validated);

        Log::warning('SETTINGS SAVED', $settings->fresh()->toArray());

        return response()->json([
            'message' => 'Настройки парсера обновлены',
            'data' => $settings->fresh(),
        ]);
    }

    /**
     * POST /api/v1/parser/start
     * Запустить парсинг в фоновом процессе (один прогон или daemon)
     */
    public function start(Request $request): JsonResponse
    {
        // Manual one-run start must not keep daemon mode enabled; otherwise
        // ScheduleNextParserDaemon may enqueue a new full run after ParserFinished.
        ParserState::current()->update([
            'status' => ParserState::STATUS_STOPPED,
            'last_stop' => now(),
        ]);

        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        if ($running) {
            return response()->json([
                'error' => 'Парсер уже запущен',
                'job_id' => $running->id,
            ], 409);
        }

        $lockKey = 'parser_lock';
        $lockTtl = 7200;
        try {
            if (!Redis::set($lockKey, 1, 'EX', $lockTtl, 'NX')) {
                return response()->json(['error' => 'Парсер уже запущен (lock)', 'job_id' => null], 409);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Redis lock failed: ' . $e->getMessage()], 500);
        }

        // Clear queues to remove orphaned jobs from previous runs (prevents "ParserJob not found" blocking)
        try {
            $conn = \Illuminate\Support\Facades\Queue::connection(config('queue.default'));
            foreach (['parser', 'photos'] as $q) {
                \Illuminate\Support\Facades\Artisan::call('queue:clear', [
                    'connection' => config('queue.default'),
                    '--queue' => $q,
                    '--force' => true,
                ]);
            }
        } catch (\Throwable $e) {
            // Log but continue — lock already acquired
            \Illuminate\Support\Facades\Log::warning('Queue clear on start failed', ['error' => $e->getMessage()]);
        }

        $overrideKeys = ['categories', 'linked_only', 'products_per_category', 'max_pages', 'no_details', 'save_photos', 'save_to_db', 'category_slug', 'seller_slug'];
        $overrides = [];
        foreach ($overrideKeys as $key) {
            if (! $request->has($key)) {
                continue;
            }
            $overrides[$key] = match ($key) {
                'categories' => $request->input('categories', []),
                'linked_only', 'no_details', 'save_photos', 'save_to_db' => $request->boolean($key),
                'products_per_category', 'max_pages' => (int) $request->input($key, 0),
                default => $request->input($key),
            };
        }

        $options = ParserJobOptions::buildForApiStart($overrides);

        $type = $request->input('type', 'full');

        ParserJobOptions::assertCategoriesForJob($type, $options);

        Log::critical('OPTIONS BEFORE CREATE', $options);

        $job = ParserJob::create([
            'type'    => $type,
            'options' => $options,
            'status'  => 'pending',
        ]);

        RunParserJob::dispatch($job->id);

        return response()->json([
            'message' => 'Парсинг запущен',
            'job_id' => $job->id,
            'job' => $this->formatJob($job),
        ], 201);
    }

    /**
     * POST /api/v1/parser/stop
     * 1. Set status=STOPPED, 2. Clear queues, 3. Restart workers, 4. Kill running jobs.
     */
    public function stop(Request $request): JsonResponse
    {
        ParserState::current()->update([
            'status' => ParserState::STATUS_STOPPED,
            'last_stop' => now(),
        ]);

        $updated = ParserJob::whereIn('status', ['running', 'pending'])
            ->update(['status' => 'stopped', 'finished_at' => now()]);

        try {
            Redis::del('parser_running');
            Redis::del('parser_lock');
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $conn = config('queue.default');
            foreach (['parser', 'photos'] as $queue) {
                \Illuminate\Support\Facades\Artisan::call('queue:clear', [
                    'connection' => $conn,
                    '--queue' => $queue,
                    '--force' => true,
                ]);
            }
            \Illuminate\Support\Facades\Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Parser stop: queue clear/restart failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Парсер остановлен',
            'jobs_stopped' => $updated,
        ]);
    }

    /**
     * POST /api/v1/parser/restart
     * Stop parser and restart queue workers.
     */
    public function restart(Request $request): JsonResponse
    {
        $updated = ParserJob::whereIn('status', ['running', 'pending'])
            ->update(['status' => 'stopped', 'finished_at' => now()]);

        try {
            Redis::del('parser_running');
            Redis::del('parser_lock');
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Queue restart failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Парсер остановлен, воркеры перезапускаются',
            'jobs_stopped' => $updated,
        ]);
    }

    /**
     * POST /api/v1/parser/queue-clear
     * Clear parser queue jobs.
     */
    public function queueClear(Request $request): JsonResponse
    {
        $queue = $request->input('queue', 'parser');
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:clear', [
                'connection' => config('queue.default'),
                '--queue' => $queue,
                '--force' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Queue clear failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => "Очередь {$queue} очищена"]);
    }

    /**
     * POST /api/v1/parser/queue-restart
     * Restart queue workers.
     */
    public function queueRestart(Request $request): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Queue restart failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Воркеры перезапускаются']);
    }

    /**
     * POST /api/v1/parser/queue-flush
     * Clear parser, photos, and default queues (all job queues).
     */
    public function queueFlush(Request $request): JsonResponse
    {
        $conn = config('queue.default');
        $cleared = [];
        foreach (['parser', 'photos', 'default'] as $queue) {
            try {
                \Illuminate\Support\Facades\Artisan::call('queue:clear', [
                    'connection' => $conn,
                    '--queue' => $queue,
                    '--force' => true,
                ]);
                $cleared[] = $queue;
            } catch (\Throwable $e) {
                return response()->json(['error' => "Queue {$queue} clear failed: " . $e->getMessage()], 500);
            }
        }
        return response()->json(['message' => 'Очереди очищены', 'queues' => $cleared]);
    }

    /**
     * POST /api/v1/parser/kill-stuck
     * Mark stuck parser jobs as failed, release lock, restart workers.
     */
    public function killStuck(Request $request): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('parser:kill-stuck', [
                '--idle-minutes' => (int) $request->input('idle_minutes', 10),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Kill stuck failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Зависшие задачи помечены как failed, lock освобождён, воркеры перезапускаются']);
    }

    /**
     * POST /api/v1/parser/release-lock
     * Release parser_lock (allows new parser start).
     */
    public function releaseLock(Request $request): JsonResponse
    {
        try {
            Redis::del('parser_lock');
            Redis::del('parser_running');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Release lock failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Блокировка снята']);
    }

    /**
     * POST /api/v1/parser/clear-failed
     * Clear all failed jobs from failed_jobs table.
     */
    public function clearFailedJobs(Request $request): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:flush');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Clear failed jobs failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Failed jobs очищены']);
    }

    /**
     * GET /api/v1/parser/failed-jobs
     * List failed jobs (last 20).
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);
        $data = $rows->map(function ($row) {
            $payload = @json_decode($row->payload, true);
            $displayName = $payload['displayName'] ?? $row->queue ?? 'unknown';
            return [
                'id'       => $row->id,
                'uuid'     => $row->uuid,
                'queue'    => $row->queue,
                'display_name' => $displayName,
                'exception' => $row->exception ? substr($row->exception, 0, 500) : null,
                'failed_at' => $row->failed_at,
            ];
        });
        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/parser/retry-job/{id}
     * Retry a failed job by id (from failed_jobs table).
     */
    public function retryJob(Request $request, $id): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$id]]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Retry failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Job возвращён в очередь']);
    }

    /**
     * POST /api/v1/parser/reset
     * Emergency reset: stop parser, release lock, clear queues, restart workers.
     */
    public function reset(Request $request): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('parser:reset', ['--force' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Reset failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Парсер сброшен']);
    }

    /**
     * POST /api/v1/parser/start-daemon
     * Set status=RUNNING, dispatch first run.
     */
    public function startDaemon(Request $request): JsonResponse
    {
        try {
            $proxy = $this->checkProxyAvailability(true);
            $networkMode = null;

            if ($proxy['proxy_ok']) {
                $donorViaProxy = $this->checkDonorAvailability(true);
                if ($donorViaProxy) {
                    $networkMode = 'proxy';
                    Log::warning('[NETWORK MODE]', ['mode' => 'proxy']);
                } else {
                    $direct = $this->checkDonorAvailability(false);
                    if ($direct) {
                        $networkMode = 'direct';
                        Log::warning('[NETWORK MODE]', ['mode' => 'direct']);
                    } else {
                        Log::critical('[NETWORK FAIL] both failed', [
                            'phase' => 'proxy_ok_donor_unreachable',
                            'proxy_reason' => $proxy['reason'] ?? null,
                        ]);
                        ParserState::current()->update([
                            'status' => ParserState::STATUS_PAUSED_NETWORK,
                            'network_mode' => null,
                            'last_stop' => now(),
                        ]);
                        ParserLogger::write('network_error', 'Donor unavailable: reachable via proxy tunnel but donor failed; direct also failed');

                        return response()->json([
                            'error' => 'Donor unavailable (proxy + direct failed)',
                            'daemon_enabled' => false,
                        ], 503);
                    }
                }
            } else {
                $direct = $this->checkDonorAvailability(false);
                if ($direct) {
                    $networkMode = 'direct';
                    Log::warning('[NETWORK MODE]', ['mode' => 'direct', 'proxy_reason' => $proxy['reason'] ?? null]);
                } else {
                    Log::critical('[NETWORK FAIL] both failed', ['proxy_reason' => $proxy['reason'] ?? null]);
                    ParserState::current()->update([
                        'status' => ParserState::STATUS_PAUSED_NETWORK,
                        'network_mode' => null,
                        'last_stop' => now(),
                    ]);
                    ParserLogger::write('network_error', 'Donor unavailable (proxy + direct failed)', [
                        'proxy_reason' => $proxy['reason'] ?? null,
                    ]);

                    return response()->json([
                        'error' => 'Donor unavailable (proxy + direct failed)',
                        'daemon_enabled' => false,
                    ], 503);
                }
            }

            Config::set('parser.use_proxy', $networkMode === 'proxy');

            // Нет активного ParserJob — снимаем залипший Redis-lock (после сбоя без ParserFinished / после wipe БД).
            $activeParserJob = ParserJob::whereIn('status', ['running', 'pending'])->exists();
            if (! $activeParserJob) {
                try {
                    Redis::del('parser_lock');
                    Redis::del('parser_running');
                } catch (\Throwable $e) {
                    Log::warning('start-daemon: could not clear stale parser locks', ['error' => $e->getMessage()]);
                }
            }

            ParserState::current()->update([
                'status' => ParserState::STATUS_RUNNING,
                'network_mode' => $networkMode,
                'last_start' => now(),
            ]);

            ParserDaemonJob::dispatch();

            return response()->json([
                'message' => 'Парсер запущен. Следующий прогон — через 60 сек после завершения текущего.',
                'daemon_enabled' => true,
                'network_mode' => $networkMode,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Network precheck failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/parser/stop-daemon
     * Same as stop: status=STOPPED, clear queue, restart workers.
     */
    public function stopDaemon(Request $request): JsonResponse
    {
        return $this->stop($request);
    }

    /**
     * POST /api/v1/parser/pause
     * Set status=PAUSED — daemon won't schedule next run.
     */
    public function pause(Request $request): JsonResponse
    {
        ParserState::current()->update([
            'status' => ParserState::STATUS_PAUSED,
            'last_stop' => now(),
        ]);
        return response()->json(['message' => 'Парсер приостановлен', 'status' => 'paused']);
    }

    /**
     * GET /api/v1/parser/status
     */
    public function status(): JsonResponse
    {
        $running = ParserJob::whereIn('status', ['running', 'pending'])->latest()->first();
        $lastCompleted = ParserJob::where('status', 'completed')->latest()->first();

        $queueParser = 0;
        $queueDefault = 0;
        $queuePhotos = 0;
        try {
            $conn = \Illuminate\Support\Facades\Queue::connection(config('queue.default'));
            $queueParser = $conn->size('parser');
            $queueDefault = $conn->size('default');
            $queuePhotos = $conn->size('photos');
        } catch (\Throwable $e) {
            // ignore
        }

        $workerSnap = QueueWorkerDiagnostics::snapshot();
        $workersRunning = $workerSnap['parser_workers'];
        $photoWorkersRunning = $workerSnap['photo_workers'];
        $queueStalled = ($queueParser + $queueDefault) > 0 && $workersRunning === 0;
        $photosStalled = $queuePhotos > 0 && $photoWorkersRunning === 0;

        $warning = $this->mergeStaleWorkerWarnings(
            $this->detectParserWarning($running, $queueParser + $queueDefault + $queuePhotos),
            $queueStalled,
            $photosStalled
        );

        $parserState = ParserState::current();
        $proxyState = ParserProxyState::snapshot();
        return response()->json([
            'is_running'          => $running !== null,
            'daemon_enabled'      => $parserState->status === ParserState::STATUS_RUNNING,
            'parser_state'        => $parserState->status,
            'network_mode'        => $parserState->network_mode,
            'current_job'         => $running ? $this->formatJob($running) : null,
            'last_completed'      => $lastCompleted ? $this->formatJob($lastCompleted) : null,
            'queue_parser_size'   => $queueParser,
            'queue_default_size'  => $queueDefault,
            'queue_photos_size'   => $queuePhotos,
            'queue_total_size'    => $queueParser + $queueDefault + $queuePhotos,
            'workers_running'     => $workersRunning,
            'photo_workers_running' => $photoWorkersRunning,
            'queue_workers_stalled' => $queueStalled,
            'photo_queue_workers_stalled' => $photosStalled,
            'proxy_blocked'       => $proxyState['blocked'],
            'proxy_blocked_until' => $proxyState['blocked_until'],
            'proxy_block_reason'  => $proxyState['reason'],
            'proxy_last_action'   => $proxyState['last_action'],
            'warning'             => $warning,
        ]);
    }

    /**
     * GET /api/v1/parser/diagnostics
     * Full diagnostics: queues, workers, metrics, running state.
     */
    public function diagnostics(): JsonResponse
    {
        $running = ParserJob::whereIn('status', ['running', 'pending'])->latest()->first();
        $queueParser = 0;
        $queueDefault = 0;
        $queuePhotos = 0;
        try {
            $conn = \Illuminate\Support\Facades\Queue::connection(config('queue.default'));
            $queueParser = $conn->size('parser');
            $queueDefault = $conn->size('default');
            $queuePhotos = $conn->size('photos');
        } catch (\Throwable $e) {
            // ignore
        }

        $failedJobs = 0;
        try {
            $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            // ignore
        }

        $metrics = [];
        if (class_exists(\App\Services\ParserMetricsService::class)) {
            $metrics = \App\Services\ParserMetricsService::getMetrics();
            $metrics['products_per_minute'] = \App\Services\ParserMetricsService::getProductsPerMinute();
        }

        $lockHeld = false;
        try {
            $lockHeld = (bool) Redis::get('parser_lock');
        } catch (\Throwable $e) {
            // ignore
        }

        $workerSnap = QueueWorkerDiagnostics::snapshot();
        $workersRunning = $workerSnap['parser_workers'];
        $photoWorkersRunning = $workerSnap['photo_workers'];

        $queueStalled = ($queueParser + $queueDefault) > 0 && $workersRunning === 0;
        $photosStalled = $queuePhotos > 0 && $photoWorkersRunning === 0;

        $lastErrors = ParserLog::whereIn('level', ['error', 'warn'])
            ->latest('logged_at')
            ->limit(10)
            ->get(['id', 'level', 'message', 'logged_at', 'url', 'attempt']);

        $errorsPerHour = ParserLog::where('level', 'error')
            ->where('logged_at', '>=', now()->subHour())
            ->count();

        $progress = ParserProgress::query()
            ->latest('updated_at')
            ->first(['job_id', 'total_items', 'processed_items', 'failed_items', 'current_url', 'speed_per_min', 'updated_at']);
        $proxyProbe = $this->checkProxyAvailability(true);
        $proxyOk = $proxyProbe['proxy_ok'];
        $parserState = ParserState::current();
        $proxyState = ParserProxyState::snapshot();
        $donorOk = $this->checkDonorAvailability(
            $parserState->isRunning() && $parserState->network_mode === 'proxy'
        );

        $errorsTodayProducts = (int) Product::where('status', 'error')
            ->whereDate('status_changed_at', today())
            ->count();
        $errorsTodayParserLogs = (int) ParserLog::where('level', 'error')->whereDate('logged_at', today())->count();

        $warning = $this->mergeStaleWorkerWarnings(
            $this->detectParserWarning($running, $queueParser + $queueDefault + $queuePhotos),
            $queueStalled,
            $photosStalled
        );

        return response()->json([
            'workers_running' => $workersRunning,
            'photo_workers_running' => $photoWorkersRunning,
            'queue_workers_stalled' => $queueStalled,
            'photo_queue_workers_stalled' => $photosStalled,
            'workers_detection' => [
                'supervisor_parser' => $workerSnap['supervisor_parser'],
                'ps_parser' => $workerSnap['ps_parser'],
                'supervisor_photo' => $workerSnap['supervisor_photo'],
                'ps_photo' => $workerSnap['ps_photo'],
            ],
            'parser_running' => $running !== null,
            'daemon_enabled' => $parserState->status === ParserState::STATUS_RUNNING,
            'parser_state' => $parserState->status,
            'network_mode' => $parserState->network_mode,
            'lock_held' => $lockHeld,
            'worker_status' => $workersRunning > 0 ? 'running' : 'stopped',
            'current_job' => $running ? $this->formatJob($running) : null,
            'parser_queue_size' => $queueParser,
            'photos_queue_size' => $queuePhotos,
            'queue' => [
                'parser' => $queueParser,
                'default' => $queueDefault,
                'photos' => $queuePhotos,
                'total' => $queueParser + $queueDefault + $queuePhotos,
            ],
            'failed_jobs_count' => $failedJobs,
            'failed_jobs' => $failedJobs,
            'products_total' => Product::count(),
            'products_today' => Product::whereDate('parsed_at', today())->count(),
            'errors_today' => $errorsTodayProducts + $errorsTodayParserLogs,
            'errors_today_breakdown' => [
                'products_status_error' => $errorsTodayProducts,
                'parser_logs_error' => $errorsTodayParserLogs,
            ],
            'parser_lock_status' => $lockHeld ? 'held' : 'free',
            'memory_usage' => memory_get_usage(true),
            'last_errors' => $lastErrors,
            'error_frequency' => ['last_hour' => $errorsPerHour],
            'progress' => $progress,
            'warning' => $warning,
            'proxy_status' => $proxyOk ? 'ok' : 'failed',
            'proxy_probe_reason' => $proxyProbe['reason'] ?? null,
            'proxy_blocked' => $proxyState['blocked'],
            'proxy_blocked_until' => $proxyState['blocked_until'],
            'proxy_block_reason' => $proxyState['reason'],
            'proxy_block_streak' => $proxyState['streak'],
            'proxy_last_action' => $proxyState['last_action'],
            'proxy_last_url' => $proxyState['last_url'],
            'sadovodbaza_status' => $donorOk ? 'ok' : 'failed',
            'metrics' => $metrics,
        ]);
    }

    /**
     * GET /api/v1/parser/health
     */
    public function health(): JsonResponse
    {
        $parserState = ParserState::current();
        $queueParser = 0;
        $queuePhotos = 0;
        try {
            $conn = Queue::connection(config('queue.default'));
            $queueParser = (int) $conn->size('parser');
            $queuePhotos = (int) $conn->size('photos');
        } catch (\Throwable $e) {
            // ignore
        }

        $workersRunning = QueueWorkerDiagnostics::snapshot()['parser_workers'];

        $proxyProbe = $this->checkProxyAvailability(true);
        $proxyOk = $proxyProbe['proxy_ok'];
        $proxyState = ParserProxyState::snapshot();
        $donorOk = $this->checkDonorAvailability(
            $parserState->isRunning() && $parserState->network_mode === 'proxy'
        );

        return response()->json([
            'parser_state' => $parserState->status,
            'network_mode' => $parserState->network_mode,
            'queue_size' => [
                'parser' => $queueParser,
                'photos' => $queuePhotos,
                'total' => $queueParser + $queuePhotos,
            ],
            'workers' => $workersRunning,
            'proxy_status' => $proxyOk ? 'ok' : 'failed',
            'proxy_probe_reason' => $proxyProbe['reason'] ?? null,
            'proxy_blocked' => $proxyState['blocked'],
            'proxy_blocked_until' => $proxyState['blocked_until'],
            'proxy_block_reason' => $proxyState['reason'],
            'proxy_last_action' => $proxyState['last_action'],
            'sadovodbaza_status' => $donorOk ? 'ok' : 'failed',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/parser/stats
     * Aggregated stats for dashboard
     */
    public function stats(Request $request): JsonResponse
    {
        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        $lastCompleted = ParserJob::where('status', 'completed')->latest('finished_at')->first();
        $queueSize = 0;
        $queueParser = 0;
        $queuePhotos = 0;
        try {
            $conn = \Illuminate\Support\Facades\Queue::connection('redis');
            $queueParser = $conn->size('parser');
            $queuePhotos = $conn->size('photos');
            $queueSize = $queueParser + $queuePhotos;
        } catch (\Throwable $e) {
            // ignore
        }
        $productsToday = \App\Models\Product::whereDate('parsed_at', today())->count();
        $errProducts =
            (int) \App\Models\Product::where('status', 'error')
                ->whereDate('status_changed_at', today())
                ->count();
        $errLogs =
            (int) \App\Models\ParserLog::where('level', 'error')->whereDate('logged_at', today())->count();
        $errorsToday = $errProducts + $errLogs;

        return response()->json([
            'products_total' => \App\Models\Product::count(),
            'products_today' => $productsToday,
            'parser_running' => $running !== null,
            'queue_size' => $queueSize,
            'queue_parser_size' => $queueParser,
            'queue_photos_size' => $queuePhotos,
            'errors_today' => $errorsToday,
            'errors_today_breakdown' => [
                'products_status_error' => $errProducts,
                'parser_logs_error' => $errLogs,
            ],
            'last_parser_run' => $lastCompleted?->finished_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/parser/progress-overview
     */
    public function progressOverview(Request $request): JsonResponse
    {
        $jobId = $request->input('job_id');
        $query = ParserProgress::query()->latest('updated_at');
        if ($jobId) {
            $query->where('job_id', (int) $jobId);
        }
        $row = $query->first();

        return response()->json([
            'total_items' => $row?->total_items ?? 0,
            'processed_items' => $row?->processed_items ?? 0,
            'failed_items' => $row?->failed_items ?? 0,
            'current_url' => $row?->current_url,
            'speed_per_min' => (float) ($row?->speed_per_min ?? 0),
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/parser/jobs
     */
    public function jobs(Request $request): JsonResponse
    {
        $jobs = ParserJob::latest()
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $jobs->items(),
            'total' => $jobs->total(),
            'per_page' => $jobs->perPage(),
            'current_page' => $jobs->currentPage(),
            'last_page' => $jobs->lastPage(),
        ]);
    }

    /**
     * GET /api/v1/parser/jobs/{id}
     */
    public function jobDetail(int $id): JsonResponse
    {
        $job = ParserJob::findOrFail($id);
        return response()->json($this->formatJob($job, true));
    }

    /**
     * GET /api/v1/parser/progress  (SSE stream)
     * Поток обновлений статуса парсера
     */
    public function progress(Request $request): Response|StreamedResponse
    {
        $jobId = $request->input('job_id');

        return response()->stream(function () use ($jobId) {
            $iterations = 0;
            $maxIterations = 600; // 10 минут максимум

            while ($iterations < $maxIterations) {
                $query = ParserJob::query();
                if ($jobId) {
                    $query->where('id', $jobId);
                } else {
                    $query->whereIn('status', ['running', 'pending'])->latest();
                }
                $job = $query->first();

                if ($job) {
                    $data = json_encode($this->formatJob($job), JSON_UNESCAPED_UNICODE);
                    echo "data: {$data}\n\n";
                } else {
                    echo "data: {\"status\":\"idle\"}\n\n";
                }

                ob_flush();
                flush();

                if (!$job || $job->isFinished()) break;

                sleep(1);
                $iterations++;
            }

            echo "data: {\"status\":\"stream_ended\"}\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * POST /api/v1/parser/photos/download
     * Скачать фото для продуктов у которых photos_downloaded=false
     */
    public function downloadPhotos(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 50);
        $productId = $request->input('product_id');

        if ($productId) {
            $products = Product::where('id', $productId)->get();
        } else {
            $products = Product::where('photos_downloaded', false)
                ->where('photos_count', '>', 0)
                ->limit($limit)
                ->get();
        }

        if ($products->isEmpty()) {
            return response()->json(['message' => 'Нет фото для скачивания', 'count' => 0]);
        }

        $photoService = new PhotoDownloadService();
        $result = $photoService->downloadBatch($products);

        return response()->json([
            'message' => 'Скачивание завершено',
            'products' => $result['products'],
            'downloaded' => $result['downloaded'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
        ]);
    }

    private function detectParserWarning(?ParserJob $running, int $queueTotal): ?string
    {
        if ($running && $queueTotal > 200 && (int) $running->saved_products === 0) {
            $minutes = $running->started_at ? (int) now()->diffInMinutes($running->started_at) : 0;
            if ($minutes >= 3) {
                $orphanCount = 0;
                try {
                    $orphanCount = ParserLog::whereNull('job_id')
                        ->where('message', 'like', '%Орфанная%')
                        ->where('logged_at', '>=', now()->subHours(2))
                        ->count();
                } catch (\Throwable $e) {
                    // ignore
                }
                if ($orphanCount > 0) {
                    return 'Обнаружены орфанные задачи (ParserJob не найден). Выполните «Сброс системы», затем «Запустить».';
                }
                return 'Очередь большая, но товары не сохраняются. Возможны орфанные задачи. Рекомендуется: «Сброс системы» → «Запустить».';
            }
        }
        return null;
    }

    private function mergeStaleWorkerWarnings(?string $warning, bool $queueStalled, bool $photosStalled): ?string
    {
        if ($queueStalled) {
            $stallMsg = 'Очередь parser/default не обрабатывается: нет процессов queue:work для очереди parser (проверьте supervisor: parser-worker или `php artisan queue:work redis --queue=parser`).';
            $warning = $warning ? ($warning.' '.$stallMsg) : $stallMsg;
        }
        if ($photosStalled) {
            $pMsg = 'Очередь photos не обрабатывается: нет воркеров для очереди photos (photo-worker / `--queue=photos`).';
            $warning = $warning ? ($warning.' '.$pMsg) : $pMsg;
        }

        return $warning;
    }

    /**
     * Daemon is considered active only when parser_state is RUNNING.
     */
    private function isParserActive(): bool
    {
        return ParserState::current()->isRunning();
    }

    /**
     * @return array{proxy_ok: bool, reason: string, skipped?: bool}
     */
    private function checkProxyAvailability(bool $forcePrecheckWhenStopped = false): array
    {
        if (ParserProxyState::isBlocked()) {
            return ['proxy_ok' => false, 'reason' => 'proxy_blocked_cooldown_active'];
        }

        if (!$forcePrecheckWhenStopped && !$this->isParserActive()) {
            return ['proxy_ok' => false, 'reason' => 'parser_inactive_skipped', 'skipped' => true];
        }

        $proxyEnabled = (bool) config('parser.proxy_enabled', true);
        $proxyUrl = (string) (config('parser.proxy') ?: config('parser.proxy_url', ''));
        if (!$proxyEnabled || $proxyUrl === '') {
            return ['proxy_ok' => false, 'reason' => 'proxy_disabled_or_missing'];
        }

        $lastReason = 'error';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                Http::timeout(20)
                    ->withOptions([
                        'proxy' => $proxyUrl,
                        'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    ])
                    ->get('https://sadovodbaza.ru')
                    ->throw();
                return ['proxy_ok' => true, 'reason' => 'ok'];
            } catch (\Throwable $e) {
                $lastReason = $this->classifyNetworkError($e);
                if (str_contains($e->getMessage(), '429')) {
                    ParserProxyState::mark429('https://sadovodbaza.ru');
                }
                if ($attempt < 3) {
                    usleep($attempt * 500000);
                    continue;
                }
                ParserLogger::write('network_error', 'Proxy precheck failed after retries', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'reason' => $lastReason,
                    'url' => 'https://sadovodbaza.ru',
                ]);
            }
        }

        return ['proxy_ok' => false, 'reason' => $lastReason];
    }

    private function checkDonorAvailability(bool $useProxy = false): bool
    {
        try {
            $options = [
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ];
            if ($useProxy) {
                $proxyUrl = (string) (config('parser.proxy') ?: config('parser.proxy_url', ''));
                if ($proxyUrl === '') {
                    return false;
                }
                $options['proxy'] = $proxyUrl;
            }

            $response = Http::timeout(10)
                ->withOptions($options)
                ->get('https://sadovodbaza.ru');

            if (!$response->successful()) {
                return false;
            }

            return str_contains(mb_strtolower($response->body()), 'sadovodbaza');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function classifyNetworkError(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout') || str_contains($msg, 'curl error 28')) {
            return 'timeout';
        }
        if (str_contains($msg, 'connection refused') || str_contains($msg, 'curl error 7')) {
            return 'refused';
        }
        if (str_contains($msg, 'could not resolve') || str_contains($msg, 'curl error 6')) {
            return 'dns';
        }

        return 'error';
    }

    private function formatJob(ParserJob $job, bool $withLogs = false): array
    {
        $data = [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'options' => $job->options,
            'progress' => [
                'categories' => ['done' => $job->parsed_categories, 'total' => $job->total_categories],
                'products'   => ['done' => $job->parsed_products, 'total' => $job->total_products],
                'saved'      => $job->saved_products,
                'errors'     => $job->errors_count,
                'photos'     => ['downloaded' => $job->photos_downloaded, 'failed' => $job->photos_failed],
                'percent'    => $job->progress_percent,
                'current_action' => $job->current_action,
                'current_page'   => $job->current_page,
                'total_pages'    => $job->total_pages,
                'current_category' => $job->current_category_slug,
            ],
            'pid' => $job->pid,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'error_message' => $job->error_message,
            'created_at' => $job->created_at->toIso8601String(),
        ];

        if ($withLogs) {
            $data['logs'] = $job->logs()
                ->latest('logged_at')
                ->limit(100)
                ->get(['level', 'module', 'message', 'context', 'logged_at'])
                ->toArray();
        }

        return $data;
    }
}

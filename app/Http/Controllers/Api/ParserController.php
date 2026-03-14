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
use App\Services\DatabaseParserService;
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
        return response()->json([
            'status' => $ps->status,
            'locked' => $ps->locked,
            'last_start' => $ps->last_start?->toIso8601String(),
            'last_stop' => $ps->last_stop?->toIso8601String(),
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
            'queue_threshold' => ['nullable', 'integer', 'min:10', 'max:1000000'],
        ]);

        $settings = ParserSetting::current();
        $settings->update($validated);

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

        $options = [
            'categories'           => $request->input('categories', []),
            'linked_only'          => $request->boolean('linked_only', false),
            'products_per_category'=> (int) $request->input('products_per_category', 0),
            'max_pages'            => (int) $request->input('max_pages', 0),
            'no_details'           => $request->boolean('no_details', false),
            'save_photos'          => $request->boolean('save_photos', false),
            'save_to_db'           => $request->boolean('save_to_db', true),
            'category_slug'        => $request->input('category_slug'),
            'seller_slug'          => $request->input('seller_slug'),
        ];

        $type = $request->input('type', 'full');

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
        ParserState::current()->update([
            'status' => ParserState::STATUS_RUNNING,
            'last_start' => now(),
        ]);

        ParserDaemonJob::dispatch();

        return response()->json([
            'message' => 'Парсер запущен. Следующий прогон — через 60 сек после завершения текущего.',
            'daemon_enabled' => true,
        ], 201);
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
        $queuePhotos = 0;
        try {
            $conn = \Illuminate\Support\Facades\Queue::connection('redis');
            $queueParser = $conn->size('parser');
            $queuePhotos = $conn->size('photos');
        } catch (\Throwable $e) {
            // ignore
        }

        $warning = $this->detectParserWarning($running, $queueParser + $queuePhotos);

        $parserState = ParserState::current();
        return response()->json([
            'is_running'          => $running !== null,
            'daemon_enabled'      => $parserState->status === ParserState::STATUS_RUNNING,
            'parser_state'        => $parserState->status,
            'current_job'         => $running ? $this->formatJob($running) : null,
            'last_completed'      => $lastCompleted ? $this->formatJob($lastCompleted) : null,
            'queue_parser_size'   => $queueParser,
            'queue_photos_size'   => $queuePhotos,
            'queue_total_size'    => $queueParser + $queuePhotos,
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

        $workersRunning = 0;
        if (function_exists('shell_exec')) {
            $supervisor = (string) (@shell_exec('supervisorctl status 2>/dev/null') ?? '');
            if ($supervisor !== '') {
                foreach (preg_split('/\R/', $supervisor) as $line) {
                    if (str_contains($line, 'parser-worker') && str_contains($line, 'RUNNING')) {
                        $workersRunning++;
                    }
                }
            } else {
                $out = @shell_exec('ps aux 2>/dev/null | grep -E "artisan queue:work" | grep -v grep | wc -l');
                $workersRunning = (int) trim((string) ($out ?? '0'));
            }
        }

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

        $parserState = ParserState::current();
        return response()->json([
            'workers_running' => $workersRunning,
            'parser_running' => $running !== null,
            'daemon_enabled' => $parserState->status === ParserState::STATUS_RUNNING,
            'parser_state' => $parserState->status,
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
            'errors_today' => (int) Product::where('status', 'error')->whereDate('updated_at', today())->count()
                + (int) ParserLog::where('level', 'error')->whereDate('logged_at', today())->count(),
            'parser_lock_status' => $lockHeld ? 'held' : 'free',
            'memory_usage' => memory_get_usage(true),
            'last_errors' => $lastErrors,
            'error_frequency' => ['last_hour' => $errorsPerHour],
            'progress' => $progress,
            'warning' => $this->detectParserWarning($running, $queueParser + $queueDefault + $queuePhotos),
            'metrics' => $metrics,
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
        $errorsToday =
            (int) \App\Models\Product::where('status', 'error')->whereDate('updated_at', today())->count()
            + (int) \App\Models\ParserLog::where('level', 'error')->whereDate('logged_at', today())->count();

        return response()->json([
            'products_total' => \App\Models\Product::count(),
            'products_today' => $productsToday,
            'parser_running' => $running !== null,
            'queue_size' => $queueSize,
            'queue_parser_size' => $queueParser,
            'queue_photos_size' => $queuePhotos,
            'errors_today' => $errorsToday,
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

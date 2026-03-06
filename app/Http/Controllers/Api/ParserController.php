<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParserJob;
use App\Models\ParserLog;
use App\Models\Product;
use App\Models\Category;
use App\Jobs\ParserDaemonJob;
use App\Jobs\RunParserJob;
use App\Models\Setting;
use App\Services\DatabaseParserService;
use App\Services\PhotoDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParserController extends Controller
{
    /**
     * POST /api/v1/parser/start
     * Запустить парсинг в фоновом процессе
     */
    public function start(Request $request): JsonResponse
    {
        // Проверяем, нет ли уже работающего задания
        $running = ParserJob::where('status', 'running')->first();
        if ($running) {
            return response()->json([
                'error' => 'Парсер уже запущен',
                'job_id' => $running->id,
            ], 409);
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
     * Mark all running/pending jobs as stopped so workers stop processing.
     */
    public function stop(Request $request): JsonResponse
    {
        $updated = ParserJob::whereIn('status', ['running', 'pending'])
            ->update(['status' => 'stopped', 'finished_at' => now()]);

        try {
            \Illuminate\Support\Facades\Redis::del('parser_running');
        } catch (\Throwable $e) {
            // ignore if Redis not available
        }

        return response()->json([
            'message' => $updated > 0 ? 'Парсер остановлен' : 'Нет активных заданий',
            'jobs_stopped' => $updated,
        ]);
    }

    /**
     * POST /api/v1/parser/start-daemon
     * Start continuous parser: runs full parse, then repeats 60 sec after each run completes.
     */
    public function startDaemon(Request $request): JsonResponse
    {
        Setting::updateOrCreate(
            ['key' => 'parser_daemon_enabled'],
            ['value' => '1', 'group' => 'parser', 'type' => 'bool']
        );

        ParserDaemonJob::dispatch();

        return response()->json([
            'message' => 'Непрерывный парсер запущен. Следующий прогон — через 60 сек после завершения текущего.',
            'daemon_enabled' => true,
        ], 201);
    }

    /**
     * POST /api/v1/parser/stop-daemon
     * Stop continuous parser (disables auto-restart).
     */
    public function stopDaemon(Request $request): JsonResponse
    {
        Setting::updateOrCreate(
            ['key' => 'parser_daemon_enabled'],
            ['value' => '0', 'group' => 'parser', 'type' => 'bool']
        );

        return response()->json([
            'message' => 'Непрерывный парсер отключён. Текущий прогон будет завершён.',
            'daemon_enabled' => false,
        ]);
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

        return response()->json([
            'is_running'          => $running !== null,
            'daemon_enabled'      => (bool) Setting::get('parser_daemon_enabled', false),
            'current_job'         => $running ? $this->formatJob($running) : null,
            'last_completed'      => $lastCompleted ? $this->formatJob($lastCompleted) : null,
            'queue_parser_size'   => $queueParser,
            'queue_photos_size'   => $queuePhotos,
            'queue_total_size'    => $queueParser + $queuePhotos,
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
        $errorsToday = \App\Models\Product::where('status', 'error')->whereDate('updated_at', today())->count();

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

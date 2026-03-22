<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AiMetricsController;
use App\Http\Controllers\Api\CategorySyncController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExcludedController;
use App\Http\Controllers\Api\FilterController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\ParserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\SaasApiKeyController;
use App\Http\Controllers\Api\SaasSearchController;
use App\Http\Controllers\Api\SystemProductController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

// =====================================================================
// HEALTH — public, no auth (monitoring)
// =====================================================================
Route::prefix('v1')->group(function () {
    Route::get('/up', function () {
        try {
            DB::connection()->getPdo();
            if (config('queue.default') === 'redis') {
                Redis::ping();
            }
            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 503);
        }
    });
    Route::get('ws-status', function () {
        $redis = 'failed';
        try {
            Redis::connection()->ping();
            $redis = 'connected';
        } catch (\Throwable $e) {
            $redis = 'failed';
        }

        $reverb = 'stopped';
        try {
            $port = (int) (config('reverb.servers.reverb.port') ?? env('REVERB_SERVER_PORT', 8080));
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                $reverb = 'running';
            } elseif (function_exists('shell_exec')) {
                $ps = trim((string) shell_exec('ps aux | grep reverb | grep -v grep'));
                $reverb = $ps !== '' ? 'running' : 'stopped';
            }
        } catch (\Throwable $e) {
            $reverb = 'stopped';
        }

        $queueWorkers = 0;
        try {
            if (function_exists('shell_exec')) {
                $out = @shell_exec('ps aux 2>/dev/null | grep -E "artisan queue:work" | grep -v grep | wc -l');
                $queueWorkers = (int) trim((string) ($out ?? '0'));
            }
        } catch (\Throwable $e) {
            $queueWorkers = 0;
        }

        return response()->json([
            'reverb' => $reverb,
            'queue_workers' => $queueWorkers,
            'redis' => $redis,
        ]);
    });
    Route::get('system/status', function () {
        $redis = 'failed';
        try {
            Redis::connection()->ping();
            $redis = 'connected';
        } catch (\Throwable $e) {
            $redis = 'failed';
        }

        $reverb = 'stopped';
        try {
            $port = (int) (config('reverb.servers.reverb.port') ?? env('REVERB_SERVER_PORT', 8080));
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                $reverb = 'running';
            } elseif (function_exists('shell_exec')) {
                $ps = trim((string) shell_exec('ps aux | grep reverb | grep -v grep'));
                $reverb = $ps !== '' ? 'running' : 'stopped';
            }
        } catch (\Throwable $e) {
            $reverb = 'stopped';
        }

        $queueWorkers = 0;
        try {
            if (function_exists('shell_exec')) {
                $out = @shell_exec('ps aux 2>/dev/null | grep -E "artisan queue:work" | grep -v grep | wc -l');
                $queueWorkers = (int) trim((string) ($out ?? '0'));
            }
        } catch (\Throwable $e) {
            $queueWorkers = 0;
        }

        $queueSize = 0;
        try {
            $conn = \Illuminate\Support\Facades\Queue::connection(config('queue.default'));
            $queueSize = (int) $conn->size('parser') + (int) $conn->size('photos') + (int) $conn->size('default');
        } catch (\Throwable $e) {
            $queueSize = 0;
        }

        $now = now();
        $parserRunning = \App\Models\ParserJob::whereIn('status', ['running', 'pending'])->exists();
        $productsTotal = \App\Models\Product::count();
        $productsToday = \App\Models\Product::whereDate('parsed_at', today())->count();
        $productErrorsToday = \App\Models\Product::where('status', 'error')->whereDate('updated_at', today())->count();
        $parserErrorsToday = \App\Models\ParserLog::where('level', 'error')->whereDate('logged_at', today())->count();
        $errorsToday = (int) $productErrorsToday + (int) $parserErrorsToday;

        $errors15m = 0;
        $errors1h = 0;
        $errors24h = 0;
        $errorReasons1h = [];
        $errorReasons = [];
        $recentErrorLogs = [];
        try {
            $productErrors15m = \App\Models\Product::where('status', 'error')
                ->where('updated_at', '>=', $now->copy()->subMinutes(15))
                ->count();
            $parserErrors15m = \App\Models\ParserLog::where('level', 'error')
                ->where('logged_at', '>=', $now->copy()->subMinutes(15))
                ->count();
            $errors15m = (int) $productErrors15m + (int) $parserErrors15m;

            $productErrors1h = \App\Models\Product::where('status', 'error')
                ->where('updated_at', '>=', $now->copy()->subHour())
                ->count();
            $parserErrors1h = \App\Models\ParserLog::where('level', 'error')
                ->where('logged_at', '>=', $now->copy()->subHour())
                ->count();
            $errors1h = (int) $productErrors1h + (int) $parserErrors1h;

            $productErrors24h = \App\Models\Product::where('status', 'error')
                ->where('updated_at', '>=', $now->copy()->subDay())
                ->count();
            $parserErrors24h = \App\Models\ParserLog::where('level', 'error')
                ->where('logged_at', '>=', $now->copy()->subDay())
                ->count();
            $errors24h = (int) $productErrors24h + (int) $parserErrors24h;

            $errorReasons1h = \App\Models\ParserLog::query()
                ->selectRaw('module, message, COUNT(*) as cnt, MAX(logged_at) as last_seen')
                ->where('level', 'error')
                ->where('logged_at', '>=', $now->copy()->subHour())
                ->groupBy('module', 'message')
                ->orderByDesc('cnt')
                ->limit(8)
                ->get()
                ->map(function ($row) use ($now) {
                    $lastSeen = \Illuminate\Support\Carbon::parse($row->last_seen);
                    return [
                        'module' => (string) $row->module,
                        'message' => mb_substr((string) $row->message, 0, 220),
                        'count' => (int) $row->cnt,
                        'last_seen' => $lastSeen->toIso8601String(),
                        'age_minutes' => $lastSeen->diffInMinutes($now),
                        'is_active_15m' => $lastSeen->greaterThanOrEqualTo($now->copy()->subMinutes(15)),
                    ];
                })
                ->values()
                ->all();

            $errorReasons = \App\Models\ParserLog::query()
                ->selectRaw('module, message, COUNT(*) as cnt, MAX(logged_at) as last_seen')
                ->where('level', 'error')
                ->where('logged_at', '>=', $now->copy()->subDay())
                ->groupBy('module', 'message')
                ->orderByDesc('cnt')
                ->limit(8)
                ->get()
                ->map(function ($row) use ($now) {
                    $lastSeen = \Illuminate\Support\Carbon::parse($row->last_seen);
                    return [
                        'module' => (string) $row->module,
                        'message' => mb_substr((string) $row->message, 0, 220),
                        'count' => (int) $row->cnt,
                        'last_seen' => $lastSeen->toIso8601String(),
                        'age_minutes' => $lastSeen->diffInMinutes($now),
                        'is_active_15m' => $lastSeen->greaterThanOrEqualTo($now->copy()->subMinutes(15)),
                    ];
                })
                ->values()
                ->all();

            $recentErrorLogs = \App\Models\ParserLog::query()
                ->where('level', 'error')
                ->latest('logged_at')
                ->limit(10)
                ->get(['id', 'module', 'message', 'logged_at'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'module' => (string) $row->module,
                    'message' => mb_substr((string) $row->message, 0, 260),
                    'logged_at' => optional($row->logged_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            // ignore additional diagnostics
        }
        $lastJob = \App\Models\ParserJob::where('status', 'completed')->latest('finished_at')->first();
        $lastParserRun = $lastJob?->finished_at?->toIso8601String();

        $cpuLoad = '—';
        if (function_exists('sys_getloadavg')) {
            $la = @sys_getloadavg();
            $cpuLoad = $la ? implode(' ', array_map(fn ($v) => round($v, 2), $la)) : '—';
        }

        $memoryUsage = '—';
        if (is_readable('/proc/meminfo')) {
            $mem = @file_get_contents('/proc/meminfo');
            if ($mem && preg_match('/MemTotal:\s*(\d+)/', $mem, $mt) && preg_match('/MemAvailable:\s*(\d+)/', $mem, $ma)) {
                $total = (int) $mt[1];
                $avail = (int) $ma[1];
                $used = $total - $avail;
                $memoryUsage = round($used / 1024) . 'M / ' . round($total / 1024) . 'M';
            }
        }

        $diskUsedGb = 0.0;
        $diskTotalGb = 0.0;
        if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
            $totalBytes = @disk_total_space('/');
            $freeBytes = @disk_free_space('/');
            if ($totalBytes !== false && $freeBytes !== false) {
                $diskTotalGb = round($totalBytes / (1024 ** 3), 2);
                $diskUsedGb = round(($totalBytes - $freeBytes) / (1024 ** 3), 2);
            }
        }

        $parserMetrics = [];
        try {
            $parserMetrics = \App\Services\ParserMetricsService::getMetrics();
        } catch (\Throwable $e) {
            // ignore
        }

        $parserWarning = null;
        try {
            $running = \App\Models\ParserJob::whereIn('status', ['running', 'pending'])->latest()->first();
            if ($running && $queueSize > 200 && (int) $running->saved_products === 0) {
                $orphanCount = \App\Models\ParserLog::whereNull('job_id')
                    ->where('message', 'like', '%Орфанная%')
                    ->where('logged_at', '>=', now()->subHours(2))
                    ->count();
                if ($orphanCount > 0) {
                    $parserWarning = 'Обнаружены орфанные задачи. Выполните «Сброс системы», затем «Запустить».';
                } elseif ($running->started_at && now()->diffInMinutes($running->started_at) >= 3) {
                    $parserWarning = 'Очередь большая, товары не сохраняются. Рекомендуется: «Сброс системы» → «Запустить».';
                }
            }
        } catch (\Throwable $e) {
            // ignore — do not break system/status
        }

        return response()->json(array_merge([
            'parser_running' => $parserRunning,
            'queue_workers' => $queueWorkers,
            'queue_size' => $queueSize,
            'products_total' => $productsTotal,
            'products_today' => $productsToday,
            'errors_today' => $errorsToday,
            'last_parser_run' => $lastParserRun,
            'redis_status' => $redis,
            'websocket' => $reverb,
            'cpu_load' => $cpuLoad,
            'memory_usage' => $memoryUsage,
            'disk' => [
                'used' => $diskUsedGb,
                'total' => $diskTotalGb,
            ],
            'error_metrics' => [
                'today_total' => $errorsToday,
                'today_products' => (int) $productErrorsToday,
                'today_parser_logs' => (int) $parserErrorsToday,
                'last_15m' => $errors15m,
                'last_1h' => $errors1h,
                'last_24h' => $errors24h,
            ],
            'error_reasons_last_1h' => $errorReasons1h,
            'error_reasons' => $errorReasons,
            'recent_error_logs' => $recentErrorLogs,
            'timestamp' => now()->toIso8601String(),
            'parser_warning' => $parserWarning,
        ], $parserMetrics));
    });
    Route::get('/health', function () {
        $db = 'failed';
        $redis = 'failed';
        $queue = 'failed';

        try {
            DB::connection()->getPdo();
            DB::connection()->getDatabaseName();
            $db = 'ok';
        } catch (\Throwable $e) {
            // ignored
        }

        try {
            Redis::ping();
            $redis = 'ok';
        } catch (\Throwable $e) {
            // ignored
        }

        try {
            $q = Queue::connection(config('queue.default'));
            $q->size('default');
            $queue = 'ok';
        } catch (\Throwable $e) {
            // ignored
        }

        $status = ($db === 'ok' && $redis === 'ok' && $queue === 'ok') ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status,
            'db' => $db,
            'queue' => $queue,
            'redis' => $redis,
        ]);
    });

    Route::get('system/health', function () {
        $database = false;
        $redis = false;
        $queue = false;
        $queueSizes = ['parser' => 0, 'photos' => 0, 'default' => 0];

        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (\Throwable $e) {
            $database = false;
        }

        try {
            Redis::ping();
            $redis = true;
        } catch (\Throwable $e) {
            $redis = false;
        }

        try {
            $q = Queue::connection(config('queue.default'));
            $queueSizes = [
                'parser' => (int) $q->size('parser'),
                'photos' => (int) $q->size('photos'),
                'default' => (int) $q->size('default'),
            ];
            $queue = true;
        } catch (\Throwable $e) {
            $queue = false;
        }

        $hasReverbCredentials =
            !empty(env('REVERB_APP_KEY')) &&
            !empty(env('REVERB_APP_SECRET')) &&
            !empty(env('REVERB_APP_ID'));
        $broadcastDriver = (string) config('broadcasting.default', 'log');
        $broadcastHealthy = $broadcastDriver === 'log' || $hasReverbCredentials;

        $parserSettings = null;
        $parserState = null;
        try {
            $parserSettings = \App\Models\ParserSetting::current();
            $parserState = \App\Models\ParserState::current();
        } catch (\Throwable $e) {
            // ignore parser details when unavailable
        }

        return response()->json([
            'status' => ($database && $redis && $queue && $broadcastHealthy) ? 'ok' : 'degraded',
            'database' => $database ? 'connected' : 'failed',
            'redis' => $redis ? 'connected' : 'failed',
            'queue' => [
                'status' => $queue ? 'ok' : 'failed',
                'sizes' => $queueSizes,
            ],
            'broadcast' => [
                'driver' => $broadcastDriver,
                'reverb_credentials' => $hasReverbCredentials ? 'present' : 'missing',
                'safe_fallback' => $broadcastHealthy ? 'ok' : 'failed',
            ],
            'parser' => [
                'state' => $parserState?->status,
                'proxy_enabled' => (bool) ($parserSettings?->proxy_enabled ?? false),
                'proxy_url' => $parserSettings?->proxy_url,
                'timeout_seconds' => (int) ($parserSettings?->timeout_seconds ?? 0),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    });
});

// =====================================================================
// PUBLIC API — без авторизации (для пользовательских страниц Cheepy)
// =====================================================================
Route::prefix('v1/public')->group(function () {
    Route::get('menu', [PublicController::class, 'menu']);
    Route::get('categories/{slug}/products', [PublicController::class, 'categoryProducts']);
    Route::get('products/{externalId}', [PublicController::class, 'product']);
    Route::get('sellers/{slug}', [PublicController::class, 'seller']);
    Route::get('search', [PublicController::class, 'search']);
    Route::get('featured', [PublicController::class, 'featured']);
});

// SaasSearchController not deployed
// Route::prefix('v1')->middleware('saas.api')->group(function () {
//     Route::get('search', [SaasSearchController::class, 'index']);
// });

Route::prefix('v1')->group(function () {
    Route::get('payments/{id}', [\App\Http\Controllers\Api\PaymentStatusController::class, 'show']);
    Route::post('api-keys', [SaasApiKeyController::class, 'store']);
    Route::post('webhook/stripe', [SaasApiKeyController::class, 'stripeWebhook']);
    Route::post('webhook/tinkoff', [SaasApiKeyController::class, 'tinkoffWebhook'])->middleware('throttle:60,1');
    Route::post('webhook/sber', [SaasApiKeyController::class, 'sberWebhook'])->middleware('throttle:60,1');
    Route::post('webhook/atol', [SaasApiKeyController::class, 'atolWebhook']);
});

// =====================================================================
// AUTH
// =====================================================================
Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware(JwtMiddleware::class)->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

// =====================================================================
// ADMIN API — требует JWT
// =====================================================================
Route::prefix('v1')->middleware(JwtMiddleware::class)->group(function () {

    // Dashboard (shared)
    Route::get('dashboard', [DashboardController::class, 'index']);
    // Route::get('admin/ai/metrics', [AiMetricsController::class, 'index']); // not deployed

    // -----------------------------------------------------------------
    // PARSER (Admin Panel) — /api/v1/admin/parser
    // Products (donor), Sellers (donor), Parser controls.
    // CRM MUST NOT call these.
    // -----------------------------------------------------------------
    Route::prefix('admin/parser')->group(function () {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{id}', [ProductController::class, 'show']);
        Route::patch('products/{id}', [ProductController::class, 'update']);
        Route::delete('products/{id}', [ProductController::class, 'destroy']);
        Route::post('products/bulk', [ProductController::class, 'bulk']);

        Route::get('sellers', [SellerController::class, 'index']);
        Route::get('sellers/{slug}/products', [SellerController::class, 'products']);
        Route::get('sellers/{slug}', [SellerController::class, 'show']);
        Route::patch('sellers/{id}', [SellerController::class, 'update']);

        Route::get('status', [ParserController::class, 'status']);
        Route::get('state', [ParserController::class, 'state']);
        Route::get('settings', [ParserController::class, 'settings']);
        Route::post('settings', [ParserController::class, 'updateSettings']);
        Route::get('stats', [ParserController::class, 'stats']);
        Route::get('diagnostics', [ParserController::class, 'diagnostics']);
        Route::get('health', [ParserController::class, 'health']);
        Route::get('progress-overview', [ParserController::class, 'progressOverview']);
        Route::get('progress', [ParserController::class, 'progress']);
        Route::get('jobs', [ParserController::class, 'jobs']);
        Route::get('jobs/{id}', [ParserController::class, 'jobDetail']);
        Route::post('start', [ParserController::class, 'start']);
        Route::post('start-daemon', [ParserController::class, 'startDaemon']);
        Route::post('stop', [ParserController::class, 'stop']);
        Route::post('stop-daemon', [ParserController::class, 'stopDaemon']);
        Route::post('pause', [ParserController::class, 'pause']);
        Route::post('restart', [ParserController::class, 'restart']);
        Route::post('queue-clear', [ParserController::class, 'queueClear']);
        Route::post('clear-queue', [ParserController::class, 'queueClear']);
        Route::post('queue-restart', [ParserController::class, 'queueRestart']);
        Route::post('restart-workers', [ParserController::class, 'queueRestart']);
        Route::post('queue-flush', [ParserController::class, 'queueFlush']);
        Route::post('clear-failed', [ParserController::class, 'clearFailedJobs']);
        Route::get('failed-jobs', [ParserController::class, 'failedJobs']);
        Route::post('retry-job/{id}', [ParserController::class, 'retryJob']);
        Route::post('kill-stuck', [ParserController::class, 'killStuck']);
        Route::post('release-lock', [ParserController::class, 'releaseLock']);
        Route::post('reset', [ParserController::class, 'reset']);
        Route::post('photos/download', [ParserController::class, 'downloadPhotos']);
        Route::post('categories/sync', CategorySyncController::class);
    });

    // -----------------------------------------------------------------
    // CRM — /api/v1/crm
    // System products, future system-sellers.
    // Parser panel MUST NOT use these.
    // -----------------------------------------------------------------
    Route::prefix('crm')->group(function () {
        // SystemProductController not deployed
        // Route::get('system-products', [SystemProductController::class, 'index']);
        // Route::post('system-products', [SystemProductController::class, 'store']);
        // Route::post('system-products/create-from-donor', [SystemProductController::class, 'createFromDonor']);
        // Route::get('system-products/{id}', [SystemProductController::class, 'show']);
        // Route::patch('system-products/{id}', [SystemProductController::class, 'update']);
        // Route::delete('system-products/{id}', [SystemProductController::class, 'destroy']);
        Route::get('api-keys', [SaasApiKeyController::class, 'index']);
        Route::get('api-keys/{id}', [SaasApiKeyController::class, 'show']);
        Route::patch('api-keys/{id}', [SaasApiKeyController::class, 'update']);
        Route::post('api-keys/{id}/balance', [SaasApiKeyController::class, 'addBalance']);
        Route::post('api-keys/{id}/checkout', [SaasApiKeyController::class, 'checkout']);
        Route::post('webhook/replay/{id}', [SaasApiKeyController::class, 'webhookReplay']);
        Route::get('payment-providers', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'index']);
        Route::get('webhook-logs', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'allLogs']);
        Route::get('payment-alerts', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'paymentAlerts']);
        Route::get('payment-providers/{name}', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'show']);
        Route::patch('payment-providers/{name}', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'update']);
        Route::post('payment-providers/{name}/test', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'test']);
        Route::post('payment-providers/{name}/test-payment', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'createTestPayment']);
        Route::get('payment-providers/{name}/logs', [\App\Http\Controllers\Api\CrmPaymentProviderController::class, 'logs']);
    });

    // Attribute Rules & Synonyms
    Route::prefix('attribute-rules')->group(function () {
        Route::get('audit',            [\App\Http\Controllers\Api\AttributeRuleController::class, 'audit']);
        Route::get('synonyms',         [\App\Http\Controllers\Api\AttributeRuleController::class, 'synonymsIndex']);
        Route::post('synonyms',        [\App\Http\Controllers\Api\AttributeRuleController::class, 'synonymsStore']);
        Route::delete('synonyms/{id}', [\App\Http\Controllers\Api\AttributeRuleController::class, 'synonymsDestroy']);
        Route::post('test',            [\App\Http\Controllers\Api\AttributeRuleController::class, 'test']);
        Route::post('rebuild',         [\App\Http\Controllers\Api\AttributeRuleController::class, 'rebuild']);
        Route::get('/',                [\App\Http\Controllers\Api\AttributeRuleController::class, 'index']);
        Route::post('/',               [\App\Http\Controllers\Api\AttributeRuleController::class, 'store']);
        Route::patch('{id}',           [\App\Http\Controllers\Api\AttributeRuleController::class, 'update']);
        Route::delete('{id}',          [\App\Http\Controllers\Api\AttributeRuleController::class, 'destroy']);
    });

    // Attribute Dictionary
    Route::prefix('attribute-dictionary')->group(function () {
        Route::get('/',        [\App\Http\Controllers\Api\AttributeRuleController::class, 'dictionaryIndex']);
        Route::post('/',       [\App\Http\Controllers\Api\AttributeRuleController::class, 'dictionaryStore']);
        Route::patch('{id}',   [\App\Http\Controllers\Api\AttributeRuleController::class, 'dictionaryUpdate']);
        Route::delete('{id}',  [\App\Http\Controllers\Api\AttributeRuleController::class, 'dictionaryDestroy']);
    });

    // Attribute Canonical Normalization
    Route::prefix('attribute-canonical')->group(function () {
        Route::get('/',        [\App\Http\Controllers\Api\AttributeRuleController::class, 'canonicalIndex']);
        Route::post('/',       [\App\Http\Controllers\Api\AttributeRuleController::class, 'canonicalStore']);
        Route::patch('{id}',   [\App\Http\Controllers\Api\AttributeRuleController::class, 'canonicalUpdate']);
        Route::delete('{id}',  [\App\Http\Controllers\Api\AttributeRuleController::class, 'canonicalDestroy']);
    });

    // Attribute Facets (catalog filter data)
    Route::prefix('attribute-facets')->group(function () {
        Route::get('/',        [\App\Http\Controllers\Api\AttributeRuleController::class, 'facets']);
        Route::post('rebuild', [\App\Http\Controllers\Api\AttributeRuleController::class, 'facetsRebuild']);
    });

    // Categories (shared — parser config, catalog mapping)
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('{id}', [CategoryController::class, 'show']);
        Route::patch('{id}', [CategoryController::class, 'update']);
        Route::post('reorder', [CategoryController::class, 'reorder']);
        Route::get('{id}/filters', [CategoryController::class, 'availableFilters']);
    });

    // Brands
    Route::prefix('brands')->group(function () {
        Route::get('/', [BrandController::class, 'index']);
        Route::get('{id}', [BrandController::class, 'show']);
        Route::post('/', [BrandController::class, 'store']);
        Route::put('{id}', [BrandController::class, 'update']);
        Route::delete('{id}', [BrandController::class, 'destroy']);
    });

    // Excluded rules
    Route::prefix('excluded')->group(function () {
        Route::get('/', [ExcludedController::class, 'index']);
        Route::post('/', [ExcludedController::class, 'store']);
        Route::put('{id}', [ExcludedController::class, 'update']);
        Route::delete('{id}', [ExcludedController::class, 'destroy']);
        Route::post('test', [ExcludedController::class, 'test']);
    });

    // Filters config
    Route::prefix('filters')->group(function () {
        Route::get('/', [FilterController::class, 'index']);
        Route::post('/', [FilterController::class, 'store']);
        Route::put('{id}', [FilterController::class, 'update']);
        Route::delete('{id}', [FilterController::class, 'destroy']);
        Route::get('{categoryId}/values', [FilterController::class, 'values']);
    });

    // Logs
    Route::prefix('logs')->group(function () {
        Route::get('/', [LogController::class, 'index']);
        Route::delete('clear', [LogController::class, 'clear']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::put('/', [SettingController::class, 'update']);
        Route::put('{key}', [SettingController::class, 'updateOne']);
    });

    // Catalog Phase 1 — dual category system (CATALOG_ARCHITECTURE_V2)
    require base_path('routes/admin_catalog.php');

    // Admin System Products — CRM (system_products)
    require base_path('routes/admin_system_products.php');
});

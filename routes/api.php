<?php

use App\Http\Controllers\Api\AuthController;
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
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Middleware\AdminRoleMiddleware;
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
            $queueSize = (int) \Illuminate\Support\Facades\Queue::connection(config('queue.default'))->size('default');
        } catch (\Throwable $e) {
            $queueSize = 0;
        }

        $parserRunning = \App\Models\ParserJob::whereIn('status', ['running', 'pending'])->exists();
        $productsTotal = \App\Models\Product::count();
        $productsToday = \App\Models\Product::whereDate('parsed_at', today())->count();
        $errorsToday = \App\Models\Product::where('status', 'error')->whereDate('updated_at', today())->count();
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

        $parserMetrics = [];
        try {
            $parserMetrics = \App\Services\ParserMetricsService::getMetrics();
        } catch (\Throwable $e) {
            // ignore
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
            'timestamp' => now()->toIso8601String(),
        ], $parserMetrics));
    });
    Route::get('/health', function () {
        $status = 'ok';
        $db = false;
        $redis = false;
        $parserLastRun = null;
        try {
            DB::connection()->getPdo();
            DB::connection()->getDatabaseName();
            $db = true;
        } catch (\Throwable $e) {
            $status = 'degraded';
        }
        try {
            Redis::ping();
            $redis = true;
        } catch (\Throwable $e) {
            $status = 'degraded';
        }
        $lastJob = \App\Models\ParserJob::where('status', 'completed')->latest('finished_at')->first();
        if ($lastJob) {
            $parserLastRun = $lastJob->finished_at?->toIso8601String();
        }
        return response()->json([
            'status' => $status,
            'database' => $db ? 'connected' : 'disconnected',
            'redis' => $redis ? 'connected' : 'disconnected',
            'parser_last_run' => $parserLastRun,
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

    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index']);

// System monitoring (dashboard)
    Route::get('system/status', function () {
        $redisStatus = 'disconnected';
        try {
            \Illuminate\Support\Facades\Redis::ping();
            $redisStatus = 'connected';
        } catch (\Throwable $e) {}

        $reverb = 'stopped';
        try {
            $port = (int) (config('reverb.servers.reverb.port') ?? env('REVERB_SERVER_PORT', 8080));
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                $reverb = 'running';
            } elseif (function_exists('shell_exec')) {
                $ps = trim((string) @shell_exec('ps aux | grep reverb | grep -v grep'));
                $reverb = $ps !== '' ? 'running' : 'stopped';
            }
        } catch (\Throwable $e) {}

        $queueWorkers = 0;
        try {
            if (function_exists('shell_exec')) {
                $out = trim((string) @shell_exec('ps aux 2>/dev/null | grep -E "queue:work" | grep -v grep | wc -l'));
                $queueWorkers = (int) $out;
            }
        } catch (\Throwable $e) {}

        $queueSize = 0;
        try {
            $queueSize = \Illuminate\Support\Facades\Queue::connection('redis')->size('default');
        } catch (\Throwable $e) {}

        $running = \App\Models\ParserJob::whereIn('status', ['running', 'pending'])->exists();
        $lastJob = \App\Models\ParserJob::where('status', 'completed')->latest('finished_at')->first();
        $lastParserRun = $lastJob?->finished_at?->toIso8601String();

        $productsTotal = \App\Models\Product::count();
        $productsToday = \App\Models\Product::whereDate('parsed_at', today())->count();
        $errorsToday = \App\Models\Product::where('status', 'error')->whereDate('updated_at', today())->count();

        $cpuLoad = '—';
        if (function_exists('sys_getloadavg')) {
            $la = @sys_getloadavg();
            $cpuLoad = $la ? implode(' ', array_map(fn($v) => round($v, 2), $la)) : '—';
        }

        $memoryUsage = '—';
        if (is_readable('/proc/meminfo')) {
            $m = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $m, $mt);
            preg_match('/MemAvailable:\s+(\d+)/', $m, $ma);
            if ($mt && $ma) {
                $total = (int)$mt[1] / 1024;
                $avail = (int)$ma[1] / 1024;
                $used = round($total - $avail);
                $memoryUsage = $used . 'M / ' . round($total) . 'M';
            }
        }

        return response()->json([
            'parser_running' => $running,
            'queue_workers' => $queueWorkers,
            'queue_size' => $queueSize,
            'products_total' => $productsTotal,
            'products_today' => $productsToday,
            'errors_today' => $errorsToday,
            'last_parser_run' => $lastParserRun,
            'redis_status' => $redisStatus,
            'websocket' => $reverb,
            'cpu_load' => $cpuLoad,
            'memory_usage' => $memoryUsage,
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Parser
    Route::prefix('parser')->group(function () {
        Route::get('status', [ParserController::class, 'status']);
        Route::get('stats', [ParserController::class, 'stats']);
        Route::get('progress', [ParserController::class, 'progress']);
        Route::get('jobs', [ParserController::class, 'jobs']);
        Route::get('jobs/{id}', [ParserController::class, 'jobDetail']);
        Route::post('start', [ParserController::class, 'start']);
        Route::post('stop', [ParserController::class, 'stop']);
        Route::post('photos/download', [ParserController::class, 'downloadPhotos']);
        Route::post('categories/sync', CategorySyncController::class);
    });

    // Products
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('{id}', [ProductController::class, 'show']);
        Route::patch('{id}', [ProductController::class, 'update']);
        Route::delete('{id}', [ProductController::class, 'destroy']);
        Route::post('bulk', [ProductController::class, 'bulk']);
    });

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('{id}', [CategoryController::class, 'show']);
        Route::patch('{id}', [CategoryController::class, 'update']);
        Route::post('reorder', [CategoryController::class, 'reorder']);
        Route::get('{id}/filters', [CategoryController::class, 'availableFilters']);
    });

    // Sellers
    Route::prefix('sellers')->group(function () {
        Route::get('/', [SellerController::class, 'index']);
        Route::get('{slug}', [SellerController::class, 'show']);
        Route::get('{slug}/products', [SellerController::class, 'products']);
        Route::patch('{id}', [SellerController::class, 'update']);
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

    // Admin Users and Roles (requires users.manage)
    Route::middleware(AdminRoleMiddleware::class)->prefix('admin')->group(function () {
        Route::get('users', [AdminUserController::class, 'index']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::put('users/{id}', [AdminUserController::class, 'update']);
        Route::delete('users/{id}', [AdminUserController::class, 'destroy']);
        Route::get('roles', [AdminRoleController::class, 'index']);
        Route::post('roles', [AdminRoleController::class, 'store']);
        Route::put('roles/{id}', [AdminRoleController::class, 'update']);
        Route::delete('roles/{id}', [AdminRoleController::class, 'destroy']);
    });
});

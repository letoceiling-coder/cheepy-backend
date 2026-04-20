<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ParserJob;
use App\Models\ParserLog;
use App\Models\ParserSetting;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard
     */
    public function index(): JsonResponse
    {
        $totalProducts = Product::count();
        $activeProducts = Product::where('status', 'active')->count();
        $hiddenProducts = Product::where('status', 'hidden')->count();
        // Раньше считали по parsed_at: в режиме «только новые» (update_existing=false) товары,
        // которые уже в БД, skip-ятся без upsert, parsed_at не меняется — счётчик всегда 0,
        // хотя в фоне парсер по факту прошёл все категории. Правильная семантика «Новые сегодня»
        // — это товары, реально созданные за сегодня, т.е. по created_at.
        $newToday = Product::whereDate('created_at', today())->count();
        $productsErrorAllTime = Product::where('status', 'error')->count();
        $errorsTodayProducts = Product::where('status', 'error')
            ->whereDate('status_changed_at', today())
            ->count();
        $errorsTodayParserLogs = ParserLog::where('level', 'error')->whereDate('logged_at', today())->count();
        $errorsToday = $errorsTodayProducts + $errorsTodayParserLogs;
        $withPhotos = Product::where('photos_downloaded', true)->count();
        $pendingPhotos = Product::where('photos_count', '>', 0)->where('photos_downloaded', false)->count();

        $totalCategories = Category::count();
        $enabledCategories = Category::where('enabled', true)->count();
        $linkedCategories = Category::where('linked_to_parser', true)->count();

        // Сколько категорий реально пойдёт в полный прогон парсера сейчас.
        // Источник правды — parser_settings.default_category_ids (UI «Категории для полного режима»).
        // Если пусто — fallback на enabled категории (поведение buildFromSettings).
        $parserSetting = ParserSetting::current();
        $selectedIds = is_array($parserSetting->default_category_ids ?? null) ? $parserSetting->default_category_ids : [];
        $selectedForParser = count($selectedIds) > 0
            ? Category::whereIn('id', $selectedIds)->where('enabled', true)->count()
            : $enabledCategories;

        $totalSellers = Seller::count();
        $activeSellers = Seller::where('status', 'active')->count();

        $lastJob = ParserJob::where('status', 'completed')->latest('finished_at')->first();
        // Align with /system/status and /admin/parser/status: pending is still an active run.
        $runningJob = ParserJob::whereIn('status', ['running', 'pending'])->first();

        $recentLogs = ParserLog::with('job:id,type,status')
            ->latest('logged_at')
            ->limit(10)
            ->get(['id', 'job_id', 'level', 'module', 'message', 'logged_at']);

        // Статистика за последние 7 дней
        $weeklyStats = Product::select(
            DB::raw('DATE(parsed_at) as date'),
            DB::raw('COUNT(*) as count')
        )
        ->whereNotNull('parsed_at')
        ->where('parsed_at', '>=', now()->subDays(7))
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->keyBy('date')
        ->map(fn($r) => $r->count);

        // Топ категорий по числу товаров
        $topCategories = Category::select('id', 'name', 'slug', 'products_count')
            ->orderByDesc('products_count')
            ->limit(5)
            ->get();

        return response()->json([
            'products' => [
                'total' => $totalProducts,
                'active' => $activeProducts,
                'hidden' => $hiddenProducts,
                'new_today' => $newToday,
                /** Same formula as /parser/diagnostics & /system/status — errors today, not lifetime error rows */
                'errors' => $errorsToday,
                'errors_all_time_products' => $productsErrorAllTime,
                'with_photos' => $withPhotos,
                'pending_photos' => $pendingPhotos,
            ],
            'categories' => [
                'total' => $totalCategories,
                'enabled' => $enabledCategories,
                'linked_to_parser' => $linkedCategories,
                // Сколько реально парсится при полном прогоне сейчас (с учётом фильтра в настройках).
                'selected_for_parser' => $selectedForParser,
            ],
            'sellers' => [
                'total' => $totalSellers,
                'active' => $activeSellers,
            ],
            'parser' => [
                'is_running' => $runningJob !== null,
                'current_job' => $runningJob ? [
                    'id' => $runningJob->id,
                    'status' => $runningJob->status,
                    'current_action' => $runningJob->current_action,
                    'saved_products' => $runningJob->saved_products,
                    'progress_percent' => $runningJob->progress_percent,
                ] : null,
                'last_run_at' => $lastJob?->finished_at?->toIso8601String(),
                'last_run_saved' => $lastJob?->saved_products,
            ],
            'weekly_stats' => $weeklyStats,
            'top_categories' => $topCategories,
            'recent_logs' => $recentLogs,
        ]);
    }
}

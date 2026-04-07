<?php

namespace App\Providers;

use App\Events\CatalogCategoryCreated;
use App\Events\CatalogCategoryDeleted;
use App\Events\CatalogCategoryUpdated;
use App\Events\CatalogMappingCreated;
use App\Events\ParserFinished;
use App\Listeners\LogCatalogCategoryCreated;
use App\Listeners\LogCatalogCategoryDeleted;
use App\Listeners\LogCatalogCategoryUpdated;
use App\Listeners\LogCatalogMappingCreated;
use App\Listeners\ReleaseParserLockOnFinished;
use App\Listeners\ScheduleNextParserDaemon;
use App\Models\DonorCategory;
use App\Observers\DonorCategoryObserver;
use App\Models\ParserSetting;
use App\Services\SadovodParser\HttpClient;
use App\Support\ParserJobOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Production merge: keep existing listeners + DonorCategory::observe for auto-mapping jobs.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HttpClient::class, function () {
            $cfg = ParserJobOptions::runtimePayload(ParserSetting::current())['http_client'];

            return new HttpClient($cfg);
        });
    }

    public function boot(): void
    {
        Event::listen(ParserFinished::class, ReleaseParserLockOnFinished::class);
        Event::listen(ParserFinished::class, ScheduleNextParserDaemon::class);

        Event::listen(CatalogCategoryCreated::class, LogCatalogCategoryCreated::class);
        Event::listen(CatalogCategoryUpdated::class, LogCatalogCategoryUpdated::class);
        Event::listen(CatalogCategoryDeleted::class, LogCatalogCategoryDeleted::class);
        Event::listen(CatalogMappingCreated::class, LogCatalogMappingCreated::class);

        DonorCategory::observe(DonorCategoryObserver::class);

        if (filter_var(env('AUDIT_PRODUCT_SQL_STATUS_ERROR', true), FILTER_VALIDATE_BOOL)) {
            DB::listen(function ($query) {
                $sql = $query->sql;
                $trim = strtolower(ltrim($sql));
                if (str_starts_with($trim, 'select')) {
                    return;
                }
                if (!str_contains(strtolower($sql), 'products')) {
                    return;
                }
                $hasErrorToken = str_contains(strtolower($sql), 'error')
                    || in_array('error', array_map(static fn ($b) => is_string($b) ? strtolower($b) : $b, $query->bindings), true);
                if (!$hasErrorToken) {
                    return;
                }

                Log::critical('SQL ERROR WRITE DETECTED', [
                    'sql' => $sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                    'trace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10), 0, 10),
                ]);
            });
        }
    }
}

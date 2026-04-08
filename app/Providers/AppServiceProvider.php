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
use App\Models\Product;
use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use App\Observers\DonorCategoryObserver;
use App\Observers\ProductObserver;
use App\Observers\SystemProductAttributeObserver;
use App\Observers\SystemProductObserver;
use App\Services\SadovodParser\HttpClient;
use App\Services\SadovodParser\Parsers\MenuParser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HttpClient::class, fn () => new HttpClient(config('sadovod', [])));
        $this->app->singleton(MenuParser::class, function ($app) {
            return new MenuParser(
                $app->make(HttpClient::class),
                config('sadovod', [])
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ParserFinished::class, ReleaseParserLockOnFinished::class);
        Event::listen(ParserFinished::class, ScheduleNextParserDaemon::class);

        // Catalog Phase 1 — dual category logging
        Event::listen(CatalogCategoryCreated::class, LogCatalogCategoryCreated::class);
        Event::listen(CatalogCategoryUpdated::class, LogCatalogCategoryUpdated::class);
        Event::listen(CatalogCategoryDeleted::class, LogCatalogCategoryDeleted::class);
        Event::listen(CatalogMappingCreated::class, LogCatalogMappingCreated::class);

        DonorCategory::observe(DonorCategoryObserver::class);
        Product::observe(ProductObserver::class);
        SystemProduct::observe(SystemProductObserver::class);
        SystemProductAttribute::observe(SystemProductAttributeObserver::class);
    }
}

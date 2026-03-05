<?php

namespace App\Providers;

use App\Services\SadovodParser\HttpClient;
use App\Services\SadovodParser\Parsers\MenuParser;
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
        //
    }
}

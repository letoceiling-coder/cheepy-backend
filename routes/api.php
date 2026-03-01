<?php

use App\Http\Controllers\Api\AuthController;
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
use Illuminate\Support\Facades\Route;

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

    // Parser
    Route::prefix('parser')->group(function () {
        Route::get('status', [ParserController::class, 'status']);
        Route::get('progress', [ParserController::class, 'progress']);
        Route::get('jobs', [ParserController::class, 'jobs']);
        Route::get('jobs/{id}', [ParserController::class, 'jobDetail']);
        Route::post('start', [ParserController::class, 'start']);
        Route::post('stop', [ParserController::class, 'stop']);
        Route::post('photos/download', [ParserController::class, 'downloadPhotos']);
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
});

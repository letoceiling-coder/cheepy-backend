<?php

use App\Http\Controllers\Admin\Catalog\CatalogCategoryController;
use App\Http\Controllers\Admin\Catalog\CategoryMappingController;
use App\Http\Controllers\Admin\Catalog\DonorCategoryController;
use App\Http\Controllers\Admin\Catalog\MappingSuggestionController;
use Illuminate\Support\Facades\Route;

/*
| Catalog Phase 1 — Admin API (CATALOG_ARCHITECTURE_V2).
| Mount under api/v1 with JWT. Full paths: /api/v1/admin/catalog/*
*/

Route::prefix('admin/catalog')->group(function () {
    Route::get('categories', [CatalogCategoryController::class, 'index']);
    Route::post('categories', [CatalogCategoryController::class, 'store']);
    Route::patch('categories/{id}', [CatalogCategoryController::class, 'update']);
    Route::delete('categories/{id}', [CatalogCategoryController::class, 'destroy']);

    Route::get('donor-categories', [DonorCategoryController::class, 'index']);

    Route::get('category-mapping', [CategoryMappingController::class, 'index']);
    Route::post('category-mapping', [CategoryMappingController::class, 'store']);
    Route::delete('category-mapping/{id}', [CategoryMappingController::class, 'destroy']);

    Route::get('mapping/suggestions', [MappingSuggestionController::class, 'index']);
});

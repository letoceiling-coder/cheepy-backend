<?php

use App\Http\Controllers\Admin\Cms\CmsPageController;
use Illuminate\Support\Facades\Route;

/*
| CRM / admin CMS — под JWT. Пути: /api/v1/admin/cms/*
*/
Route::prefix('admin/cms')->group(function () {
    Route::get('pages', [CmsPageController::class, 'index']);
    Route::post('pages', [CmsPageController::class, 'store']);
    Route::get('pages/{id}', [CmsPageController::class, 'show'])->whereNumber('id');
    Route::patch('pages/{id}', [CmsPageController::class, 'update'])->whereNumber('id');
    Route::post('pages/{id}/publish', [CmsPageController::class, 'publish'])->whereNumber('id');
    Route::get('pages/{pageId}/versions/{versionId}', [CmsPageController::class, 'showVersion'])
        ->whereNumber('pageId')
        ->whereNumber('versionId');
    Route::put('pages/{pageId}/versions/{versionId}/blocks', [CmsPageController::class, 'syncBlocks'])
        ->whereNumber('pageId')
        ->whereNumber('versionId');
});

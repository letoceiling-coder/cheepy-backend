<?php

use App\Http\Controllers\Admin\Constructor\ConstructorLayoutTemplateController;
use Illuminate\Support\Facades\Route;

/*
| Шаблоны конструктора витрины — под JWT. Пути: /api/v1/admin/constructor/layout-templates
*/
Route::prefix('admin/constructor/layout-templates')->group(function () {
    Route::get('/', [ConstructorLayoutTemplateController::class, 'index']);
    Route::post('/', [ConstructorLayoutTemplateController::class, 'store']);
    Route::get('{id}', [ConstructorLayoutTemplateController::class, 'show'])->whereNumber('id');
    Route::put('{id}/blocks', [ConstructorLayoutTemplateController::class, 'syncBlocks'])->whereNumber('id');
    Route::delete('{id}', [ConstructorLayoutTemplateController::class, 'destroy'])->whereNumber('id');
});

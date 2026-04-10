<?php

use App\Http\Controllers\Api\SystemProductController;
use Illuminate\Support\Facades\Route;

/*
| Admin System Products — CRM products (system_products)
| Mount under api/v1 with JWT. Full paths: /api/v1/admin/system-products
|
| GET    /api/v1/admin/system-products
| GET    /api/v1/admin/system-products/{id}
| PATCH  /api/v1/admin/system-products/{id}/moderate  — только статус (модерация)
| PATCH  /api/v1/admin/system-products/{id}           — карточка каталога (без статуса)
| POST   /api/v1/admin/system-products/create-from-donor
*/

Route::prefix('admin')->group(function () {
    Route::post('system-products/create-from-donor', [SystemProductController::class, 'createFromDonor']);
    Route::patch('system-products/{id}/moderate', [SystemProductController::class, 'moderate'])->whereNumber('id');
    Route::patch('system-products/{id}/crm-attributes', [SystemProductController::class, 'syncCrmAttributes'])->whereNumber('id');
    Route::patch('system-products/{id}/crm-photos', [SystemProductController::class, 'syncCrmPhotos'])->whereNumber('id');
    Route::get('system-products', [SystemProductController::class, 'index']);
    Route::get('system-products/{id}', [SystemProductController::class, 'show'])->whereNumber('id');
    Route::patch('system-products/{id}', [SystemProductController::class, 'update'])->whereNumber('id');
});

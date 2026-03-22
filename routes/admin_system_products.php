<?php

use App\Http\Controllers\Api\SystemProductController;
use Illuminate\Support\Facades\Route;

/*
| Admin System Products — CRM products (system_products)
| Mount under api/v1 with JWT. Full paths: /api/v1/admin/system-products
|
| GET    /api/v1/admin/system-products
| GET    /api/v1/admin/system-products?status=pending
| GET    /api/v1/admin/system-products/{id}
| PATCH  /api/v1/admin/system-products/{id}
*/

Route::prefix('admin')->group(function () {
    Route::get('system-products', [SystemProductController::class, 'index']);
    Route::get('system-products/{id}', [SystemProductController::class, 'show'])->whereNumber('id');
    Route::patch('system-products/{id}', [SystemProductController::class, 'update'])->whereNumber('id');
});

<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

// ======================================================
// Веб-каталог (онлайн отображение как в старом sadavod)
// ======================================================

Route::get('/', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/product/{externalId}', [CatalogController::class, 'product'])->name('catalog.product');

// Алиас для совместимости со старыми URL /?product=ID
Route::get('/p', function (\Illuminate\Http\Request $request) {
    $id = $request->input('product') ?? $request->input('id');
    if ($id) {
        return redirect()->route('catalog.product', ['externalId' => $id]);
    }
    $cat = $request->input('category');
    return redirect($cat ? '/?category=' . $cat : '/');
});

// Health check
Route::get('/up', fn() => response('OK', 200));

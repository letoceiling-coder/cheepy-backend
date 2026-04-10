<?php

use App\Http\Controllers\Api\CrmMediaController;
use Illuminate\Support\Facades\Route;

/*
| CRM Media Library — /api/v1/admin/media/*
| JWT group applied in api.php.
*/

Route::prefix('admin/media')->group(function () {
    Route::get('folders', [CrmMediaController::class, 'folders']);
    Route::post('folders', [CrmMediaController::class, 'storeFolder']);
    Route::patch('folders/{id}', [CrmMediaController::class, 'updateFolder'])->whereNumber('id');
    Route::delete('folders/{id}', [CrmMediaController::class, 'destroyFolder'])->whereNumber('id');

    Route::get('files', [CrmMediaController::class, 'files']);
    Route::post('files', [CrmMediaController::class, 'upload']);
    Route::post('files/move', [CrmMediaController::class, 'moveFiles']);
    Route::post('files/{id}/restore', [CrmMediaController::class, 'restore'])->whereNumber('id');
    Route::post('trash/empty', [CrmMediaController::class, 'emptyTrash']);
});

<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PoolController;
use Illuminate\Support\Facades\Route;

/*
| REST API, mounted at /api. Authenticated with personal access tokens
| (Authorization: Bearer jog_…) and rate limited to 120 requests/minute per token.
*/
Route::prefix('v1')->middleware(['api.token', 'throttle:api'])->group(function () {
    Route::get('/me', MeController::class)->name('api.me');

    Route::get('/pools', [PoolController::class, 'index'])->name('api.pools.index');
    Route::get('/pools/{pool}', [PoolController::class, 'show'])->name('api.pools.show');
    Route::middleware('api.role:operator')->group(function () {
        Route::post('/pools', [PoolController::class, 'store'])->name('api.pools.store');
        Route::patch('/pools/{pool}', [PoolController::class, 'update'])->name('api.pools.update');
    });
});

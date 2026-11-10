<?php

use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PoolController;
use App\Http\Controllers\JobLogController;
use Illuminate\Support\Facades\Route;

/*
| REST API, mounted at /api. Authenticated with personal access tokens
| (Authorization: Bearer jog_…) and rate limited to 120 requests/minute per token.
*/
Route::prefix('v1')->middleware(['api.token', 'throttle:api'])->group(function () {
    Route::get('/me', MeController::class)->name('api.me');

    Route::get('/jobs', [JobController::class, 'index'])->name('api.jobs.index');
    Route::get('/jobs/{job}', [JobController::class, 'show'])->name('api.jobs.show');
    Route::get('/jobs/{job}/logs', [JobLogController::class, 'download'])->name('api.jobs.logs');

    Route::get('/pools', [PoolController::class, 'index'])->name('api.pools.index');
    Route::get('/pools/{pool}', [PoolController::class, 'show'])->name('api.pools.show');
    Route::middleware('api.role:operator')->group(function () {
        Route::post('/pools', [PoolController::class, 'store'])->name('api.pools.store');
        Route::patch('/pools/{pool}', [PoolController::class, 'update'])->name('api.pools.update');
    });
});

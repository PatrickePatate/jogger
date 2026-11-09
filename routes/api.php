<?php

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
| REST API, mounted at /api. Authenticated with personal access tokens
| (Authorization: Bearer jog_…) and rate limited to 120 requests/minute per token.
*/
Route::prefix('v1')->middleware(['api.token', 'throttle:api'])->group(function () {
    Route::get('/me', MeController::class)->name('api.me');
});

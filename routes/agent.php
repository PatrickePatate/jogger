<?php

use App\Http\Controllers\Agent\BroadcastAuthController;
use App\Http\Controllers\Agent\CommandsController;
use App\Http\Controllers\Agent\EnrollController;
use App\Http\Controllers\Agent\EventsController;
use App\Http\Controllers\Agent\HeartbeatController;
use App\Http\Controllers\Agent\LogsController;
use App\Http\Controllers\Agent\MeController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

/*
| Agent API (/agent/v1). Outside the `web` group: no session or CSRF. Enrollment is authenticated by the
| single-use token in the body; everything else by the host credential.
*/
Route::prefix('agent/v1')->group(function () {
    Route::post('/enroll', EnrollController::class)->middleware('throttle:20,1')->name('agent.enroll');

    Route::middleware(AuthenticateAgent::class)->group(function () {
        Route::post('/heartbeat', HeartbeatController::class)->name('agent.heartbeat');
        Route::get('/commands', [CommandsController::class, 'index'])->name('agent.commands');
        Route::post('/commands/{command}/ack', [CommandsController::class, 'ack'])->name('agent.commands.ack');
        Route::post('/broadcasting/auth', BroadcastAuthController::class)->name('agent.broadcasting.auth');
        Route::post('/events', EventsController::class)->name('agent.events');
        Route::post('/runners/{runner}/logs', LogsController::class)->whereUuid('runner')->name('agent.runners.logs');
        Route::get('/me', MeController::class)->name('agent.me');
    });
});

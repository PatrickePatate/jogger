<?php

use App\Events\Pinged;
use App\Http\Controllers\Auth\GithubLoginController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\GithubManifestController;
use App\Http\Middleware\LocalOnly;
use App\Http\Middleware\RedirectIfSetupComplete;
use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
use App\Livewire\Github\Connect;
use App\Livewire\Github\Deliveries;
use App\Livewire\Github\Installations;
use App\Livewire\Settings\Security;
use App\Livewire\Settings\Tokens;
use App\Livewire\Setup\FirstRun;
use Illuminate\Support\Facades\Route;

Route::get('/setup', FirstRun::class)->middleware(RedirectIfSetupComplete::class)->name('setup');

Route::prefix('auth/github')->group(function () {
    Route::get('/redirect', [GithubLoginController::class, 'redirect'])->middleware('guest')->name('auth.github.redirect');
    // Registered with GitHub as the callback URL; also used by the signed-in "link GitHub" flow.
    Route::get('/callback', [GithubLoginController::class, 'callback'])->name('auth.github.callback');
    Route::get('/link', [GithubLoginController::class, 'linkRedirect'])->middleware('auth')->name('auth.github.link');
    Route::get('/confirm', [GithubLoginController::class, 'confirm'])->middleware('guest')->name('auth.github.confirm');
    Route::post('/confirm', [GithubLoginController::class, 'confirmStore'])->middleware(['guest', 'throttle:20,1'])->name('auth.github.confirm.store');
});

Route::middleware('signed')->group(function () {
    Route::get('/invitations/{invitation}', [InvitationController::class, 'show'])->name('invitations.accept');
    Route::post('/invitations/{invitation}', [InvitationController::class, 'store'])->name('invitations.accept.store');
});

Route::middleware('auth')->group(function () {
    Route::view('/', 'overview')->name('overview');
    Route::get('/settings/security', Security::class)->name('settings.security');
    Route::get('/settings/tokens', Tokens::class)->name('settings.tokens');

    Route::middleware('can:admin')->prefix('admin')->group(function () {
        Route::get('/users', Users::class)->name('admin.users');
        Route::get('/teams', Teams::class)->name('admin.teams');
    });

    Route::middleware('can:admin')->prefix('github')->group(function () {
        Route::get('/', Connect::class)->name('github.connect');
        Route::get('/installations', Installations::class)->name('github.installations');
        Route::get('/deliveries', Deliveries::class)->name('github.deliveries');
        Route::post('/manifest/redirect', [GithubManifestController::class, 'redirect'])->name('github.manifest.redirect');
        Route::get('/manifest/callback', [GithubManifestController::class, 'callback'])->name('github.manifest.callback');
    });
});

Route::view('/_ui', 'dev.ui')->middleware(LocalOnly::class)->name('dev.ui');

Route::post('/_ui/ping', function () {
    Pinged::dispatch('Ping from '.request()->userAgent());

    return response()->noContent();
})->middleware(LocalOnly::class)->name('dev.ping');

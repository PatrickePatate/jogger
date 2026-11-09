<?php

use App\Http\Controllers\Webhooks\GithubWebhookController;
use App\Http\Middleware\DeduplicateDelivery;
use App\Http\Middleware\VerifyGithubSignature;
use Illuminate\Support\Facades\Route;

/*
| Inbound webhooks. Deliberately outside the `web` group: no session, cookies or CSRF, and no first-run
| redirect; every request is authenticated by its HMAC signature instead.
*/
Route::post('/webhooks/github', GithubWebhookController::class)
    ->middleware([VerifyGithubSignature::class, DeduplicateDelivery::class])
    ->name('webhooks.github');

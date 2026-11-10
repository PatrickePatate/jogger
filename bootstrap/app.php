<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\RejectBrowserRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequireTokenRole;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Inbound webhooks: no session/CSRF middleware at all (see routes/webhooks.php).
            Route::middleware(RejectBrowserRequests::class)->group(base_path('routes/webhooks.php'));
            // Agent API: bearer credentials, no session.
            Route::middleware(RejectBrowserRequests::class)->group(base_path('routes/agent.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [EnsureSetupComplete::class]);
        // Token auth must run before the rate limiter so limits are per token, not per IP.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateApiToken::class);
        $middleware->alias([
            'api.token' => AuthenticateApiToken::class,
            'api.role' => RequireTokenRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiError::handles($request) || $request->expectsJson(),
        );

        // Expired or tampered signed links (e.g. invitations) get a friendly page instead of a bare 403.
        $exceptions->render(
            fn (InvalidSignatureException $e, Request $request) => $request->expectsJson() || ApiError::handles($request)
                ? null
                : response()->view('auth.invitation-invalid', [], 403),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiError::handles($request)
                ? ApiError::render($e, $request)
                : null,
        );
    })->create();

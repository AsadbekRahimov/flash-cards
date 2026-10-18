<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TwaAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook/*',
        ]);

        $middleware->append(SecurityHeaders::class);

        // Laravel's priority list runs `throttle:*` before any middleware it does not
        // know, so the TWA limiters would see no `twa` attribute and key every learner
        // by IP. Authenticate first: per-learner limits (twa-api, twa-review, twa-promo…)
        // then really are per learner.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: TwaAuth::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();

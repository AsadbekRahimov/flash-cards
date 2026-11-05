<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TwaAuth;
use App\Support\Surface;
use App\Support\SurfacePackageManifest;
use App\Support\TrustedProxies;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
        // The web routes depend on the surface (App\Support\Surface): the `api` container serves /api/*, /up and
        // /health/ready only. The surface comes from the process environment or .env, and .env is read by a kernel
        // bootstrapper that runs AFTER this file - so the choice is made inside the callback, which the booting
        // RouteServiceProvider runs, and not when this file is loaded. The order is the framework's own: api.php, /up,
        // then these, as `web:` would have been.
        then: static function (): void {
            Route::middleware('web')->group(__DIR__.'/../routes/health.php');

            if (! Surface::isApi()) {
                Route::middleware('web')->group(__DIR__.'/../routes/web.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: [
            'telegram/webhook/*',
            'billing/webhook/*',
        ]);

        $middleware->append(SecurityHeaders::class);

        // Behind nginx as an HTTP proxy (the Octane mode of app-api) the client address and scheme arrive in X-Forwarded-*.
        // The proxies that may send them come from config/trustedproxy.php (TRUSTED_PROXIES), which the global TrustProxies
        // middleware reads on every request when none are named here; the header mask has no config key, so it is set
        // here - without X-Forwarded-Prefix, which nginx hands through from the client (App\Support\TrustedProxies).
        $middleware->trustProxies(headers: TrustedProxies::HEADERS);

        // Laravel's priority list runs `throttle:*` before any middleware it does not
        // know, so the TWA limiters would see no `twa` attribute and key every learner
        // by IP. Authenticate first: per-learner limits (twa-api, twa-review, twa-promo…)
        // then really are per learner.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: TwaAuth::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();

// The package manifest says which discovered package providers and aliases get registered (bootstrap/cache/packages.php
// is one file for both surfaces, written at `composer install`). Application::registerBaseBindings() bound the stock one
// as a singleton; it is replaced here, right after the application is built and before anything bootstraps - nobody has
// resolved it yet: RegisterProviders and RegisterFacades read it when the kernel bootstraps the first request or command,
// and that is also when the surface-aware subclass reads APP_SURFACE, after .env. On `full` it answers as the stock one.
$app->singleton(PackageManifest::class, static fn (): PackageManifest => new SurfacePackageManifest(
    new Filesystem,
    $app->basePath(),
    $app->getCachedPackagesPath(),
));

return $app;

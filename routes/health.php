<?php

declare(strict_types=1);

use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\ReadinessThrottle;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Readiness probe for monitoring and the deploy smoke check: stateless (no session/cookies), throttled.
// Loaded on BOTH surfaces (bootstrap/app.php): the `app-api` container (APP_SURFACE=api, no web routes) answers it too.
Route::get('/health/ready', ReadinessController::class)
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware(ReadinessThrottle::class)
    ->name('health.ready');

<?php

declare(strict_types=1);

use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Middleware\TelegramIpAllowlist;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => view('welcome'));

// Readiness probe for monitoring: stateless (no session/cookies), throttled.
Route::get('/health/ready', ReadinessController::class)
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('health.ready');

Route::post('/telegram/webhook/{secret}', WebhookController::class)
    ->middleware(TelegramIpAllowlist::class)
    ->where('secret', '[A-Za-z0-9\-_]{16,128}')
    ->name('telegram.webhook');

Route::middleware(['web', 'auth'])->prefix('2fa')->name('2fa.')->group(function (): void {
    Route::get('/setup', [TwoFactorController::class, 'setup'])->name('setup');
    Route::post('/setup', [TwoFactorController::class, 'confirm'])->name('confirm');
    Route::get('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('recovery-codes');
    Route::get('/challenge', [TwoFactorController::class, 'challenge'])->name('challenge');
    Route::post('/challenge', [TwoFactorController::class, 'verify'])->name('verify');
});

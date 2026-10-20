<?php

declare(strict_types=1);

use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Middleware\StaticPageCsp;
use App\Http\Middleware\TelegramIpAllowlist;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public site (landing, offer, privacy): static pages, so no session / cookies / CSRF.
// `/page` negotiates the language from Accept-Language (ru default); `/{locale}/page` is explicit.
Route::withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->middleware(StaticPageCsp::class)
    ->group(function (): void {
        $locales = implode('|', array_map(preg_quote(...), (array) config('localization.supported', ['ru'])));

        Route::get('/', [PublicSiteController::class, 'home'])->name('site.home');
        Route::get('/offer', [PublicSiteController::class, 'offer'])->name('site.offer');
        Route::get('/privacy', [PublicSiteController::class, 'privacy'])->name('site.privacy');

        Route::get('/{locale}', [PublicSiteController::class, 'home'])->where('locale', $locales)->name('site.home.localized');
        Route::get('/{locale}/offer', [PublicSiteController::class, 'offer'])->where('locale', $locales)->name('site.offer.localized');
        Route::get('/{locale}/privacy', [PublicSiteController::class, 'privacy'])->where('locale', $locales)->name('site.privacy.localized');
    });

// Readiness probe for monitoring: stateless (no session/cookies), throttled.
Route::get('/health/ready', ReadinessController::class)
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('health.ready');

Route::post('/telegram/webhook/{secret}', WebhookController::class)
    ->middleware(TelegramIpAllowlist::class)
    ->where('secret', '[A-Za-z0-9\-_]{16,128}')
    ->name('telegram.webhook');

// Shared by `/admin` (guard `web`) and the `/org` cabinet (guard `org`): the first guard that is
// logged in becomes the request's default, so Auth::user() is the right account in the controller.
Route::middleware(['web', 'auth:web,org'])->prefix('2fa')->name('2fa.')->group(function (): void {
    Route::get('/setup', [TwoFactorController::class, 'setup'])->name('setup');
    Route::post('/setup', [TwoFactorController::class, 'confirm'])->name('confirm');
    Route::get('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('recovery-codes');
    Route::get('/challenge', [TwoFactorController::class, 'challenge'])->name('challenge');
    Route::post('/challenge', [TwoFactorController::class, 'verify'])->name('verify');
});

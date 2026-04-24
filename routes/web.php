<?php

declare(strict_types=1);

use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Middleware\TelegramIpAllowlist;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

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

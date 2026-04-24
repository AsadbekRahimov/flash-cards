<?php

declare(strict_types=1);

use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Middleware\TelegramIpAllowlist;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::post('/telegram/webhook/{secret}', WebhookController::class)
    ->middleware(TelegramIpAllowlist::class)
    ->where('secret', '[A-Za-z0-9\-_]{16,128}')
    ->name('telegram.webhook');

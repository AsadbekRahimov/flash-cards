<?php

declare(strict_types=1);

use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::post('/telegram/webhook/{secret}', WebhookController::class)
    ->where('secret', '[A-Za-z0-9\-_]{16,128}')
    ->name('telegram.webhook');

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Twa\AuthController;
use App\Http\Controllers\Api\Twa\MeController;
use App\Http\Controllers\Api\Twa\TrainingController;
use App\Http\Middleware\TwaAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('twa')->group(function (): void {
    Route::post('/auth', AuthController::class)
        ->middleware('throttle:twa-auth')
        ->name('twa.auth');

    Route::middleware([TwaAuth::class, 'throttle:twa-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('twa.me');

        Route::prefix('training/sessions/{session}')->whereNumber('session')->group(function (): void {
            Route::post('/start', [TrainingController::class, 'start'])->name('twa.training.start');
            Route::get('/next', [TrainingController::class, 'next'])->name('twa.training.next');
            Route::post('/review', [TrainingController::class, 'review'])
                ->middleware('throttle:twa-review')
                ->name('twa.training.review');
        });
    });
});

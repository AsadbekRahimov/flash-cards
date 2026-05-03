<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Twa\AuthController;
use App\Http\Controllers\Api\Twa\ExamController;
use App\Http\Controllers\Api\Twa\MeController;
use App\Http\Controllers\Api\Twa\TrainingController;
use App\Http\Controllers\Api\Twa\TtsController;
use App\Http\Middleware\TwaAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('twa')->group(function (): void {
    Route::post('/auth', AuthController::class)
        ->middleware('throttle:twa-auth')
        ->name('twa.auth');

    Route::middleware([TwaAuth::class, 'throttle:twa-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('twa.me');
        Route::get('/tts', TtsController::class)
            ->middleware('throttle:twa-tts')
            ->name('twa.tts');

        Route::prefix('training/sessions/{session}')->whereNumber('session')->group(function (): void {
            Route::post('/start', [TrainingController::class, 'start'])->name('twa.training.start');
            Route::get('/next', [TrainingController::class, 'next'])->name('twa.training.next');
            Route::post('/review', [TrainingController::class, 'review'])
                ->middleware('throttle:twa-review')
                ->name('twa.training.review');
        });

        Route::prefix('exam/sessions/{session}')->whereNumber('session')->group(function (): void {
            Route::post('/join', [ExamController::class, 'join'])->name('twa.exam.join');
            Route::get('/question', [ExamController::class, 'question'])->name('twa.exam.question');
            Route::post('/answer', [ExamController::class, 'answer'])
                ->middleware('throttle:twa-exam-answer')
                ->name('twa.exam.answer');
            Route::get('/result', [ExamController::class, 'result'])->name('twa.exam.result');
        });
    });
});

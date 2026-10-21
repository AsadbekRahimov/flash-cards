<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Twa\AssignmentController;
use App\Http\Controllers\Api\Twa\AuthController;
use App\Http\Controllers\Api\Twa\BillingController;
use App\Http\Controllers\Api\Twa\ChallengeController;
use App\Http\Controllers\Api\Twa\CourseController;
use App\Http\Controllers\Api\Twa\DeckController;
use App\Http\Controllers\Api\Twa\ExamController;
use App\Http\Controllers\Api\Twa\FriendController;
use App\Http\Controllers\Api\Twa\LeagueController;
use App\Http\Controllers\Api\Twa\MeController;
use App\Http\Controllers\Api\Twa\MockExamController;
use App\Http\Controllers\Api\Twa\OrganizationController;
use App\Http\Controllers\Api\Twa\PromoController;
use App\Http\Controllers\Api\Twa\ReferralController;
use App\Http\Controllers\Api\Twa\SettingsController;
use App\Http\Controllers\Api\Twa\TodayController;
use App\Http\Controllers\Api\Twa\TrainingController;
use App\Http\Controllers\Api\Twa\TtsController;
use App\Http\Middleware\SetTwaLocale;
use App\Http\Middleware\TwaAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('twa')->group(function (): void {
    Route::post('/auth', AuthController::class)
        ->middleware([SetTwaLocale::class, 'throttle:twa-auth'])
        ->name('twa.auth');

    Route::middleware([TwaAuth::class, SetTwaLocale::class, 'throttle:twa-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('twa.me');
        Route::patch('/me/settings', [SettingsController::class, 'update'])->name('twa.me.settings');
        Route::post('/me/settings/timezone/revert', [SettingsController::class, 'revertTimezone'])->name('twa.me.settings.timezone.revert');
        Route::get('/me/organizations', [OrganizationController::class, 'index'])->name('twa.me.organizations');
        Route::delete('/me/organizations/{member}', [OrganizationController::class, 'leave'])
            ->whereNumber('member')
            ->middleware('throttle:twa-organizations')
            ->name('twa.me.organizations.leave');
        Route::get('/today', TodayController::class)->name('twa.today');
        Route::get('/assignments', AssignmentController::class)->name('twa.assignments');
        Route::get('/referrals', ReferralController::class)->name('twa.referrals');
        Route::get('/league', LeagueController::class)->name('twa.league');

        // Friends and challenges (docs/13 §7.5, docs/04 A.13): reads share `twa-api`, writes have their own cap.
        Route::get('/friends', [FriendController::class, 'index'])->name('twa.friends');
        Route::get('/challenges', [ChallengeController::class, 'index'])->name('twa.challenges');
        Route::middleware('throttle:twa-friends')->group(function (): void {
            Route::post('/friends/{friendship}/accept', [FriendController::class, 'accept'])->name('twa.friends.accept');
            Route::post('/friends/{friendship}/decline', [FriendController::class, 'decline'])->name('twa.friends.decline');
            Route::post('/friends/{friendship}/remove', [FriendController::class, 'remove'])->name('twa.friends.remove');
            Route::post('/challenges', [ChallengeController::class, 'store'])->name('twa.challenges.store');
            Route::post('/challenges/{challenge}/accept', [ChallengeController::class, 'accept'])->name('twa.challenges.accept');
            Route::post('/challenges/{challenge}/decline', [ChallengeController::class, 'decline'])->name('twa.challenges.decline');
        });

        Route::post('/promo/redeem', [PromoController::class, 'redeem'])
            ->middleware('throttle:twa-promo')
            ->name('twa.promo.redeem');

        // Purchase and auto-renewal of the learner's own Pro (docs/13 §5, docs/04 A.8).
        Route::prefix('billing')->group(function (): void {
            Route::get('/catalog', [BillingController::class, 'catalog'])->name('twa.billing.catalog');
            Route::post('/checkout', [BillingController::class, 'checkout'])
                ->middleware('throttle:twa-checkout')
                ->name('twa.billing.checkout');
            Route::get('/orders/{token}', [BillingController::class, 'order'])->name('twa.billing.order');
            Route::middleware('throttle:twa-subscription')->group(function (): void {
                Route::post('/subscription/cancel', [BillingController::class, 'cancel'])->name('twa.billing.cancel');
                Route::post('/subscription/resume', [BillingController::class, 'resume'])->name('twa.billing.resume');
            });
        });

        Route::get('/courses', [CourseController::class, 'index'])->name('twa.courses.index');
        Route::get('/courses/{slug}', [CourseController::class, 'show'])->name('twa.courses.show');
        Route::post('/me/course', [CourseController::class, 'select'])->name('twa.me.course');
        // IELTS / SAT mock of a course (Pro, docs/04 A.4a); played on the exam routes below.
        Route::post('/courses/{slug}/mock-exam', [MockExamController::class, 'open'])
            ->middleware('throttle:twa-session-create')
            ->name('twa.courses.mock-exam');

        // Learner decks (docs/04 A.10): reads share `twa-api`; writes and imports have their own caps.
        Route::get('/decks', [DeckController::class, 'index'])->name('twa.decks.index');
        Route::get('/decks/{slug}', [DeckController::class, 'show'])->name('twa.decks.show');
        Route::middleware('throttle:twa-decks')->group(function (): void {
            Route::post('/decks', [DeckController::class, 'store'])->name('twa.decks.store');
            Route::patch('/decks/{slug}', [DeckController::class, 'update'])->name('twa.decks.update');
            Route::delete('/decks/{slug}', [DeckController::class, 'destroy'])->name('twa.decks.destroy');
            Route::delete('/decks/{slug}/words/{word}', [DeckController::class, 'removeWord'])
                ->whereNumber('word')
                ->name('twa.decks.words.remove');
        });
        Route::post('/decks/{slug}/words', [DeckController::class, 'addWords'])
            ->middleware('throttle:twa-deck-import')
            ->name('twa.decks.words.add');

        Route::get('/tts', TtsController::class)
            ->middleware('throttle:twa-tts')
            ->name('twa.tts');

        Route::post('/training/sessions', [TrainingController::class, 'open'])
            ->middleware('throttle:twa-session-create')
            ->name('twa.training.open');

        Route::prefix('training/sessions/{session}')->whereNumber('session')->group(function (): void {
            Route::post('/start', [TrainingController::class, 'start'])->name('twa.training.start');
            Route::get('/next', [TrainingController::class, 'next'])->name('twa.training.next');
            Route::post('/finish', [TrainingController::class, 'finish'])->name('twa.training.finish');
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

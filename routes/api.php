<?php

declare(strict_types=1);

use App\Domain\Practice\Services\Battle\BattleReader;
use App\Http\Controllers\Api\Twa\AchievementController;
use App\Http\Controllers\Api\Twa\AssignmentController;
use App\Http\Controllers\Api\Twa\AuthController;
use App\Http\Controllers\Api\Twa\BattleController;
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
use App\Http\Controllers\Api\Twa\PracticeAudioController;
use App\Http\Controllers\Api\Twa\PracticeHubController;
use App\Http\Controllers\Api\Twa\PracticeSessionController;
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

        // Achievements (docs/13 §7.4, docs/04 A.13).
        Route::get('/achievements', [AchievementController::class, 'index'])->name('twa.achievements');
        Route::post('/achievements/{key}/shared', [AchievementController::class, 'shared'])
            ->where('key', '[a-z0-9_]{1,32}')
            ->name('twa.achievements.shared');

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

        // Practice (docs/16 §7.1-7.2): the hub and the solo sessions. Opening a session and answering have limiters
        // of their own on top of `twa-api`. The battle (§7.3-7.4) follows below.
        Route::get('/practice/hub', [PracticeHubController::class, 'show'])->name('twa.practice.hub');
        Route::post('/practice/sessions', [PracticeSessionController::class, 'store'])
            ->middleware('throttle:twa-practice-open')
            ->name('twa.practice.sessions.store');

        Route::prefix('practice/sessions/{session}')->whereNumber('session')->group(function (): void {
            Route::get('/', [PracticeSessionController::class, 'show'])->name('twa.practice.sessions.show');
            Route::middleware('throttle:twa-practice-answer')->group(function (): void {
                Route::post('/answer', [PracticeSessionController::class, 'answer'])->name('twa.practice.sessions.answer');
                Route::post('/replace', [PracticeSessionController::class, 'replace'])->name('twa.practice.sessions.replace');
            });
        });

        // Battle 1 on 1 (docs/16 §7.3-7.4): the reads share `twa-api`; an invitation (a snapshot, rows, a DM to the friend) and the
        // actions have limiters of their own on top. The poll and the audio are OUTSIDE it, below. `options` before `{battle}`.
        // The id is up to 18 digits: a longer one does not fit an integer and would reach the controller as a TypeError (500).
        Route::prefix('practice/battles')->group(function (): void {
            Route::get('/options', [BattleController::class, 'options'])->name('twa.practice.battles.options');
            Route::get('/', [BattleController::class, 'index'])->name('twa.practice.battles.index');
            Route::post('/', [BattleController::class, 'store'])
                ->middleware(['throttle:twa-practice-open', 'throttle:twa-battle-invite'])
                ->name('twa.practice.battles.store');

            Route::prefix('{battle}')->where(['battle' => '[0-9]{1,18}'])->group(function (): void {
                Route::get('/', [BattleController::class, 'show'])->name('twa.practice.battles.show');
                Route::get('/round', [BattleController::class, 'round'])->name('twa.practice.battles.round');
                Route::middleware('throttle:twa-battle-action')->group(function (): void {
                    Route::post('/accept', [BattleController::class, 'accept'])->name('twa.practice.battles.accept');
                    Route::post('/decline', [BattleController::class, 'decline'])->name('twa.practice.battles.decline');
                    Route::post('/cancel', [BattleController::class, 'cancel'])->name('twa.practice.battles.cancel');
                    Route::post('/ready', [BattleController::class, 'ready'])->name('twa.practice.battles.ready');
                    Route::post('/surrender', [BattleController::class, 'surrender'])->name('twa.practice.battles.surrender');
                    Route::post('/answer', [BattleController::class, 'answer'])->name('twa.practice.battles.answer');
                    Route::post('/audio-failed', [BattleController::class, 'audioFailed'])->name('twa.practice.battles.audio-failed');
                });
            });
        });
    });

    // The poll of a battle lives OUTSIDE `twa-api` (docs/16 §7.5): a request every 2 s for as long as a room or a match is on screen is
    // 30 a minute (two tabs, 60), and inside `twa-api` it would eat the whole budget of 60 and choke the rounds and the answers. It has
    // a bucket of its own (90 a minute) - a limit still, so that a loop in a client or a script runs into it, not into the database.
    Route::middleware([TwaAuth::class, SetTwaLocale::class, 'throttle:twa-battle-poll'])->group(function (): void {
        Route::get('/practice/battles/{battle}/poll', [BattleController::class, 'poll'])
            ->where('battle', '[0-9]{1,18}')
            ->name('twa.practice.battles.poll');
    });

    // The audio of a practice session and of a battle lives OUTSIDE `twa-api` (docs/16 §7.5): five clips are loaded at once when a
    // room is entered, and that preloading must not eat the general budget of 60 requests a minute. It has its own limiter.
    Route::middleware([TwaAuth::class, SetTwaLocale::class, 'throttle:twa-practice-audio'])->group(function (): void {
        Route::get('/practice/sessions/{session}/audio/{element}', [PracticeAudioController::class, 'session'])
            ->whereNumber('session')
            ->name('twa.practice.sessions.audio');
        Route::get('/practice/battles/{battle}/audio/{element}', [PracticeAudioController::class, 'battle'])
            ->where('battle', '[0-9]{1,18}')
            ->name(BattleReader::AUDIO_ROUTE);
    });
});

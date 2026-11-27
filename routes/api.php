<?php

declare(strict_types=1);

use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learners\Support\OnboardingChoices;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Http\Controllers\Api\Twa\AchievementController;
use App\Http\Controllers\Api\Twa\AllTimeRatingController;
use App\Http\Controllers\Api\Twa\AssignmentController;
use App\Http\Controllers\Api\Twa\AuthController;
use App\Http\Controllers\Api\Twa\BattleController;
use App\Http\Controllers\Api\Twa\BillingController;
use App\Http\Controllers\Api\Twa\ChallengeController;
use App\Http\Controllers\Api\Twa\ClassCaravanController;
use App\Http\Controllers\Api\Twa\ClientTimingController;
use App\Http\Controllers\Api\Twa\CourseController;
use App\Http\Controllers\Api\Twa\Cup\CupController;
use App\Http\Controllers\Api\Twa\Cup\CupDuelController;
use App\Http\Controllers\Api\Twa\Cup\CupRoundController;
use App\Http\Controllers\Api\Twa\Cup\CupSetupController;
use App\Http\Controllers\Api\Twa\DeckController;
use App\Http\Controllers\Api\Twa\ExamController;
use App\Http\Controllers\Api\Twa\FriendController;
use App\Http\Controllers\Api\Twa\GameCityController;
use App\Http\Controllers\Api\Twa\GameMapController;
use App\Http\Controllers\Api\Twa\GameProfileController;
use App\Http\Controllers\Api\Twa\GameRunController;
use App\Http\Controllers\Api\Twa\GameShopController;
use App\Http\Controllers\Api\Twa\GameWalletController;
use App\Http\Controllers\Api\Twa\LeagueController;
use App\Http\Controllers\Api\Twa\LevelQuizController;
use App\Http\Controllers\Api\Twa\MeController;
use App\Http\Controllers\Api\Twa\MockExamController;
use App\Http\Controllers\Api\Twa\MonthlyRatingController;
use App\Http\Controllers\Api\Twa\OfferViewedController;
use App\Http\Controllers\Api\Twa\OnboardingController;
use App\Http\Controllers\Api\Twa\OnboardingPlacementController;
use App\Http\Controllers\Api\Twa\OrganizationController;
use App\Http\Controllers\Api\Twa\PracticeAudioController;
use App\Http\Controllers\Api\Twa\PracticeHubController;
use App\Http\Controllers\Api\Twa\PracticeSessionController;
use App\Http\Controllers\Api\Twa\PromoController;
use App\Http\Controllers\Api\Twa\ReadinessController;
use App\Http\Controllers\Api\Twa\ReferralController;
use App\Http\Controllers\Api\Twa\SettingsController;
use App\Http\Controllers\Api\Twa\ShareController;
use App\Http\Controllers\Api\Twa\TodayController;
use App\Http\Controllers\Api\Twa\TrainingController;
use App\Http\Controllers\Api\Twa\VocabularyController;
use App\Http\Controllers\Api\Twa\WordNoteController;
use App\Http\Middleware\EnsureCupAccess;
use App\Http\Middleware\EnsureCupDuels;
use App\Http\Middleware\SetTwaLocale;
use App\Http\Middleware\TwaAuth;
use App\Http\Resources\Twa\Cup\CupRoundResource;
use Illuminate\Support\Facades\Route;

Route::prefix('twa')->group(function (): void {
    Route::post('/auth', AuthController::class)
        ->middleware([SetTwaLocale::class, 'throttle:twa-auth'])
        ->name('twa.auth');

    Route::middleware([TwaAuth::class, SetTwaLocale::class, 'throttle:twa-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('twa.me');
        Route::patch('/me/settings', [SettingsController::class, 'update'])->name('twa.me.settings');
        Route::post('/me/settings/timezone/revert', [SettingsController::class, 'revertTimezone'])->name('twa.me.settings.timezone.revert');
        Route::post('/me/settings/region/revert', [SettingsController::class, 'revertRegion'])->name('twa.me.settings.region.revert');
        Route::post('/me/region-prompt/dismiss', [SettingsController::class, 'dismissRegionPrompt'])->name('twa.me.region_prompt.dismiss');
        Route::post('/me/account-restored/seen', [MeController::class, 'restoredSeen'])->name('twa.me.account_restored.seen');
        Route::post('/me/delete', [SettingsController::class, 'delete'])->name('twa.me.delete');
        Route::get('/me/organizations', [OrganizationController::class, 'index'])->name('twa.me.organizations');
        Route::delete('/me/organizations/{member}', [OrganizationController::class, 'leave'])
            ->whereNumber('member')
            ->middleware('throttle:twa-organizations')
            ->name('twa.me.organizations.leave');
        Route::get('/today', TodayController::class)->name('twa.today');

        // The onboarding of the Mini App, O1-O8 (docs/40 §2.1, docs/04 A.24): the read shares `twa-api`, the steps and the end have
        // their own cap too. An unknown step is a 404 of the route.
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('twa.onboarding');
        Route::middleware('throttle:twa-onboarding')->group(function (): void {
            Route::put('/onboarding/steps/{step}', [OnboardingController::class, 'update'])
                ->whereIn('step', OnboardingChoices::STEPS)
                ->name('twa.onboarding.step');
            Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('twa.onboarding.complete');
        });
        // The placement test of the level screen (docs/40 §2.1a, docs/04 A.27): a set of words per start, one scoring per set - the
        // cap of the personal sessions (10/min) keeps a script from guessing the key by starting over.
        Route::middleware('throttle:twa-session-create')->group(function (): void {
            Route::post('/onboarding/placement', [OnboardingPlacementController::class, 'start'])->name('twa.onboarding.placement');
            Route::post('/onboarding/placement/answers', [OnboardingPlacementController::class, 'answer'])->name('twa.onboarding.placement.answers');
        });
        Route::get('/assignments', AssignmentController::class)->name('twa.assignments');
        Route::get('/referrals', ReferralController::class)->name('twa.referrals');
        Route::get('/league', LeagueController::class)->name('twa.league');
        // The monthly rating with prizes (docs/13 §7.6, docs/04 A.19): the rating, and the viewer's own line for the finish screen.
        Route::get('/league/month', [MonthlyRatingController::class, 'show'])->name('twa.league.month');
        Route::get('/league/month/me', [MonthlyRatingController::class, 'me'])->name('twa.league.month.me');
        // "All time" (docs/32 §5, §8.3, docs/04 A.19a) and the tables of a region (docs/35 §2.4).
        Route::get('/league/all-time', AllTimeRatingController::class)->name('twa.league.all_time');

        // Achievements (docs/13 §7.4, docs/04 A.13).
        Route::get('/achievements', [AchievementController::class, 'index'])->name('twa.achievements');
        Route::post('/achievements/{key}/shared', [AchievementController::class, 'shared'])
            ->where('key', '[a-z0-9_]{1,32}')
            ->name('twa.achievements.shared');

        // "Share" through the bot (docs/36 §1в, docs/04 A.22): a prepared message for `WebApp.shareMessage` (a Bot API call: its own
        // limiter, per learner) and the client's analytics of it.
        Route::post('/share/prepare', [ShareController::class, 'prepare'])
            ->middleware('throttle:twa-share')
            ->name('twa.share.prepare');
        // The 9:16 card for `WebApp.shareToStory` (docs/40 §2.4, docs/04 A.26): a render on the disk, the same cap as the message.
        Route::post('/share/story', [ShareController::class, 'story'])
            ->middleware('throttle:twa-share')
            ->name('twa.share.story');
        Route::post('/share/events', [ShareController::class, 'event'])
            ->middleware('throttle:twa-share-event')
            ->name('twa.share.events');

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

        // Timing marks of one sampled open (docs/04 A.21): 204, one event per open, nothing recorded while the rate is 0.
        Route::post('/client-timing', ClientTimingController::class)
            ->middleware('throttle:twa-client-timing')
            ->name('twa.client_timing');

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
            // The first step of the Pro funnel (docs/13 §5.1): a Pro offer became visible; 204, deduplicated per placement and local day.
            Route::post('/offer-viewed', OfferViewedController::class)
                ->middleware('throttle:twa-offer-viewed')
                ->name('twa.billing.offer_viewed');
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
        // Readiness to the level and the quiz of the week of a course (docs/04 A.20); the quiz is played on the exam routes below.
        Route::get('/courses/{slug}/readiness', [ReadinessController::class, 'show'])->name('twa.courses.readiness');
        Route::post('/courses/{slug}/level-quiz', [LevelQuizController::class, 'open'])
            ->middleware('throttle:twa-session-create')
            ->name('twa.courses.level-quiz');

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

        // "My vocabulary" (docs/40 §2.3, docs/04 A.25): the learner's graded words, a page at a time; a read under `twa-api`.
        Route::get('/vocabulary', VocabularyController::class)->name('twa.vocabulary');

        // The learner's own memory hook of a word (docs/06 §12.3): one row of theirs, a write cap of its own.
        Route::put('/words/{word}/note', [WordNoteController::class, 'update'])
            ->whereNumber('word')
            ->middleware('throttle:twa-word-notes')
            ->name('twa.words.note');

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

        // The game "Caravan" (docs/18 §12.8, docs/04 A.18): the map; the runs, the wallet, the shop and the profile are below.
        Route::get('/game/map', [GameMapController::class, 'show'])->name('twa.game.map');

        // The wallet, the shop and the profile (docs/18 §12.8, G2.5b): all under `twa-api`; a purchase is paid once per `request_id`.
        Route::get('/game/wallet', [GameWalletController::class, 'show'])->name('twa.game.wallet');
        Route::get('/game/shop', [GameShopController::class, 'index'])->name('twa.game.shop');
        Route::post('/game/shop/{key}/buy', [GameShopController::class, 'buy'])
            ->where('key', '[a-z0-9][a-z0-9_]{0,47}')
            ->name('twa.game.shop.buy');
        Route::get('/game/profile', [GameProfileController::class, 'show'])->name('twa.game.profile');
        Route::post('/game/profile/equip', [GameProfileController::class, 'equip'])->name('twa.game.profile.equip');

        // The card of a city that the learner has reached (docs/18 §12.9, G2.7b): the mark "shared".
        Route::post('/game/cities/{stage}/shared', [GameCityController::class, 'shared'])->where('stage', '[0-9]{1,18}')->name('twa.game.cities.shared');

        // The caravans of the learner's classes (docs/18 §12.8, G2.9b): the list and one caravan with its table; under `twa-api`.
        Route::get('/game/class', [ClassCaravanController::class, 'index'])->name('twa.game.class.index');
        Route::get('/game/class/{id}', [ClassCaravanController::class, 'show'])->where('id', '[0-9]{1,18}')->name('twa.game.class.show');

        // The runs (docs/18 §12.8): starting has a limiter of its own, answering and the hint share another; the audio is OUTSIDE `twa-api`, below.
        Route::post('/game/runs', [GameRunController::class, 'store'])
            ->middleware('throttle:twa-game-start')
            ->name('twa.game.runs.store');
        Route::prefix('game/runs/{run}')->where(['run' => '[0-9]{1,18}'])->group(function (): void {
            Route::get('/', [GameRunController::class, 'show'])->name('twa.game.runs.show');
            Route::post('/abandon', [GameRunController::class, 'abandon'])->name('twa.game.runs.abandon');
            Route::middleware('throttle:twa-game-answer')->group(function (): void {
                Route::post('/answer', [GameRunController::class, 'answer'])->name('twa.game.runs.answer');
                Route::post('/hint', [GameRunController::class, 'hint'])->name('twa.game.runs.hint');
                Route::post('/booster', [GameRunController::class, 'booster'])->name('twa.game.runs.booster');
            });
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
                Route::post('/start', [PracticeSessionController::class, 'start'])->name('twa.practice.sessions.start');
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

        // The cup of a group (docs/33 §7.2, docs/04 A.23, K7). `CUP_ACCESS=off` closes all of it with one 403 `cup_unavailable`. The
        // routes that may call Telegram (getChatMember) or write have limiters of their own on top of `twa-api`; the codes are the
        // 22 characters of base64url (anything else is a plain 404 of the router), the ids up to 18 digits.
        Route::middleware(EnsureCupAccess::class)->group(function (): void {
            Route::get('/cups', [CupController::class, 'index'])->name('twa.cups.index');
            Route::get('/cups/by-code/{code}', [CupController::class, 'invite'])
                ->where('code', '[A-Za-z0-9_-]{22}')
                ->middleware('throttle:twa-cup-read')
                ->name('twa.cups.invite');
            Route::middleware('throttle:twa-cup-admin')->group(function (): void {
                Route::get('/cups/setup/{code}', [CupSetupController::class, 'show'])->where('code', '[A-Za-z0-9_-]{22}')->name('twa.cups.setup');
                Route::post('/cups/setup/{code}/publish', [CupSetupController::class, 'publish'])->where('code', '[A-Za-z0-9_-]{22}')->name('twa.cups.publish');
                Route::patch('/cups/{id}/settings', [CupSetupController::class, 'update'])->where('id', '[0-9]{1,18}')->name('twa.cups.settings');
                Route::post('/cups/{id}/cancel', [CupSetupController::class, 'cancel'])->where('id', '[0-9]{1,18}')->name('twa.cups.cancel');
            });
            Route::prefix('cups/{id}')->where(['id' => '[0-9]{1,18}'])->group(function (): void {
                Route::middleware('throttle:twa-cup-read')->group(function (): void {
                    Route::get('/', [CupController::class, 'show'])->name('twa.cups.show');
                    Route::get('/rounds/today', [CupController::class, 'today'])->name('twa.cups.today');
                });
                Route::middleware('throttle:twa-cup-join')->group(function (): void {
                    Route::post('/join', [CupController::class, 'join'])->name('twa.cups.join');
                    Route::patch('/me', [CupController::class, 'rename'])->name('twa.cups.rename');
                    Route::post('/leave', [CupController::class, 'leave'])->name('twa.cups.leave');
                });
                Route::post('/rounds/today', [CupController::class, 'start'])->middleware('throttle:twa-cup-start')->name('twa.cups.start');

                // The duels (docs/04 A.23d, D2): `CUP_DUELS` off - one 409 `cup_duel_disabled` before anything is read. The actions
                // ask Telegram about the one who acts and write: `twa-cup-duel`; the poll of one duel (the waiting screen) has a bucket of
                // its own, the list shares the reads of the cup.
                Route::prefix('duels')->middleware(EnsureCupDuels::class)->group(function (): void {
                    Route::get('/', [CupDuelController::class, 'index'])->middleware('throttle:twa-cup-read')->name('twa.cups.duels.index');
                    Route::post('/', [CupDuelController::class, 'store'])->middleware('throttle:twa-cup-duel')->name('twa.cups.duels.store');
                    Route::prefix('{duel}')->where(['duel' => '[0-9]{1,18}'])->group(function (): void {
                        Route::get('/', [CupDuelController::class, 'show'])->middleware('throttle:twa-cup-duel-poll')->name('twa.cups.duels.show');
                        Route::middleware('throttle:twa-cup-duel')->group(function (): void {
                            Route::post('/accept', [CupDuelController::class, 'accept'])->name('twa.cups.duels.accept');
                            Route::post('/decline', [CupDuelController::class, 'decline'])->name('twa.cups.duels.decline');
                            Route::post('/cancel', [CupDuelController::class, 'cancel'])->name('twa.cups.duels.cancel');
                        });
                    });
                });
            });
            Route::prefix('cup-rounds/{attempt}')->where(['attempt' => '[0-9]{1,18}'])->group(function (): void {
                Route::get('/', [CupRoundController::class, 'show'])->name('twa.cup-rounds.show');
                Route::post('/answer', [CupRoundController::class, 'answer'])->middleware('throttle:twa-cup-answer')->name('twa.cup-rounds.answer');
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
        Route::get('/game/runs/{run}/audio/{element}', [GameRunController::class, 'audio'])
            ->where('run', '[0-9]{1,18}')
            ->name(RunService::AUDIO_ROUTE);
        Route::get('/practice/sessions/{session}/audio/{element}', [PracticeAudioController::class, 'session'])
            ->whereNumber('session')
            ->name('twa.practice.sessions.audio');
        Route::get('/practice/battles/{battle}/audio/{element}', [PracticeAudioController::class, 'battle'])
            ->where('battle', '[0-9]{1,18}')
            ->name(BattleReader::AUDIO_ROUTE);
        // The clip of a `listen` task of a round of a cup (K7): the learner's own attempt, the current or an answered task only.
        Route::get('/cup-rounds/{attempt}/audio/{element}', [CupRoundController::class, 'audio'])
            ->where('attempt', '[0-9]{1,18}')
            ->middleware(EnsureCupAccess::class)
            ->name(CupRoundResource::AUDIO_ROUTE);
    });
});

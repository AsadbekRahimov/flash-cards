<?php

declare(strict_types=1);

use App\Domain\Learners\Services\DayPlanService;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeSession;
use App\Models\TrainingSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

/*
 * The plan of the day in GET /today (`plan`, docs/04 A.2a, docs/31 §5б): lesson -> review of the lesson -> game on its words, in the
 * learner's local day, and the same answer for the bot (DayPlanService::forLearner, DayPlan::offersGame()).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 10:00 in Tashkent; the local day is [2026-11-24 19:00, 2026-11-25 19:00) UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    config([
        'learning.lesson_review' => ['enabled' => true, 'max_words' => 20, 'max_serves_per_word' => 3],
        'billing.plans.free.limits.new_words_per_day' => 20,
        'practice.access' => 'all', 'practice.plan' => 'all', 'practice.modes.pairs_timed' => true, 'game.access' => 'off',
    ]);
});

/** @return array{learner: Learner, course: Course, lessons: array<string, Lesson>, headers: array<string, string>} */
function dayPlanWorld(): array
{
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 5, 2 => 6]]);
    $lessons['1.1']->update(['title' => 'Напитки']);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 20, 'interface_locale' => 'ru']);

    return ['learner' => $learner, 'course' => $course, 'lessons' => $lessons, 'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']]];
}

/** Plays a training session of `$mode` on the lesson to its end; returns the session id. */
function dayPlanPlay(object $test, array $headers, string $mode, int $lessonId, bool $finish = true): int
{
    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => $mode, 'lesson_id' => $lessonId])->assertOk()->json();
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }
    if ($finish) {
        $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();
    }

    return (int) $opened['session_id'];
}

/** @return array<string, mixed> */
function dayPlanToday(object $test, array $headers): array
{
    return $test->withHeaders($headers)->getJson('/api/twa/today')->assertOk()->json();
}

it('walks the steps: lesson, review, game, done', function (): void {
    ['learner' => $learner, 'course' => $course, 'lessons' => $lessons, 'headers' => $h] = dayPlanWorld();
    $l1 = $lessons['1.1']->id;

    // Step 1: nothing studied today; the next lesson has 5 new words.
    $today = dayPlanToday($this, $h);
    expect($today['active_course']['next_lesson'])->toMatchArray(['id' => $l1, 'words_left' => 5])
        ->and($today['plan'])->toBe([
            'step' => 1, 'done' => false,
            'lesson' => ['done' => false, 'lesson_id' => null, 'next_lesson_id' => $l1, 'words_left' => 5],
            'training' => null, 'game' => null,
        ]);

    // Step 2: the lesson played to its end today.
    dayPlanPlay($this, $h, 'lesson', $l1);
    $today = dayPlanToday($this, $h);
    expect($today['plan'])->toBe([
        'step' => 2, 'done' => false,
        'lesson' => ['done' => true, 'lesson_id' => $l1, 'next_lesson_id' => $lessons['1.2']->id, 'words_left' => 6],
        'training' => ['lesson_id' => $l1, 'course' => $course->slug, 'title' => 'Напитки', 'words' => 5, 'done' => false, 'open_session_id' => null],
        'game' => ['lesson_id' => $l1, 'course' => $course->slug, 'mode' => 'pairs_timed', 'played' => false],
    ]);
    expect(app(DayPlanService::class)->forLearner($learner->fresh())->offersGame())->toBeTrue();

    // A review begun and not finished: still step 2, with the session to continue.
    $open = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $l1])->assertOk()->json('session_id');
    expect(dayPlanToday($this, $h)['plan']['training'])->toMatchArray(['done' => false, 'open_session_id' => $open]);

    // Step 3: the review played to its end.
    dayPlanPlay($this, $h, 'lesson_review', $l1, finish: false);
    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['step'])->toBe(3)
        ->and($plan['training'])->toMatchArray(['done' => true, 'open_session_id' => null])
        ->and($plan['game']['played'])->toBeFalse();

    // A game on the words of the lesson, answered at least once: the chain is done, the bot has nothing to offer.
    PracticeSession::factory()->ofMode(PracticeMode::PairsTimed)->create([
        'learner_id' => $learner->id, 'course_id' => $course->id, 'lesson_id' => $l1, 'source' => PracticeSession::SOURCE_DEEPLINK,
        'status' => SessionStatus::Finished, 'active_learner_id' => null, 'answered' => 3, 'started_at' => now(), 'finished_at' => now(),
    ]);
    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['step'])->toBeNull()
        ->and($plan['done'])->toBeTrue()
        ->and($plan['game']['played'])->toBeTrue()
        ->and(app(DayPlanService::class)->forLearner($learner->fresh())->offersGame())->toBeFalse();
});

it('goes to the game straight after the lesson when the review is switched off, and has no game while the practice is closed', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = dayPlanWorld();
    dayPlanPlay($this, $h, 'lesson', $lessons['1.1']->id);

    config(['learning.lesson_review.enabled' => false]);
    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['step'])->toBe(3)->and($plan['training'])->toBeNull();

    config(['practice.access' => 'off']);
    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['step'])->toBeNull()->and($plan['done'])->toBeTrue()->and($plan['game'])->toBeNull()
        ->and(app(DayPlanService::class)->forLearner($learner->fresh())->offersGame())->toBeFalse();
});

it('does not count a game begun without an answer, nor one on another lesson, nor one of yesterday', function (): void {
    ['learner' => $learner, 'course' => $course, 'lessons' => $lessons, 'headers' => $h] = dayPlanWorld();
    $l1 = $lessons['1.1']->id;
    dayPlanPlay($this, $h, 'lesson', $l1);
    $base = ['learner_id' => $learner->id, 'course_id' => $course->id, 'status' => SessionStatus::Finished, 'active_learner_id' => null, 'finished_at' => now()];
    PracticeSession::factory()->create([...$base, 'lesson_id' => $l1, 'answered' => 0, 'started_at' => now()]);
    PracticeSession::factory()->create([...$base, 'lesson_id' => $lessons['1.2']->id, 'answered' => 5, 'started_at' => now()]);
    PracticeSession::factory()->create([...$base, 'lesson_id' => $l1, 'answered' => 5, 'started_at' => CarbonImmutable::parse('2026-11-24 18:59:00', 'UTC')]);

    expect(dayPlanToday($this, $h)['plan']['game']['played'])->toBeFalse()
        ->and(app(DayPlanService::class)->forLearner($learner->fresh())->offersGame())->toBeTrue();
});

it('keeps a lesson left in the middle at step 1, and does not count a lesson re-played without new words', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = dayPlanWorld();
    // Two cards of the lesson and "Finish": not played to its end.
    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    $card = $opened['card'];
    for ($i = 0; $i < 2; $i++) {
        $card = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['step'])->toBe(1)->and($plan['lesson']['done'])->toBeFalse()->and($plan['training'])->toBeNull();

    // Yesterday the learner met every word of 1.2; today they play it again to its end - no word is new today.
    $this->travel(-1)->days();
    Fx::learn($learner, Word::query()->where('lesson_id', $lessons['1.2']->id)->pluck('id')->all());
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    TrainingSession::query()->create([
        'learner_id' => $learner->id, 'mode' => 'lesson', 'lesson_id' => $lessons['1.2']->id, 'source' => 'self', 'status' => 'closed',
        'started_at' => now(), 'ended_at' => now(), 'completed_at' => now(), 'expires_at' => now()->addHours(12), 'done_reason' => 'finished',
    ]);
    expect(dayPlanToday($this, $h)['plan']['lesson']['done'])->toBeFalse();
});

it('starts a new plan at the local midnight, not at the UTC one', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = dayPlanWorld();
    dayPlanPlay($this, $h, 'lesson', $lessons['1.1']->id);

    // 23:59 in Tashkent (18:59 UTC): still today.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 18:59:00', 'UTC'));
    expect(dayPlanToday($this, $h)['plan']['step'])->toBe(2);

    // 00:00 in Tashkent (19:00 UTC), long before the UTC midnight: a new day - the next lesson.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 19:00:00', 'UTC'));
    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['step'])->toBe(1)
        ->and($plan['lesson'])->toBe(['done' => false, 'lesson_id' => null, 'next_lesson_id' => $lessons['1.2']->id, 'words_left' => 6])
        ->and($plan['training'])->toBeNull()
        ->and(app(DayPlanService::class)->forLearner($learner->fresh())->offersGame())->toBeFalse();
});

it('names the course of the lesson of today when it is not the active course, and without an active course', function (): void {
    ['learner' => $learner, 'course' => $active, 'headers' => $h] = dayPlanWorld();
    ['course' => $other, 'lessons' => $otherLessons] = Fx::course([1 => [1 => 4]]);
    $lesson = $otherLessons['1.1']->id;

    // A lesson of another course (an assignment of a center, a course left since) played to its end today.
    dayPlanPlay($this, $h, 'lesson', $lesson);
    $plan = dayPlanToday($this, $h)['plan'];
    expect($other->slug)->not->toBe($active->slug)
        ->and($plan['step'])->toBe(2)
        ->and($plan['training'])->toMatchArray(['lesson_id' => $lesson, 'course' => $other->slug, 'title' => null])
        ->and($plan['game'])->toMatchArray(['lesson_id' => $lesson, 'course' => $other->slug])
        ->and(app(DayPlanService::class)->forLearner($learner->fresh())->todayCourseSlug)->toBe($other->slug);

    // No active course at all: the plan still names where its steps open.
    $learner->update(['active_course_id' => null]);
    $plan = dayPlanToday($this, $h)['plan'];
    expect($plan['game'])->toMatchArray(['lesson_id' => $lesson, 'course' => $other->slug])
        ->and($plan['training']['course'])->toBe($other->slug);
});

it('pays no statement for the plan on /today: the sessions and the scalars carry it', function (): void {
    ['lessons' => $lessons, 'headers' => $h] = dayPlanWorld();
    dayPlanPlay($this, $h, 'lesson', $lessons['1.1']->id);
    dayPlanToday($this, $h); // warm

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->assertJsonPath('plan.step', 2));

    expect(QueryLog::matching($queries, '/from ["`]training_sessions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]practice_sessions["`]/'))->toHaveCount(1)
        // ... and that one is a member of the one statement of scalars, beside the words met today.
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`].*as ["`]plan_words["`].*from ["`]practice_sessions["`].*as ["`]plan_game["`].*from ["`]lessons["`].*as ["`]plan_course["`]/s'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]lessons["`]/'))->toHaveCount(1);
});

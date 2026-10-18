<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\SpacedRepetitionEngine;
use App\Domain\Learning\Support\LearningPolicy;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeRepetition(array $overrides = []): WordRepetition
{
    $group = TelegramGroup::factory()->create();
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);
    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    $word = Word::factory()->for($lesson)->create();

    return WordRepetition::factory()->create(array_merge([
        'student_id' => $student->id,
        'word_id' => $word->id,
        'repetitions' => 0,
        'interval_days' => 0,
        'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
        'last_quality' => null,
        'is_hard' => false,
        'state' => RepetitionState::New->value,
        'learning_step' => 0,
        'lapses' => 0,
    ], $overrides));
}

/** Graduated (review-state) card helper. */
function makeReviewRepetition(array $overrides = []): WordRepetition
{
    return makeRepetition(array_merge([
        'state' => RepetitionState::Review->value,
        'repetitions' => 1,
        'interval_days' => 1,
    ], $overrides));
}

function engine(?LearningPolicy $policy = null): SpacedRepetitionEngine
{
    return new SpacedRepetitionEngine($policy ?? new LearningPolicy(
        learningStepsMinutes: [1, 10],
        relearningStepsMinutes: [10],
        graduatingIntervalDays: 1,
        easyIntervalDays: 4,
        learnAheadMinutes: 20,
    ));
}

$now = CarbonImmutable::parse('2026-10-18 12:00:00');

// ---------------------------------------------------------------------------
// EF formula (API contract 0..5 unchanged)
// ---------------------------------------------------------------------------

it('updates EF with standard SM-2 delta for each quality', function (): void {
    $engine = engine();

    // delta = 0.1 - (5-q) * (0.08 + (5-q) * 0.02)
    expect($engine->newEasinessFactor(2.50, 5))->toEqualWithDelta(2.60, 0.0001);
    expect($engine->newEasinessFactor(2.50, 4))->toEqualWithDelta(2.50, 0.0001);
    expect($engine->newEasinessFactor(2.50, 3))->toEqualWithDelta(2.36, 0.0001);
    expect($engine->newEasinessFactor(2.50, 2))->toEqualWithDelta(2.18, 0.0001);
    expect($engine->newEasinessFactor(2.50, 1))->toEqualWithDelta(1.96, 0.0001);
    expect($engine->newEasinessFactor(2.50, 0))->toEqualWithDelta(1.70, 0.0001);
});

it('never drops EF below 1.30 even on repeated failures', function (): void {
    $engine = engine();

    $ef = 1.30;
    for ($i = 0; $i < 20; $i++) {
        $ef = $engine->newEasinessFactor($ef, 0);
    }

    expect($ef)->toBe(SpacedRepetitionEngine::MIN_EF);
});

it('throws on quality out of 0..5 range', function (int $q): void {
    $rep = makeRepetition();
    engine()->applyReview($rep, $q);
})->with([-1, 6, 99, -100])->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// "Средне" (Good = q4) never lowers EF; "Трудно" (Again = q2) does, once per lapse
// ---------------------------------------------------------------------------

it('keeps EF unchanged on a review card graded Good (q=4) — no ease hell', function () use ($now): void {
    $rep = makeReviewRepetition(['repetitions' => 3, 'interval_days' => 15, 'easiness_factor' => 2.50]);

    $engine = engine();
    for ($i = 0; $i < 10; $i++) {
        $engine->applyReview($rep->fresh(), 4, $now->addDays($i * 30));
    }

    expect($rep->fresh())
        ->easiness_factor->toEqual(2.50)
        ->state->toBe(RepetitionState::Review)
        ->repetitions->toBe(13);
});

it('raises EF by 0.10 on a review card graded Easy (q=5)', function () use ($now): void {
    $rep = makeReviewRepetition(['repetitions' => 2, 'interval_days' => 6, 'easiness_factor' => 2.50]);

    engine()->applyReview($rep, 5, $now);

    // EF' = 2.60 -> round(6 * 2.60) = 16
    expect($rep->fresh())
        ->easiness_factor->toEqual(2.60)
        ->repetitions->toBe(3)
        ->interval_days->toBe(16)
        ->is_hard->toBeFalse();
});

it('still allows q=3 ("Hard" pass) on a review card with a -0.14 EF penalty', function () use ($now): void {
    $rep = makeReviewRepetition(['repetitions' => 2, 'interval_days' => 6, 'easiness_factor' => 2.50]);

    engine()->applyReview($rep, 3, $now);

    expect($rep->fresh())
        ->easiness_factor->toEqual(2.36)
        ->repetitions->toBe(3)
        ->interval_days->toBe(14)
        ->state->toBe(RepetitionState::Review);
});

// ---------------------------------------------------------------------------
// New cards: learning steps 1 min -> 10 min -> graduate (1 day)
// ---------------------------------------------------------------------------

it('moves a new card to the second learning step after the first Good answer', function () use ($now): void {
    $rep = makeRepetition();

    engine()->applyReview($rep, 4, $now);

    $fresh = $rep->fresh();
    expect($fresh)
        ->state->toBe(RepetitionState::Learning)
        ->learning_step->toBe(1)
        ->repetitions->toBe(0)
        ->interval_days->toBe(0)
        ->is_hard->toBeFalse()
        ->easiness_factor->toEqual(2.50)
        ->last_quality->toBe(4);
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addMinutes(10)->toIso8601String());
});

it('graduates a learning card to 1 day after the last step', function () use ($now): void {
    $rep = makeRepetition(['state' => RepetitionState::Learning->value, 'learning_step' => 1]);

    engine()->applyReview($rep, 4, $now);

    $fresh = $rep->fresh();
    expect($fresh)
        ->state->toBe(RepetitionState::Review)
        ->learning_step->toBe(0)
        ->repetitions->toBe(1)
        ->interval_days->toBe(1)
        ->easiness_factor->toEqual(2.50);
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addDay()->toIso8601String());
});

it('does not touch EF while a card is in learning, even on failures', function () use ($now): void {
    $rep = makeRepetition(['easiness_factor' => 2.50]);
    $engine = engine();

    $engine->applyReview($rep->fresh(), 2, $now);
    $engine->applyReview($rep->fresh(), 1, $now->addMinute());
    $engine->applyReview($rep->fresh(), 0, $now->addMinutes(2));
    $engine->applyReview($rep->fresh(), 5, $now->addMinutes(3));

    expect($rep->fresh())
        ->easiness_factor->toEqual(2.50)
        ->lapses->toBe(0);
});

it('restarts learning steps and flags hard when a new card is failed', function () use ($now): void {
    $rep = makeRepetition(['state' => RepetitionState::Learning->value, 'learning_step' => 1]);

    engine()->applyReview($rep, 2, $now);

    $fresh = $rep->fresh();
    expect($fresh)
        ->state->toBe(RepetitionState::Learning)
        ->learning_step->toBe(0)
        ->is_hard->toBeTrue()
        ->repetitions->toBe(0)
        ->lapses->toBe(0);
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addMinute()->toIso8601String());
});

it('graduates immediately with the easy interval when a learning card is graded Easy', function () use ($now): void {
    $rep = makeRepetition();

    engine()->applyReview($rep, 5, $now);

    $fresh = $rep->fresh();
    expect($fresh)
        ->state->toBe(RepetitionState::Review)
        ->repetitions->toBe(1)
        ->interval_days->toBe(4)
        ->easiness_factor->toEqual(2.50);
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addDays(4)->toIso8601String());
});

it('walks a new card through 1 min -> 10 min -> 1 day -> 6 days -> ~15 days with Good', function () use ($now): void {
    $rep = makeRepetition();
    $engine = engine();

    $engine->applyReview($rep->fresh(), 4, $now);
    expect($rep->fresh())->state->toBe(RepetitionState::Learning)->learning_step->toBe(1);

    $engine->applyReview($rep->fresh(), 4, $now->addMinutes(10));
    expect($rep->fresh())->state->toBe(RepetitionState::Review)->interval_days->toBe(1)->repetitions->toBe(1);

    $engine->applyReview($rep->fresh(), 4, $now->addDay());
    expect($rep->fresh())->interval_days->toBe(6)->repetitions->toBe(2);

    // EF stays 2.50 on Good: round(6 * 2.50) = 15
    $engine->applyReview($rep->fresh(), 4, $now->addDays(7));
    expect($rep->fresh())
        ->interval_days->toBe(15)
        ->repetitions->toBe(3)
        ->easiness_factor->toEqual(2.50);
});

it('honours configurable learning steps', function () use ($now): void {
    $policy = new LearningPolicy(
        learningStepsMinutes: [2, 15, 60],
        relearningStepsMinutes: [5],
        graduatingIntervalDays: 2,
        easyIntervalDays: 7,
        learnAheadMinutes: 60,
    );
    $engine = engine($policy);
    $rep = makeRepetition();

    $engine->applyReview($rep->fresh(), 4, $now);
    expect($rep->fresh()->next_review_at->toIso8601String())->toBe($now->addMinutes(15)->toIso8601String());

    $engine->applyReview($rep->fresh(), 4, $now);
    expect($rep->fresh()->next_review_at->toIso8601String())->toBe($now->addMinutes(60)->toIso8601String());

    $engine->applyReview($rep->fresh(), 4, $now);
    expect($rep->fresh())->state->toBe(RepetitionState::Review)->interval_days->toBe(2);
});

// ---------------------------------------------------------------------------
// Review cards: lapse -> relearning -> graduate
// ---------------------------------------------------------------------------

it('sends a mature review card to relearning on failure, penalising EF once and counting the lapse', function () use ($now): void {
    $rep = makeReviewRepetition([
        'repetitions' => 5,
        'interval_days' => 30,
        'easiness_factor' => 2.40,
    ]);

    engine()->applyReview($rep, 2, $now);

    $fresh = $rep->fresh();
    expect($fresh)
        ->state->toBe(RepetitionState::Relearning)
        ->learning_step->toBe(0)
        ->repetitions->toBe(0)
        ->interval_days->toBe(0)
        ->lapses->toBe(1)
        ->is_hard->toBeTrue()
        ->easiness_factor->toEqual(2.08)
        ->last_quality->toBe(2);
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addMinutes(10)->toIso8601String());
});

it('does not penalise EF again while relearning, and graduates back to review after the step', function () use ($now): void {
    $rep = makeRepetition([
        'state' => RepetitionState::Relearning->value,
        'learning_step' => 0,
        'lapses' => 1,
        'is_hard' => true,
        'easiness_factor' => 2.08,
    ]);
    $engine = engine();

    // Fails again inside relearning: back to step 0, EF untouched, lapses unchanged.
    $engine->applyReview($rep->fresh(), 2, $now);
    expect($rep->fresh())
        ->state->toBe(RepetitionState::Relearning)
        ->easiness_factor->toEqual(2.08)
        ->lapses->toBe(1)
        ->is_hard->toBeTrue();

    // Passes the single relearning step: graduated, 1 day, still flagged hard.
    $engine->applyReview($rep->fresh(), 4, $now->addMinutes(10));
    $fresh = $rep->fresh();
    expect($fresh)
        ->state->toBe(RepetitionState::Review)
        ->repetitions->toBe(1)
        ->interval_days->toBe(1)
        ->is_hard->toBeTrue()
        ->easiness_factor->toEqual(2.08);
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addMinutes(10)->addDay()->toIso8601String());
});

it('clears is_hard only when a graduated card passes a review-state answer', function () use ($now): void {
    $rep = makeReviewRepetition(['is_hard' => true, 'repetitions' => 1, 'interval_days' => 1, 'last_quality' => 2]);

    engine()->applyReview($rep, 4, $now);

    expect($rep->fresh())
        ->is_hard->toBeFalse()
        ->repetitions->toBe(2)
        ->interval_days->toBe(6);
});

it('treats a legacy failed row (repetitions=0, backfilled relearning) like the old SM-2 next-day pass', function () use ($now): void {
    // Pre-migration shape: failed yesterday, interval 1, due now, state backfilled to relearning.
    $rep = makeRepetition([
        'state' => RepetitionState::Relearning->value,
        'repetitions' => 0,
        'interval_days' => 1,
        'is_hard' => true,
        'last_quality' => 2,
        'next_review_at' => $now,
    ]);

    engine()->applyReview($rep, 4, $now);

    expect($rep->fresh())
        ->state->toBe(RepetitionState::Review)
        ->repetitions->toBe(1)
        ->interval_days->toBe(1);
});

it('sets last_reviewed_at and clears the learn-ahead ticket on every answer', function () use ($now): void {
    $rep = makeRepetition(['state' => RepetitionState::Learning->value, 'learning_step' => 1, 'served_ahead_at' => $now]);

    engine()->applyReview($rep, 4, $now);

    $fresh = $rep->fresh();
    expect($fresh->last_reviewed_at?->toIso8601String())->toBe($now->toIso8601String());
    expect($fresh->served_ahead_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// LearningPolicy guards
// ---------------------------------------------------------------------------

it('parses comma-separated steps from config and rejects invalid ones', function (): void {
    $policy = LearningPolicy::fromConfig([
        'learning_steps' => ' 1, 10 ,',
        'relearning_steps' => [5, '20'],
        'graduating_interval_days' => 1,
        'easy_interval_days' => 4,
        'learn_ahead_minutes' => 20,
    ]);

    expect($policy->learningStepsMinutes)->toBe([1, 10]);
    expect($policy->relearningStepsMinutes)->toBe([5, 20]);

    expect(fn () => LearningPolicy::fromConfig(['learning_steps' => '']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => LearningPolicy::fromConfig(['learning_steps' => '0,10']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => LearningPolicy::fromConfig(['learning_steps' => 'abc']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a learn-ahead window shorter than the largest step, but allows 0 (disabled) and >= max', function (): void {
    $base = [
        'learning_steps' => '1,10,60',
        'relearning_steps' => '10',
        'graduating_interval_days' => 1,
        'easy_interval_days' => 4,
    ];

    // The misconfiguration from the review: LEARNING_STEPS=1,10,60 with the default 20-minute window.
    expect(fn () => LearningPolicy::fromConfig([...$base, 'learn_ahead_minutes' => 20]))
        ->toThrow(InvalidArgumentException::class, 'learn_ahead_minutes (20) must be 0 (disabled) or >= the largest learning/relearning step (60 min)');

    expect(LearningPolicy::fromConfig([...$base, 'learn_ahead_minutes' => 60])->learnAheadMinutes)->toBe(60);
    expect(LearningPolicy::fromConfig([...$base, 'learn_ahead_minutes' => 0])->learnAheadMinutes)->toBe(0);
    expect(LearningPolicy::fromConfig([...$base, 'learn_ahead_minutes' => 0])->maxStepMinutes())->toBe(60);

    // The largest step may live in relearning_steps.
    expect(fn () => LearningPolicy::fromConfig([...$base, 'learning_steps' => '1,10', 'relearning_steps' => '30', 'learn_ahead_minutes' => 20]))
        ->toThrow(InvalidArgumentException::class, '(30 min)');
});

it('validates abandoned_after_hours and defaults it to 12', function (): void {
    $base = ['learning_steps' => '1,10', 'relearning_steps' => '10', 'learn_ahead_minutes' => 20];

    expect(LearningPolicy::fromConfig($base)->abandonedAfterHours)->toBe(12);
    expect(LearningPolicy::fromConfig([...$base, 'abandoned_after_hours' => 1])->abandonedAfterHours)->toBe(1);

    expect(fn () => LearningPolicy::fromConfig([...$base, 'abandoned_after_hours' => 0]))
        ->toThrow(InvalidArgumentException::class, 'abandoned_after_hours must be >= 1');
    // Must be strictly longer than the largest step, otherwise a card in a live session counts as abandoned.
    expect(fn () => LearningPolicy::fromConfig([...$base, 'learning_steps' => '1,120', 'learn_ahead_minutes' => 120, 'abandoned_after_hours' => 2]))
        ->toThrow(InvalidArgumentException::class, 'abandoned_after_hours (2 h) must be longer');
});

it('fails fast at boot when the learning config is inconsistent', function (): void {
    config([
        'learning.learning_steps' => '1,10,60',
        'learning.relearning_steps' => '10',
        'learning.learn_ahead_minutes' => 20,
    ]);
    app()->forgetInstance(LearningPolicy::class);

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, 'LEARNING_LEARN_AHEAD_MINUTES');

    // Consistent config boots and yields the singleton.
    config(['learning.learn_ahead_minutes' => 60]);
    app()->forgetInstance(LearningPolicy::class);
    (new AppServiceProvider(app()))->boot();
    expect(app(LearningPolicy::class)->learnAheadMinutes)->toBe(60);
});

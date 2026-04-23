<?php

declare(strict_types=1);

use App\Domain\Learning\Services\SpacedRepetitionEngine;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeRepetition(array $overrides = []): WordRepetition
{
    $group  = TelegramGroup::factory()->create();
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);
    $stage  = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    $word   = Word::factory()->for($lesson)->create();

    return WordRepetition::factory()->create(array_merge([
        'student_id'      => $student->id,
        'word_id'         => $word->id,
        'repetitions'     => 0,
        'interval_days'   => 0,
        'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
        'last_quality'    => null,
        'is_hard'         => false,
    ], $overrides));
}

it('resets interval and flags hard when quality < 3 on a mature card', function (): void {
    $rep = makeRepetition([
        'repetitions'     => 5,
        'interval_days'   => 30,
        'easiness_factor' => 2.40,
    ]);

    $now = CarbonImmutable::parse('2026-04-23 12:00:00');

    app(SpacedRepetitionEngine::class)->applyReview($rep, 2, $now);

    expect($rep->fresh())
        ->repetitions->toBe(0)
        ->interval_days->toBe(1)
        ->is_hard->toBeTrue()
        ->last_quality->toBe(2)
        ->and($rep->fresh()->next_review_at->toIso8601String())->toBe($now->addDay()->toIso8601String());
});

it('uses interval=1 on first successful repetition', function (): void {
    $rep = makeRepetition(['repetitions' => 0, 'interval_days' => 0]);

    app(SpacedRepetitionEngine::class)->applyReview($rep, 3);

    expect($rep->fresh())
        ->repetitions->toBe(1)
        ->interval_days->toBe(1)
        ->is_hard->toBeFalse();
});

it('uses interval=6 on second successful repetition', function (): void {
    $rep = makeRepetition(['repetitions' => 1, 'interval_days' => 1]);

    app(SpacedRepetitionEngine::class)->applyReview($rep, 4);

    expect($rep->fresh())
        ->repetitions->toBe(2)
        ->interval_days->toBe(6);
});

it('multiplies interval by EF from third repetition onwards', function (): void {
    $rep = makeRepetition([
        'repetitions'     => 2,
        'interval_days'   => 6,
        'easiness_factor' => 2.50,
    ]);

    app(SpacedRepetitionEngine::class)->applyReview($rep, 5);

    // EF' = 2.50 + (0.1 - 0 * ...) = 2.60 -> round(6 * 2.60) = 16
    expect($rep->fresh())
        ->repetitions->toBe(3)
        ->interval_days->toBe(16)
        ->easiness_factor->toEqual(2.60);
});

it('never drops EF below 1.30 even on repeated failures', function (): void {
    $engine = app(SpacedRepetitionEngine::class);

    $ef = 1.30;
    for ($i = 0; $i < 20; $i++) {
        $ef = $engine->newEasinessFactor($ef, 0);
    }

    expect($ef)->toBe(SpacedRepetitionEngine::MIN_EF);
});

it('updates EF with standard SM-2 delta for each quality', function (): void {
    $engine = app(SpacedRepetitionEngine::class);

    // delta formula: 0.1 - (5-q) * (0.08 + (5-q) * 0.02)
    // q=5 -> +0.10
    // q=4 -> 0.1 - 1*(0.08 + 0.02) = 0.00
    // q=3 -> 0.1 - 2*(0.08 + 0.04) = -0.14
    // q=2 -> 0.1 - 3*(0.08 + 0.06) = -0.32
    // q=1 -> 0.1 - 4*(0.08 + 0.08) = -0.54
    // q=0 -> 0.1 - 5*(0.08 + 0.10) = -0.80
    expect($engine->newEasinessFactor(2.50, 5))->toEqualWithDelta(2.60, 0.0001);
    expect($engine->newEasinessFactor(2.50, 4))->toEqualWithDelta(2.50, 0.0001);
    expect($engine->newEasinessFactor(2.50, 3))->toEqualWithDelta(2.36, 0.0001);
    expect($engine->newEasinessFactor(2.50, 2))->toEqualWithDelta(2.18, 0.0001);
    expect($engine->newEasinessFactor(2.50, 1))->toEqualWithDelta(1.96, 0.0001);
    expect($engine->newEasinessFactor(2.50, 0))->toEqualWithDelta(1.70, 0.0001);
});

it('throws on quality out of 0..5 range', function (int $q): void {
    $rep = makeRepetition();
    app(SpacedRepetitionEngine::class)->applyReview($rep, $q);
})->with([-1, 6, 99, -100])->throws(InvalidArgumentException::class);

it('advances the full 3-step cycle producing intervals 1, 6, ~15', function (): void {
    $rep = makeRepetition([
        'repetitions'     => 0,
        'interval_days'   => 0,
        'easiness_factor' => SpacedRepetitionEngine::DEFAULT_EF,
    ]);

    $engine = app(SpacedRepetitionEngine::class);

    $engine->applyReview($rep, 3);
    expect($rep->fresh())->interval_days->toBe(1)->repetitions->toBe(1);

    $engine->applyReview($rep->fresh(), 3);
    expect($rep->fresh())->interval_days->toBe(6)->repetitions->toBe(2);

    // q=3 delta = -0.14. After 3rd review EF = 2.50 - 0.14*3 = 2.08 (rounded).
    // interval = round(6 * EF_new) = round(6 * 2.08) = 12.
    $engine->applyReview($rep->fresh(), 3);
    expect($rep->fresh())
        ->interval_days->toBe(12)
        ->repetitions->toBe(3)
        ->easiness_factor->toEqual(2.08);
});

it('sets last_reviewed_at and next_review_at based on provided now()', function (): void {
    $rep = makeRepetition(['repetitions' => 1, 'interval_days' => 1]);

    $now = CarbonImmutable::parse('2026-04-23 10:00:00');
    app(SpacedRepetitionEngine::class)->applyReview($rep, 3, $now);

    $fresh = $rep->fresh();
    expect($fresh->last_reviewed_at->toIso8601String())->toBe($now->toIso8601String());
    expect($fresh->next_review_at->toIso8601String())->toBe($now->addDays(6)->toIso8601String());
});

it('clears is_hard flag after a successful review on a previously-hard card', function (): void {
    $rep = makeRepetition([
        'repetitions'     => 0,
        'interval_days'   => 1,
        'is_hard'         => true,
        'last_quality'    => 2,
    ]);

    app(SpacedRepetitionEngine::class)->applyReview($rep, 5);

    expect($rep->fresh())->is_hard->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\DoneReason;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\TrainingSession;
use App\Models\Word;
use Carbon\CarbonImmutable;

// --- TrainingScope ------------------------------------------------------------

it('derives the scope from the session mode', function (): void {
    $group = new TrainingSession(['mode' => 'group_lesson', 'lesson_id' => 7]);
    $lesson = new TrainingSession(['mode' => 'lesson', 'lesson_id' => 8]);
    $daily = new TrainingSession(['mode' => 'daily_review', 'course_id' => 3]);
    $dailyNoCourse = new TrainingSession(['mode' => 'daily_review']);

    expect(TrainingScope::forSession($group)->isLesson())->toBeTrue()
        ->and(TrainingScope::forSession($group)->lessonId)->toBe(7)
        ->and(TrainingScope::forSession($lesson)->lessonId)->toBe(8)
        ->and(TrainingScope::forSession($lesson)->includesNew())->toBeTrue()
        ->and(TrainingScope::forSession($daily)->isDaily())->toBeTrue()
        ->and(TrainingScope::forSession($daily)->courseId)->toBe(3)
        ->and(TrainingScope::forSession($daily)->includesNew())->toBeTrue()
        ->and(TrainingScope::forSession($dailyNoCourse)->includesNew())->toBeFalse()
        ->and(TrainingScope::forSession($dailyNoCourse)->newWordCandidates(1))->toBeNull();
});

it('refuses a lesson-mode session without a lesson (mode_shape invariant)', function (): void {
    expect(fn () => TrainingScope::forSession(new TrainingSession(['mode' => 'lesson'])))
        ->toThrow(LogicException::class);
});

it('accepts a word in scope: same lesson for lesson scope, any active word for daily scope', function (): void {
    $inLesson = new Word(['lesson_id' => 5, 'is_active' => true]);
    $otherLesson = new Word(['lesson_id' => 6, 'is_active' => true]);
    $inactive = new Word(['lesson_id' => 5, 'is_active' => false]);

    $lesson = TrainingScope::lesson(5);
    $daily = TrainingScope::daily(null);

    expect($lesson->contains($inLesson))->toBeTrue()
        ->and($lesson->contains($otherLesson))->toBeFalse()
        ->and($lesson->contains($inactive))->toBeFalse()
        ->and($daily->contains($inLesson))->toBeTrue()
        ->and($daily->contains($otherLesson))->toBeTrue()
        ->and($daily->contains($inactive))->toBeFalse();
});

it('names the done reason of an exhausted scope', function (): void {
    expect(TrainingScope::lesson(1)->exhaustedReason())->toBe(DoneReason::LessonComplete)
        ->and(TrainingScope::daily(2)->exhaustedReason())->toBe(DoneReason::NothingDue)
        ->and(TrainingScope::daily(null)->exhaustedReason())->toBe(DoneReason::NothingDue);
});

// --- NewCardBudget ------------------------------------------------------------

it('models the new-card budget: unlimited, fixed and lazily resolved', function (): void {
    $now = CarbonImmutable::parse('2026-10-18 10:00:00');

    expect(NewCardBudget::unlimited()->isUnlimited())->toBeTrue()
        ->and(NewCardBudget::unlimited()->remaining(1, $now))->toBeNull()
        ->and(NewCardBudget::of(3)->remaining(1, $now))->toBe(3)
        ->and(NewCardBudget::of(-2)->remaining(1, $now))->toBe(0);

    $seen = [];
    $lazy = NewCardBudget::lazy(function (int $learnerId, CarbonImmutable $at) use (&$seen): ?int {
        $seen[] = [$learnerId, $at];

        return $learnerId === 42 ? null : 5;
    });

    expect($lazy->isUnlimited())->toBeFalse()
        ->and($lazy->remaining(42, $now))->toBeNull()
        ->and($lazy->remaining(7, $now))->toBe(5)
        ->and($seen)->toBe([[42, $now], [7, $now]]);
});

// --- mode_shape invariant (service-side mirror of the PG CHECK) ---------------

it('enforces the training_sessions mode_shape invariant for every driver', function (): void {
    TrainingSessionService::assertModeShape(['mode' => 'group_lesson', 'telegram_group_id' => 1, 'lesson_id' => 2]);
    TrainingSessionService::assertModeShape(['mode' => 'lesson', 'learner_id' => 1, 'lesson_id' => 2]);
    TrainingSessionService::assertModeShape(['mode' => 'daily_review', 'learner_id' => 1]);

    expect(fn () => TrainingSessionService::assertModeShape(['mode' => 'group_lesson', 'telegram_group_id' => 1]))
        ->toThrow(LogicException::class)
        ->and(fn () => TrainingSessionService::assertModeShape(['mode' => 'group_lesson', 'lesson_id' => 2]))
        ->toThrow(LogicException::class)
        ->and(fn () => TrainingSessionService::assertModeShape(['mode' => 'lesson', 'lesson_id' => 2]))
        ->toThrow(LogicException::class)
        ->and(fn () => TrainingSessionService::assertModeShape(['mode' => 'daily_review']))
        ->toThrow(LogicException::class)
        ->and(fn () => TrainingSessionService::assertModeShape(['mode' => 'exam', 'learner_id' => 1]))
        ->toThrow(LogicException::class);
});

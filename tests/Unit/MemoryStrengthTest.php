<?php

declare(strict_types=1);

/*
 * "Memory strength" 0–5 (docs/40 §2.2, docs/06 §13.3a): every value, the edges of the thresholds, the agreement with the stage of the
 * word (WordStage) and the days to the next review. The same rule as SQL is checked against the database in TwaVocabularyTest.
 */

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordStage;
use App\Domain\Learning\Support\MemoryStrength;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['learning.stages' => ['fresh_review_days' => 3, 'known_from_interval_days' => 7, 'mastered_from_interval_days' => 21]]);
});

function memRow(RepetitionState $state, int $interval, ?int $quality, ?string $next = null): WordRepetition
{
    return (new WordRepetition)->forceFill(['state' => $state, 'interval_days' => $interval, 'last_quality' => $quality, 'next_review_at' => $next]);
}

it('gives every strength of the table, at the edges of the thresholds', function (RepetitionState $state, int $interval, ?int $quality, int $strength, WordStage $stage): void {
    $row = memRow($state, $interval, $quality);

    expect(MemoryStrength::of($state, $interval, $quality))->toBe($strength)
        ->and(MemoryStrength::ofRepetition($row))->toBe($strength)
        ->and(WordStage::of($row))->toBe($stage);
})->with([
    'new, never graded' => [RepetitionState::New, 0, null, 0, WordStage::New],
    'new, graded (a failed first meeting)' => [RepetitionState::New, 0, 1, 1, WordStage::New],
    'learning steps' => [RepetitionState::Learning, 0, 4, 1, WordStage::New],
    'learning steps, no grade stored' => [RepetitionState::Learning, 0, null, 1, WordStage::New],
    'relearning after a lapse' => [RepetitionState::Relearning, 0, 2, 1, WordStage::Learning],
    'review, 1 day' => [RepetitionState::Review, 1, 4, 2, WordStage::Learning],
    'review, 2 days (below fresh)' => [RepetitionState::Review, 2, 4, 2, WordStage::Learning],
    'review, 3 days (fresh)' => [RepetitionState::Review, 3, 4, 3, WordStage::Learning],
    'review, 6 days (below known)' => [RepetitionState::Review, 6, 5, 3, WordStage::Learning],
    'review, 7 days (known)' => [RepetitionState::Review, 7, 5, 4, WordStage::Known],
    'review, 20 days (below mastered)' => [RepetitionState::Review, 20, 5, 4, WordStage::Known],
    'review, 21 days (mastered)' => [RepetitionState::Review, 21, 5, 5, WordStage::Mastered],
    'review, 400 days' => [RepetitionState::Review, 400, 5, 5, WordStage::Mastered],
]);

it('never contradicts the stage: 0-1 is new (or learning after a lapse), 2-3 learning, 4 known, 5 mastered', function (): void {
    $allowed = [0 => ['new'], 1 => ['new', 'learning'], 2 => ['learning'], 3 => ['learning'], 4 => ['known'], 5 => ['mastered']];

    foreach (RepetitionState::cases() as $state) {
        foreach ([null, 0, 3, 5] as $quality) {
            for ($interval = 0; $interval <= 60; $interval++) {
                $row = memRow($state, $state === RepetitionState::Review ? max(1, $interval) : 0, $quality);
                expect(WordStage::of($row)->value)->toBeIn($allowed[MemoryStrength::ofRepetition($row)], "{$state->value}, {$interval} days, quality ".var_export($quality, true));
            }
        }
    }
});

it('follows the configured thresholds and keeps fresh within known', function (): void {
    config(['learning.stages' => ['fresh_review_days' => 2, 'known_from_interval_days' => 10, 'mastered_from_interval_days' => 30]]);
    expect(MemoryStrength::of(RepetitionState::Review, 2, 4))->toBe(3)
        ->and(MemoryStrength::of(RepetitionState::Review, 9, 4))->toBe(3)
        ->and(MemoryStrength::of(RepetitionState::Review, 10, 4))->toBe(4)
        ->and(MemoryStrength::of(RepetitionState::Review, 30, 4))->toBe(5);

    // A fresh threshold above "known" is clamped to it: strength 3 then has no days of its own, 4 still starts at "known".
    config(['learning.stages' => ['fresh_review_days' => 9, 'known_from_interval_days' => 7, 'mastered_from_interval_days' => 21]]);
    expect(MemoryStrength::thresholds())->toBe(['fresh' => 7, 'known' => 7, 'mastered' => 21])
        ->and(MemoryStrength::of(RepetitionState::Review, 6, 4))->toBe(2)
        ->and(MemoryStrength::of(RepetitionState::Review, 7, 4))->toBe(4);
});

it('counts whole days to the next review of a review card, and none for a card in the steps', function (): void {
    $now = CarbonImmutable::parse('2026-11-27 12:00:00');

    expect(MemoryStrength::nextInDays(RepetitionState::Review, $now->addDays(4), $now))->toBe(4)
        ->and(MemoryStrength::nextInDays(RepetitionState::Review, $now->addDays(3)->addMinute(), $now))->toBe(4)
        ->and(MemoryStrength::nextInDays(RepetitionState::Review, $now->addSecond(), $now))->toBe(1)
        ->and(MemoryStrength::nextInDays(RepetitionState::Review, $now, $now))->toBe(0)
        ->and(MemoryStrength::nextInDays(RepetitionState::Review, $now->subDays(2), $now))->toBe(0)
        ->and(MemoryStrength::nextInDays(RepetitionState::Review, null, $now))->toBeNull()
        ->and(MemoryStrength::nextInDays(RepetitionState::Learning, $now->addMinutes(10), $now))->toBeNull()
        ->and(MemoryStrength::nextInDays(RepetitionState::Relearning, $now->addMinutes(10), $now))->toBeNull()
        ->and(MemoryStrength::nextInDays(RepetitionState::New, $now, $now))->toBeNull();

    expect(MemoryStrength::reading(memRow(RepetitionState::Review, 12, 5, $now->addDays(12)->toDateTimeString()), $now))
        ->toBe(['strength' => 4, 'stage' => 'known', 'next_in_days' => 12]);
});

it('puts the thresholds of the SQL rule into bindings, never into the text', function (): void {
    config(['learning.stages' => ['fresh_review_days' => 13, 'known_from_interval_days' => 17, 'mastered_from_interval_days' => 29]]);

    $case = MemoryStrength::sqlCase();
    $weak = MemoryStrength::weakCondition();
    $strong = MemoryStrength::strongCondition();
    expect($case['bindings'])->toBe(['new', 'review', 13, 17, 29])
        ->and(substr_count($case['sql'], '?'))->toBe(5)
        ->and($weak['bindings'])->toBe(['review', 13])
        ->and($strong['bindings'])->toBe(['review', 17]);
    foreach ([$case['sql'], $weak['sql'], $strong['sql']] as $sql) {
        expect($sql)->not->toMatch('/13|17|29|review|new/');
    }
});

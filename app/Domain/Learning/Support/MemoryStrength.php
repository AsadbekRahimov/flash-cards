<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\WordStage;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * "Memory strength" 0–5 of a word (docs/40 §2.2, docs/06 §13.3a): the five dots under the flash card (F1) and in "My vocabulary" (F2).
 * Only a view of the row of `word_repetitions` - no column, no query, the schedule (SM-2 / FSRS) is not touched:
 *
 *   0  `new`, never graded (`last_quality IS NULL`)
 *   1  in the minute steps - `new` / `learning` with a grade - or `relearning`
 *   2  `review`, `interval_days < learning.stages.fresh_review_days` (3)
 *   3  `review`, from `fresh_review_days` up to `known_from_interval_days` (7)
 *   4  `review`, from `known_from_interval_days` up to `mastered_from_interval_days` (21)
 *   5  `review`, from `mastered_from_interval_days` on
 *
 * The thresholds are those of {@see WordStage}, so a strength never contradicts the stage: 0–1 is `new` (or `learning` for a lapsed
 * card), 2–3 `learning`, 4 `known`, 5 `mastered`. {@see sqlCase()} is the same rule as SQL for the vocabulary - the thresholds go as
 * bindings, never into the text.
 */
final class MemoryStrength
{
    public const MAX = 5;

    /** Strength at or below which a word is "weak" in the vocabulary filter (docs/40 §2.3). */
    public const WEAK_MAX = 2;

    /** Strength from which a word is "strong" in the vocabulary filter. */
    public const STRONG_MIN = 4;

    /** @return array{fresh: int, known: int, mastered: int} the interval thresholds (days), each at least 1 and in order */
    public static function thresholds(): array
    {
        $stages = WordStage::thresholds();
        $fresh = min($stages['known'], max(1, (int) config('learning.stages.fresh_review_days', 3)));

        return ['fresh' => $fresh, 'known' => $stages['known'], 'mastered' => $stages['mastered']];
    }

    /** @return int<0, 5> */
    public static function of(RepetitionState $state, int $intervalDays, ?int $lastQuality): int
    {
        if ($state !== RepetitionState::Review) {
            return $state === RepetitionState::New && $lastQuality === null ? 0 : 1;
        }

        $t = self::thresholds();

        return match (true) {
            $intervalDays >= $t['mastered'] => 5,
            $intervalDays >= $t['known'] => 4,
            $intervalDays >= $t['fresh'] => 3,
            default => 2,
        };
    }

    /** @return int<0, 5> */
    public static function ofRepetition(WordRepetition $repetition): int
    {
        return self::of($repetition->state, (int) $repetition->interval_days, $repetition->last_quality);
    }

    /**
     * Whole days until the next day-based review: `max(0, ceil((next_review_at - now) / day))` for a `review` card; null for a card in
     * the minute steps (the client says "again in this session") or without a date.
     */
    public static function nextInDays(RepetitionState $state, ?CarbonImmutable $nextReviewAt, CarbonImmutable $now): ?int
    {
        if ($state !== RepetitionState::Review || $nextReviewAt === null) {
            return null;
        }

        $seconds = $nextReviewAt->getTimestamp() - $now->getTimestamp();

        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86_400);
    }

    /**
     * `card.memory` of the training screen: the strength BEFORE the grade, the stage and the days to the next review.
     *
     * @return array{strength: int<0, 5>, stage: string, next_in_days: int|null}
     */
    public static function reading(WordRepetition $repetition, CarbonImmutable $now): array
    {
        return [
            'strength' => self::ofRepetition($repetition),
            'stage' => WordStage::of($repetition)->value,
            'next_in_days' => self::nextInDays($repetition->state, $repetition->next_review_at, $now),
        ];
    }

    /**
     * The strength as a SQL `CASE` over the columns of a row of `word_repetitions` (qualified names, e.g. `wr.state`): the same rule
     * as {@see of()}, the states and the thresholds as bindings in the order of `?`.
     *
     * @param  literal-string  $state
     * @param  literal-string  $interval
     * @param  literal-string  $quality
     * @return array{sql: literal-string, bindings: list<int|string>}
     */
    public static function sqlCase(string $state = 'wr.state', string $interval = 'wr.interval_days', string $quality = 'wr.last_quality'): array
    {
        $t = self::thresholds();

        return [
            'sql' => "CASE WHEN {$state} = ? AND {$quality} IS NULL THEN 0"
                ." WHEN {$state} <> ? THEN 1"
                ." WHEN {$interval} < ? THEN 2"
                ." WHEN {$interval} < ? THEN 3"
                ." WHEN {$interval} < ? THEN 4"
                .' ELSE 5 END',
            'bindings' => [RepetitionState::New->value, RepetitionState::Review->value, $t['fresh'], $t['known'], $t['mastered']],
        ];
    }

    /**
     * "Weak" (strength ≤ {@see WEAK_MAX}) as a SQL condition: not graduated yet, or graduated with an interval below `fresh`.
     *
     * @param  literal-string  $state
     * @param  literal-string  $interval
     * @return array{sql: literal-string, bindings: list<int|string>}
     */
    public static function weakCondition(string $state = 'wr.state', string $interval = 'wr.interval_days'): array
    {
        return ['sql' => "({$state} <> ? OR {$interval} < ?)", 'bindings' => [RepetitionState::Review->value, self::thresholds()['fresh']]];
    }

    /**
     * "Strong" (strength ≥ {@see STRONG_MIN}) as a SQL condition: graduated with an interval from `known` on.
     *
     * @param  literal-string  $state
     * @param  literal-string  $interval
     * @return array{sql: literal-string, bindings: list<int|string>}
     */
    public static function strongCondition(string $state = 'wr.state', string $interval = 'wr.interval_days'): array
    {
        return ['sql' => "({$state} = ? AND {$interval} >= ?)", 'bindings' => [RepetitionState::Review->value, self::thresholds()['known']]];
    }
}

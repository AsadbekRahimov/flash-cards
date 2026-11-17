<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * The one formula of "bring the repetition closer" (docs/06 §5a, §14; docs/18 §12.4): ONE conditional UPDATE of the learner's own
 * rows that are due later than now,
 *
 *   UPDATE word_repetitions SET next_review_at = now [, is_hard = true], updated_at = now
 *    WHERE learner_id = :me AND word_id IN (:ids) AND next_review_at > now
 *
 * and nothing else: not `state`, `repetitions`, `interval_days`, `easiness_factor`, `lapses`, `stability`, `difficulty`,
 * `scheduled_days`, `last_reviewed_at`, `served_ahead_*`, and no `training_reviews`. It can only bring a repetition closer, never push
 * it away, and a repeat finds nothing to move. The mistake of the game marks the word hard (`$markHard`); the round of hard words
 * does not - nothing failed, it only makes the chosen words due, so the ordinary path of a due card takes their answers.
 */
final class ReviewPull
{
    /**
     * @param  list<int>  $wordIds
     * @return list<int> the words whose repetition was really brought closer (`next_review_at` was in the future)
     */
    public function toNow(int $learnerId, array $wordIds, CarbonImmutable $now, bool $markHard): array
    {
        $wordIds = array_values(array_unique(array_map('intval', $wordIds)));
        if ($wordIds === []) {
            return [];
        }

        // Which rows the UPDATE will move: read first (the UPDATE itself returns a count only).
        /** @var list<int> $pulled */
        $pulled = WordRepetition::query()
            ->where('learner_id', $learnerId)
            ->whereIn('word_id', $wordIds)
            ->where('next_review_at', '>', $now)
            ->pluck('word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($pulled === []) {
            return [];
        }

        WordRepetition::query()
            ->where('learner_id', $learnerId)
            ->whereIn('word_id', $pulled)
            ->where('next_review_at', '>', $now)
            ->update($markHard
                ? ['next_review_at' => $now, 'is_hard' => true, 'updated_at' => $now]
                : ['next_review_at' => $now, 'updated_at' => $now]);

        return $pulled;
    }
}

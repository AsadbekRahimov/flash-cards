<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Models\GameWordMiss;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * The one rule of the game about the schedule (docs/18 §5, §12.4; docs/06 §5a): a mistake in a run brings the word's repetition
 * closer - never further - and marks it hard. ONE conditional UPDATE of the learner's own rows, after the commit of the answer
 * (the transaction of a run never touches `word_repetitions`, rule 17), plus the counter of misses per word (`game_word_misses`,
 * the input of the boss).
 *
 *   UPDATE word_repetitions SET next_review_at = now, is_hard = true, updated_at = now
 *    WHERE learner_id = :me AND word_id IN (:ids) AND next_review_at > now
 *
 * Nothing else is written: not `state`, `repetitions`, `interval_days`, `easiness_factor`, `lapses`, `stability`, `difficulty`,
 * `scheduled_days`, `last_reviewed_at`, `served_ahead_*`, and no `training_reviews`. A correct answer calls nothing. Idempotent: a
 * repeat finds `next_review_at <= now` and changes nothing; `misses` grows once per accepted mistake because only the first accepted
 * request reaches here (a repeated `request_id` answers from the journal).
 */
final class GameSrsAdjuster
{
    /**
     * @param  list<int>  $wordIds  the words of the mistake (an authored task: all its target words)
     * @return int how many repetitions were really brought closer (`next_review_at` was in the future)
     */
    public function pull(int $learnerId, array $wordIds, CarbonImmutable $now): int
    {
        $wordIds = array_values(array_unique(array_map('intval', $wordIds)));
        if ($wordIds === []) {
            return 0;
        }

        // Which rows the UPDATE will move: read first, so the misses know who was pulled (the UPDATE itself returns a count only).
        /** @var list<int> $pulled */
        $pulled = WordRepetition::query()
            ->where('learner_id', $learnerId)
            ->whereIn('word_id', $wordIds)
            ->where('next_review_at', '>', $now)
            ->pluck('word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $moved = 0;
        if ($pulled !== []) {
            $moved = WordRepetition::query()
                ->where('learner_id', $learnerId)
                ->whereIn('word_id', $pulled)
                ->where('next_review_at', '>', $now)
                ->update(['next_review_at' => $now, 'is_hard' => true, 'updated_at' => $now]);
        }

        $this->countMisses($learnerId, $wordIds, $pulled, $now);

        return $moved;
    }

    /**
     * `misses + 1` for every word, `last_pulled_at = now` for the pulled ones: the missing rows are made first (`insertOrIgnore` with
     * 0), then the builder's own atomic `increment()` - no raw SQL, and two hooks on the same word (a left word of pairs tried twice)
     * cannot lose a count.
     *
     * @param  list<int>  $wordIds
     * @param  list<int>  $pulled
     */
    private function countMisses(int $learnerId, array $wordIds, array $pulled, CarbonImmutable $now): void
    {
        $rows = [];
        foreach ($wordIds as $wordId) {
            $rows[] = ['learner_id' => $learnerId, 'word_id' => $wordId, 'misses' => 0, 'last_missed_at' => $now, 'last_pulled_at' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        GameWordMiss::query()->insertOrIgnore($rows);

        GameWordMiss::query()
            ->where('learner_id', $learnerId)
            ->whereIn('word_id', $wordIds)
            ->increment('misses', 1, ['last_missed_at' => $now, 'updated_at' => $now]);

        if ($pulled !== []) {
            GameWordMiss::query()
                ->where('learner_id', $learnerId)
                ->whereIn('word_id', $pulled)
                ->update(['last_pulled_at' => $now]);
        }
    }
}

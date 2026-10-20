<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\LearnerStats;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Models\Learner;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

final class LearnerStatsService
{
    public function __construct(private readonly DueRepetitionCriteria $due) {}

    /**
     * `words_due_today` uses the same rule as review reminders
     * (DueRepetitionCriteria, docs/06 §6): graduated cards that are due plus
     * (re)learning cards abandoned long enough ago. Rows in `new` state and
     * fresh minute steps inside a session are not "due today".
     */
    public function forLearner(Learner $learner): LearnerStats
    {
        $wordsLearned = WordRepetition::query()
            ->where('learner_id', $learner->id)
            ->where('repetitions', '>=', 1)
            ->count();

        return new LearnerStats($wordsLearned, $this->dueCount($learner));
    }

    /**
     * `words_learned` of many learners in one query (the same rule as {@see forLearner()}); a learner
     * without a learned word is present with 0.
     *
     * @param  list<int>  $learnerIds
     * @return array<int, int>
     */
    public function wordsLearnedFor(array $learnerIds): array
    {
        $counts = array_fill_keys($learnerIds, 0);
        if ($learnerIds === []) {
            return $counts;
        }

        $rows = WordRepetition::query()
            ->whereIn('learner_id', $learnerIds)
            ->where('repetitions', '>=', 1)
            ->selectRaw('learner_id, count(*) as n')
            ->groupBy('learner_id')
            ->pluck('n', 'learner_id');

        foreach ($rows as $learnerId => $n) {
            $counts[(int) $learnerId] = (int) $n;
        }

        return $counts;
    }

    /**
     * Cards the learner should come back for right now — the number shown as `words_due_today`
     * and as `due_count` of the "Today" screen.
     */
    public function dueCount(Learner $learner, ?CarbonImmutable $now = null): int
    {
        return $this->due->apply(
            WordRepetition::query()->where('learner_id', $learner->id),
            $now,
        )->count();
    }
}

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
    public function __construct(
        private readonly DueRepetitionCriteria $due,
        private readonly LearningCache $cache,
    ) {}

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
     * The EXACT `words_learned` of many learners in one query (the same rule as {@see forLearner()}); a learner
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
     * {@see wordsLearnedFor()} for OTHER learners (the friends screen), up to {@see LearningCache::wordsLearned()}'s TTL
     * old: cached per learner, and only the learners that missed are counted — in one query. The numbers lag, so
     * the learner's own figures and anything that decides something (achievements) keep using the exact
     * {@see wordsLearnedFor()}.
     *
     * @param  list<int>  $learnerIds
     * @return array<int, int> every requested id is present (0 for a learner without a learned word)
     */
    public function cachedWordsLearnedFor(array $learnerIds): array
    {
        return $this->cache->wordsLearned($learnerIds, $this->wordsLearnedFor(...));
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

<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\LearnerStats;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Models\Learner;
use App\Models\WordRepetition;

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

        $wordsDueToday = $this->due->apply(
            WordRepetition::query()->where('learner_id', $learner->id),
        )->count();

        return new LearnerStats($wordsLearned, $wordsDueToday);
    }
}

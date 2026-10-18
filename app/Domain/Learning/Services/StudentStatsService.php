<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\StudentStats;
use App\Domain\Learning\Enums\RepetitionState;
use App\Models\Student;
use App\Models\WordRepetition;

final class StudentStatsService
{
    /**
     * `words_due_today` counts graduated (`review`) cards only — the same rule
     * as review reminders (docs/06 §6). Rows in `new` state are created with
     * `next_review_at = now` the moment a card is shown, and (re)learning cards
     * are scheduled in minutes inside a session; neither is "due today".
     */
    public function forStudent(Student $student): StudentStats
    {
        $wordsLearned = WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('repetitions', '>=', 1)
            ->count();

        $wordsDueToday = WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('state', RepetitionState::Review->value)
            ->where('next_review_at', '<=', now())
            ->count();

        return new StudentStats($wordsLearned, $wordsDueToday);
    }
}

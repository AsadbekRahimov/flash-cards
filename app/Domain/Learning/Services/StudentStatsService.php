<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\StudentStats;
use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Models\Student;
use App\Models\WordRepetition;

final class StudentStatsService
{
    public function __construct(private readonly DueRepetitionCriteria $due) {}

    /**
     * `words_due_today` uses the same rule as review reminders
     * (DueRepetitionCriteria, docs/06 §6): graduated cards that are due plus
     * (re)learning cards abandoned long enough ago. Rows in `new` state and
     * fresh minute steps inside a session are not "due today".
     */
    public function forStudent(Student $student): StudentStats
    {
        $wordsLearned = WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('repetitions', '>=', 1)
            ->count();

        $wordsDueToday = $this->due->apply(
            WordRepetition::query()->where('student_id', $student->id),
        )->count();

        return new StudentStats($wordsLearned, $wordsDueToday);
    }
}

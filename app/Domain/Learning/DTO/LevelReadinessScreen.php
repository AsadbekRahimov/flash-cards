<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\Course;

/**
 * The full readiness screen of a course for a learner (docs/04 A.20): the readiness with its modules and the quiz of the week
 * (`quiz` is null when the quiz is off; `history` - the counted results by week ascending).
 */
final readonly class LevelReadinessScreen
{
    /**
     * @param  list<array{number: int, title: string|null, learned: int, total: int}>  $modules
     * @param  array{known_days: int, mastered_days: int}  $thresholds
     * @param  list<array{week_start: string, correct: int, total: int, percent: int}>  $history
     */
    public function __construct(
        public Course $course,
        public string $courseTitle,
        public LevelReadiness $readiness,
        public array $modules,
        public array $thresholds,
        public LevelQuizWeek $week,
        public int $quizQuestions,
        public int $quizSeconds,
        public array $history,
    ) {}
}

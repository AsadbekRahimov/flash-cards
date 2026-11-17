<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

/**
 * The quiz of the week for a learner and a course (docs/13 §8.5): whether it is on, the Monday of the learner's current ISO week,
 * and their counted result of this week (null - not taken yet).
 */
final readonly class LevelQuizWeek
{
    /** @param  array{correct: int, total: int, percent: int, finished_at: string|null}|null  $thisWeek */
    public function __construct(
        public bool $enabled,
        public string $weekStart,
        public ?array $thisWeek,
    ) {}

    /** @return array{enabled: bool}|array{enabled: true, week_start: string, this_week: array{correct: int, total: int, percent: int}|null} the compact block of "Today" */
    public function toTodayArray(): array
    {
        if (! $this->enabled) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'week_start' => $this->weekStart,
            'this_week' => $this->thisWeek === null ? null : [
                'correct' => $this->thisWeek['correct'],
                'total' => $this->thisWeek['total'],
                'percent' => $this->thisWeek['percent'],
            ],
        ];
    }
}

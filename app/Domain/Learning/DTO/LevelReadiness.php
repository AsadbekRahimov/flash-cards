<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

/**
 * Readiness of a learner to the level of a course (docs/13 §8.5): the share of the course's active words they have LEARNED -
 * a word in `review` whose interval has reached the "known" threshold. `percent` is the floor of the share; `known` is
 * `learned - mastered`. A vocabulary estimate, not a promise of any exam result.
 */
final readonly class LevelReadiness
{
    public function __construct(
        public int $courseId,
        public ?string $levelLabel,
        public int $total,
        public int $learned,
        public int $mastered,
        public int $known,
        public int $inProgress,
        public int $notStarted,
        public int $percent,
    ) {}

    /** @return array{level_label: string|null, percent: int, words: array{total: int, learned: int, mastered: int, in_progress: int, not_started: int}} */
    public function toArray(): array
    {
        return [
            'level_label' => $this->levelLabel,
            'percent' => $this->percent,
            'words' => [
                'total' => $this->total,
                'learned' => $this->learned,
                'mastered' => $this->mastered,
                'in_progress' => $this->inProgress,
                'not_started' => $this->notStarted,
            ],
        ];
    }
}

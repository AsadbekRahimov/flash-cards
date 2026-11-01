<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * Where the practice of a finished training session leads (docs/16 §11.1): the hub of a course (`lessonId = null`) or of a
 * lesson. `course` is the slug the hub route takes. It is what `practice_entry` of `POST /training/sessions/{id}/finish` carries.
 */
final readonly class PracticeEntry
{
    public function __construct(
        public string $course,
        public ?int $lessonId,
    ) {}

    /** @return array{course: string, lesson_id: int|null} */
    public function toArray(): array
    {
        return ['course' => $this->course, 'lesson_id' => $this->lessonId];
    }
}

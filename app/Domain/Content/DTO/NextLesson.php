<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** The lesson of a course the learner still has never-served active words in (a row of the course tree). */
final readonly class NextLesson
{
    public function __construct(
        public int $id,
        public int $stageNumber,
        public int $number,
        public ?string $title,
    ) {}
}

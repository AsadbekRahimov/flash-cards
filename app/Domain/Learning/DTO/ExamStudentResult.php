<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

final readonly class ExamStudentResult
{
    /**
     * @param  list<array{rank:int, name:string, score:int}>  $top
     */
    public function __construct(
        public int $score,
        public ?int $rank,
        public int $totalParticipants,
        public array $top,
    ) {}
}

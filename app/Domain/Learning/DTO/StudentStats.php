<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

final readonly class StudentStats
{
    public function __construct(
        public int $wordsLearned,
        public int $wordsDueToday,
    ) {}
}

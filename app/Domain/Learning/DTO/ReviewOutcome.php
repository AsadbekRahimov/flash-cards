<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\WordRepetition;

final readonly class ReviewOutcome
{
    public function __construct(
        public WordRepetition $repetition,
        public TrainingStep $next,
    ) {}
}

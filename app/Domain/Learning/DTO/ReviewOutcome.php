<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\WordRepetition;

final readonly class ReviewOutcome
{
    /**
     * @param  SpellingVerdict|null  $verdict  the server's grade of a typed answer; null when the client graded
     */
    public function __construct(
        public WordRepetition $repetition,
        public TrainingStep $next,
        public ?SpellingVerdict $verdict = null,
    ) {}
}

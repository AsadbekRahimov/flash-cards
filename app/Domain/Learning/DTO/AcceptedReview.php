<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Models\WordRepetition;

/**
 * What {@see ReviewSubmissionService} applied: the updated card, the
 * exercise the grade was recorded under and — for a typed answer — the server's verdict.
 */
final readonly class AcceptedReview
{
    public function __construct(
        public WordRepetition $repetition,
        public ExerciseType $exercise,
        public ?SpellingVerdict $verdict = null,
    ) {}
}

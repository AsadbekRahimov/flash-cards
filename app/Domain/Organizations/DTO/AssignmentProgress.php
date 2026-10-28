<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use App\Models\Assignment;

/**
 * The counted progress of one learner on one assignment, before it is written to
 * `assignment_results` (docs/13 §1.4 snapshot rule): the percent of the lesson's ACTIVE words with
 * `repetitions >= 1`, whether the completion threshold is reached, and how many words the threshold
 * takes (`needed` — the N-th earliest first pass is the completion moment). Built and consumed by
 * AssignmentService only.
 */
final readonly class AssignmentProgress
{
    public function __construct(
        public Assignment $assignment,
        public int $learnerId,
        public int $progressPct,
        public bool $reached,
        public int $needed,
    ) {}

    /** The `(assignment_id, learner_id)` pair as the key of a snapshot row. */
    public function key(): string
    {
        return $this->assignment->id.':'.$this->learnerId;
    }
}

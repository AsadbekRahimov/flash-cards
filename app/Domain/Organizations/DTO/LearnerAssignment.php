<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use App\Models\Assignment;
use Carbon\CarbonInterface;

/**
 * One assignment as the learner sees it on "Today" (GET /api/twa/assignments, docs/04 A.9): the
 * assignment plus the learner's own progress — never anybody else's.
 */
final readonly class LearnerAssignment
{
    public function __construct(
        public Assignment $assignment,
        public int $progressPct,
        public ?CarbonInterface $completedAt,
    ) {}

    public function completed(): bool
    {
        return $this->completedAt !== null;
    }

    /** Done after the due date. */
    public function late(): bool
    {
        return $this->completedAt !== null && $this->completedAt->gt($this->assignment->due_at);
    }
}

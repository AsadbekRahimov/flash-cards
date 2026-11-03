<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * Outcome of one `LearnerPurgeService::purgeDue()` run (docs/03 §8 rule 2).
 *
 * `purged` = learners deleted for good by this run; `skipped` = due learners that were not deleted after all
 * (restored by their own `/start` between the pick and the row lock, or already gone); `capped` = the per-run limit
 * stopped the run before every due learner was handled (the rest waits for the next run); `locked` = another run
 * holds the purge lock, nothing was done.
 */
final class LearnerPurgeReport
{
    public int $purged = 0;

    public int $skipped = 0;

    public bool $capped = false;

    public bool $locked = false;

    /** @return array{purged: int, skipped: int, capped: bool, locked: bool} */
    public function toArray(): array
    {
        return [
            'purged' => $this->purged,
            'skipped' => $this->skipped,
            'capped' => $this->capped,
            'locked' => $this->locked,
        ];
    }
}

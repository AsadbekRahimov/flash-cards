<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Services\CardPicker;
use Carbon\CarbonImmutable;

/**
 * What is left of a round for the picker (docs/06 §13.2): the sprint's limits with the session's own counts applied.
 * Built per request by TrainingFlowService from {@see SprintPolicy} and the session counters of
 * {@see CardPicker::stepCounts()}; a daily session without a sprint passes null instead.
 *
 * `dueCapReached()` — the picker serves no more day-based (`review`) cards, only cards in minute steps and new words;
 * `newRemaining() <= 0` — no more new words this round (also while a review debt holds them back: `newLimit` 0). The
 * new-word limit is also projected under the learner lock (`sprint_used`), so two tabs cannot jointly exceed it; the
 * due cap is a soft reading (one extra card in a race at worst). A null limit is no limit of that kind.
 */
final readonly class SprintLimits
{
    /**
     * @param  CarbonImmutable  $startedAt  the session's start: a word introduced at or after it belongs to this round
     * @param  int|null  $newLimit  new words a round may introduce; null = as many as there are
     * @param  int  $newUsed  new words this round introduced so far (counted before the pick)
     * @param  int|null  $dueRemaining  day-based reviews the round may still take; null = all that are due
     */
    public function __construct(
        public CarbonImmutable $startedAt,
        public ?int $newLimit,
        public int $newUsed,
        public ?int $dueRemaining,
    ) {}

    /** New words the round may still introduce; without a limit, more than any course holds. */
    public function newRemaining(): int
    {
        if ($this->newLimit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $this->newLimit - $this->newUsed);
    }

    public function dueCapReached(): bool
    {
        return $this->dueRemaining !== null && $this->dueRemaining <= 0;
    }
}

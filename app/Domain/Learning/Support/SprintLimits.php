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
 * `dueRemaining <= 0` — the picker serves no more day-based (`review`) cards, only cards in minute steps and new words;
 * `newUsed >= newLimit` — no more new words this round. The new-word limit is also projected under the learner lock
 * (`sprint_used`), so two tabs cannot jointly exceed it; the due cap is a soft reading (one extra card in a race at worst).
 */
final readonly class SprintLimits
{
    /**
     * @param  CarbonImmutable  $startedAt  the session's start: a word introduced at or after it belongs to this round
     * @param  int  $newLimit  new words a round may introduce
     * @param  int  $newUsed  new words this round introduced so far (counted before the pick)
     * @param  int  $dueRemaining  day-based reviews the round may still take
     */
    public function __construct(
        public CarbonImmutable $startedAt,
        public int $newLimit,
        public int $newUsed,
        public int $dueRemaining,
    ) {}

    public function newRemaining(): int
    {
        return max(0, $this->newLimit - $this->newUsed);
    }

    public function dueCapReached(): bool
    {
        return $this->dueRemaining <= 0;
    }
}

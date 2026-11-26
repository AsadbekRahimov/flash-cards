<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\RepetitionState;
use Carbon\CarbonImmutable;

/**
 * The learner's card (`word_repetitions`) as it stood BEFORE the answer (docs/32 §3.3): the state, the interval the scheduler gave at the
 * last pass and the moment of that pass. The scheduled due ("назначенный срок") is `lastReviewedAt + intervalDays` days: both SM-2 and FSRS
 * write `next_review_at = now + interval_days` and `last_reviewed_at = now` together (LearningStepMachine::commitAnswer), while
 * `ReviewPull` moves only `next_review_at` - so a card pulled to now keeps its scheduled due and is "early".
 */
final readonly class CardStateBefore
{
    public function __construct(
        public RepetitionState $state,
        public int $intervalDays,
        public ?CarbonImmutable $lastReviewedAt,
    ) {}

    /** The scheduled due of a day-based card (whole seconds, as stored); null for a card in minute steps or never graded. */
    public function nominalDue(): ?CarbonImmutable
    {
        if ($this->state !== RepetitionState::Review || $this->lastReviewedAt === null) {
            return null;
        }

        return $this->lastReviewedAt->startOfSecond()->addDays(max(0, $this->intervalDays));
    }
}

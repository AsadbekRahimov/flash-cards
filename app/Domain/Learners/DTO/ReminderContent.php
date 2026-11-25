<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Services\ReviewScoreHint;

/**
 * What the reminder job decided to say (docs/12 §7.5): the kind plus the numbers
 * the message is built from. `dueCount` is always the learner's current due count,
 * whatever the kind — it goes to `reminder.sent` for analytics. `idleDays` is the number
 * of local days without reviews (0 = studied today). `scoreHint` - the `Review` reminder of the day after a lesson says that the words
 * of the lesson came up for review and earn points ({@see ReviewScoreHint}, docs/31 §5б).
 */
final readonly class ReminderContent
{
    public function __construct(
        public ReminderKind $kind,
        public int $dueCount,
        public int $streakCurrent,
        public int $idleDays = 0,
        public bool $scoreHint = false,
    ) {}

    public function withScoreHint(): self
    {
        return new self($this->kind, $this->dueCount, $this->streakCurrent, $this->idleDays, true);
    }
}

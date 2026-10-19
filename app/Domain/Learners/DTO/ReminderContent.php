<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Enums\ReminderKind;

/**
 * What the reminder job decided to say (docs/12 §7.5): the kind plus the numbers
 * the message is built from. `dueCount` is always the learner's current due count,
 * whatever the kind — it goes to `reminder.sent` for analytics.
 */
final readonly class ReminderContent
{
    public function __construct(
        public ReminderKind $kind,
        public int $dueCount,
        public int $streakCurrent,
    ) {}
}

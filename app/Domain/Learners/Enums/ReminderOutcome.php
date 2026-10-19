<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

use App\Domain\Learners\Services\ReminderSender;

/** What {@see ReminderSender::send()} did for one claimed reminder. */
enum ReminderOutcome: string
{
    case Sent = 'sent';

    /** Telegram answered 403: `dm_blocked_at` is now set, no retry. */
    case Forbidden = 'forbidden';

    /** Learner gone, banned, DM blocked or reminders switched off since the claim. */
    case SkippedUnreachable = 'skipped_unreachable';

    /** The claim is older than `learning.reminders.max_delay_minutes`. */
    case SkippedStale = 'skipped_stale';

    /** A reminder was already sent within `learning.reminders.min_interval_hours` (retried job, overlapping claims). */
    case SkippedRecent = 'skipped_recent';

    /** Nothing due and the streak is not at risk — silence is the right answer today. */
    case SkippedNothingToSay = 'skipped_nothing_to_say';
}

<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

use App\Domain\Learners\Services\ReminderExperiment;

/**
 * The arm of the reminder experiment ({@see ReminderExperiment}): a question with four answers, or the plain text with
 * the one app button. Stored as `variant` in the `reminder.sent` / `reminder.failed` props and the `notifications_log` payload,
 * next to `experiment` (the salt of the experiment, {@see ReminderExperiment::salt()}).
 */
enum ReminderVariant: string
{
    case Quiz = 'quiz';
    case Plain = 'plain';
}

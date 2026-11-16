<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

/**
 * What a personal reminder says (docs/12 §7.5). Decided by the job at send time (see
 * ReminderContentResolver for the order): a break in learning → `Inactive`; enough due
 * cards → `Review`; a streak about to break → `StreakAtRisk`; nothing started yet →
 * `NeverStarted` / `ChooseCourse`; nothing to repeat today → `NewWords`; otherwise
 * nothing is sent.
 */
enum ReminderKind: string
{
    case Review = 'review';
    case StreakAtRisk = 'streak_at_risk';
    case Inactive = 'inactive';
    case NeverStarted = 'never_started';
    case NewWords = 'new_words';
    case ChooseCourse = 'choose_course';

    /** `notifications_log.type` of a sent reminder of this kind. */
    public function notificationType(): string
    {
        return match ($this) {
            self::Review => 'review_reminder',
            self::StreakAtRisk => 'streak_reminder',
            self::Inactive => 'inactive_reminder',
            self::NeverStarted => 'never_started_reminder',
            self::NewWords => 'new_words_reminder',
            self::ChooseCourse => 'choose_course_reminder',
        };
    }

    /** @return list<string> every `notifications_log.type` that counts as "a reminder was sent" */
    public static function notificationTypes(): array
    {
        return array_map(static fn (self $kind): string => $kind->notificationType(), self::cases());
    }
}

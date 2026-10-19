<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

/**
 * What a personal reminder says (docs/12 §7.5). Decided by the job at send time, in
 * this order: enough due cards → `Review`; otherwise a streak about to break →
 * `StreakAtRisk`; otherwise nothing is sent.
 */
enum ReminderKind: string
{
    case Review = 'review';
    case StreakAtRisk = 'streak_at_risk';

    /** `notifications_log.type` of a sent reminder of this kind. */
    public function notificationType(): string
    {
        return match ($this) {
            self::Review => 'review_reminder',
            self::StreakAtRisk => 'streak_reminder',
        };
    }

    /** @return list<string> every `notifications_log.type` that counts as "a reminder was sent" */
    public static function notificationTypes(): array
    {
        return array_map(static fn (self $kind): string => $kind->notificationType(), self::cases());
    }
}

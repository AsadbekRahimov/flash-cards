<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\Learner;
use App\Models\User;

/**
 * Turns the sender of a private-chat update into a learner. Writing to the bot is the person's
 * own action: the learner is created / revived and `last_seen_at` is bumped
 * ({@see LearnerRegistrar::fromTelegramUser()}), and `dm_blocked_at` is cleared — a person who
 * writes to the bot has evidently not blocked it (docs/12 §7.5).
 *
 * A linked teacher (`users.telegram_user_id`) is not a learner here: no learner row is created
 * for them and the learner-facing handlers stay silent, so they never end up in the onboarding.
 */
final class DmLearnerResolver
{
    public function __construct(private readonly LearnerRegistrar $registrar) {}

    /**
     * @param  array<string, mixed>  $from  Telegram `User` (`message.from` / `callback_query.from`)
     * @return Learner|null null for a teacher or a sender without an id
     */
    public function resolve(array $from): ?Learner
    {
        if ((int) ($from['id'] ?? 0) <= 0) {
            return null;
        }

        if (User::query()->where('telegram_user_id', (int) $from['id'])->exists()) {
            return null;
        }

        $learner = $this->registrar->fromTelegramUser($from);

        if ($learner->dm_blocked_at !== null) {
            $learner->forceFill(['dm_blocked_at' => null])->save();
        }

        return $learner;
    }
}

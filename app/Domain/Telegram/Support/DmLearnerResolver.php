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

    /**
     * Any message or button tap in a private chat proves the DM is open: clears `dm_blocked_at`
     * of an existing learner without creating one (`/help`, `/stats`, `/promo` and future DM
     * handlers do not go through {@see resolve()}). One indexed conditional UPDATE; a no-op for
     * teachers and unknown senders. Called by the dispatcher before any handler (docs/12 §7.5).
     */
    public function markReachable(int $telegramUserId): void
    {
        if ($telegramUserId <= 0) {
            return;
        }

        Learner::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereNotNull('dm_blocked_at')
            ->update(['dm_blocked_at' => null]);
    }

    /**
     * The learner blocked the bot (`my_chat_member`, private chat, `kicked`): no reminder can reach
     * them, so they leave the dispatcher's selection at once instead of after the first 403. Only
     * an existing learner is touched; the first moment is kept on a repeated update.
     */
    public function markBlocked(int $telegramUserId): void
    {
        if ($telegramUserId <= 0) {
            return;
        }

        Learner::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereNull('dm_blocked_at')
            ->update(['dm_blocked_at' => now()->utc()->format('Y-m-d H:i:s')]);
    }
}

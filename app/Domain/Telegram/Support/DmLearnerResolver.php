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
 * A staff member is not a learner here ({@see isStaff()}): no learner row is created for them and the learner-facing
 * handlers stay silent, so they never end up in the onboarding.
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

        if ($this->isStaff((int) $from['id'])) {
            return null;
        }

        return $this->registerLearner($from);
    }

    /**
     * The ONE rule "this Telegram account belongs to a staff / admin user, not to a learner" for private chats (review W1 of the
     * audit 2026-11-10): a `users.telegram_user_id` that was VERIFIED through the bot, or an unverified one (hand-typed in
     * `/admin`, legacy) with NO learner row behind it — the teachers of Phase 1 who never wrote to the bot as learners keep their
     * greeting. An unverified id that a learner also carries is the learner's: a typed number must not switch somebody's bot
     * off. Group commands do not use this rule (`TeacherAuthority`: `teacher_groups` + `is_active`).
     */
    public function isStaff(int $telegramUserId): bool
    {
        if ($telegramUserId <= 0) {
            return false;
        }

        $verifiedAt = User::query()->where('telegram_user_id', $telegramUserId)->value('telegram_verified_at');

        if ($verifiedAt === null) {
            // No user at all, or no proof: a staff member only when nobody is a learner under this id.
            return User::query()->where('telegram_user_id', $telegramUserId)->exists()
                && ! Learner::withTrashed()->where('telegram_user_id', $telegramUserId)->exists();
        }

        return true;
    }

    /** @param array<string, mixed> $from a sender whose Telegram id was already checked against teachers */
    public function registerLearner(array $from): Learner
    {
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

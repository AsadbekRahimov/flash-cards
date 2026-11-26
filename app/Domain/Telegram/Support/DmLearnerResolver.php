<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Services\DmReachability;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Turns the sender of a private-chat update into a learner. Writing to the bot is the person's
 * own action: the learner is created / revived and `last_seen_at` is bumped
 * ({@see LearnerRegistrar::fromTelegramUser()}), and `dm_blocked_at` is cleared — a person who
 * writes to the bot has evidently not blocked it (docs/12 §7.5). The flag itself is written by
 * {@see DmReachability} only (docs/34 §4в п. 3).
 *
 * A staff member is not a learner here ({@see isStaff()}): no learner row is created for them and the learner-facing
 * handlers stay silent, so they never end up in the onboarding.
 */
final class DmLearnerResolver
{
    public function __construct(
        private readonly LearnerRegistrar $registrar,
        private readonly DmReachability $reachability,
    ) {}

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
            $this->reachability->markReachable($learner->telegram_user_id, DmReachability::VIA_START);
            $learner->refresh();
        }

        return $learner;
    }

    /**
     * Any message or button tap in a private chat proves the DM is open: clears `dm_blocked_at`
     * of an existing learner without creating one (`/help`, `/stats`, `/promo` and future DM
     * handlers do not go through {@see resolve()}). One indexed conditional UPDATE; a no-op for
     * teachers and unknown senders. Called by the dispatcher before any handler (docs/12 §7.5);
     * `my_chat_member` `member` passes its own `$via`.
     */
    public function markReachable(int $telegramUserId, string $via = DmReachability::VIA_DM): void
    {
        $this->reachability->markReachable($telegramUserId, $via);
    }

    /**
     * The learner blocked the bot (`my_chat_member`, private chat, `kicked`): no reminder can reach
     * them, so they leave the dispatcher's selection at once instead of after the first 403. Only
     * an existing learner is touched; the first moment is kept on a repeated update. `$at` is the
     * `date` of the update when Telegram sent one.
     */
    public function markBlocked(int $telegramUserId, ?CarbonImmutable $at = null): void
    {
        $this->reachability->markUnreachable($telegramUserId, DmReachability::REASON_KICKED, $at, DmReachability::VIA_MY_CHAT_MEMBER);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Administration\Services\BotBlockedCounter;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer of the reachability of a learner's private chat (docs/34 §4в п. 3): `learners.dm_blocked_at` (since when the bot
 * cannot write to the person) and `learners.dm_blocked_reason` (why). Every sender, the dispatcher and `my_chat_member` go through
 * here; a Pest guard (`DmReachabilityGuardTest`) fails when `app/` writes `dm_blocked_at` anywhere else.
 *
 *  - {@see markUnreachable()}: the date is set only when there was none (the FIRST moment stays on a repeated 403 or `kicked`), the
 *    reason is updated every time (the last answer of Telegram is the one that explains the silence). `bot.dm_closed {reason, via}`
 *    is recorded only on the transition open → closed.
 *  - {@see markReachable()}: both columns are cleared; `bot.dm_reopened {via}` only on the transition closed → open. Opening the
 *    Mini App does NOT call it: it does not prove the bot was unblocked.
 *
 * Each transition is one conditional UPDATE (`... WHERE dm_blocked_at IS [NOT] NULL`): of two racing calls exactly one changes the
 * row, so the event is recorded once. No lock: the millisecond race between a 403 and an inbound DM is harmless in both orders
 * (worst case one more 403 tomorrow, or silence until the learner writes again). Only existing learners are touched; a Telegram id
 * of nobody (a teacher, a stranger) is a no-op. The counter of the dashboard ({@see BotBlockedCounter}) is dropped after the commit
 * of a transition.
 */
final class DmReachability
{
    /** Why a DM is closed: Telegram's answer to a send (the reasons of {@see TelegramForbiddenException}) or the `kicked` of `my_chat_member`. */
    public const REASON_KICKED = 'kicked';

    public const REASONS = [
        TelegramForbiddenException::REASON_BOT_BLOCKED,
        TelegramForbiddenException::REASON_NOT_STARTED,
        TelegramForbiddenException::REASON_USER_DEACTIVATED,
        TelegramForbiddenException::REASON_CHAT_NOT_FOUND,
        TelegramForbiddenException::REASON_FORBIDDEN,
        self::REASON_KICKED,
    ];

    /** The accounts that will never answer again (docs/34 §4в п. 6): the reminders stop planning for them. */
    public const DEAD_REASONS = [
        TelegramForbiddenException::REASON_USER_DEACTIVATED,
        TelegramForbiddenException::REASON_CHAT_NOT_FOUND,
    ];

    /** `via` of the events: a send got 403 / chat not found. */
    public const VIA_SEND = 'send';

    /** `via`: `my_chat_member` of the private chat (`kicked` / `member`). */
    public const VIA_MY_CHAT_MEMBER = 'my_chat_member';

    /** `via`: a message or a button tap of the learner in the private chat. */
    public const VIA_DM = 'dm';

    /** `via`: `/start` (the registration of the learner). */
    public const VIA_START = 'start';

    public function __construct(private readonly ProductEventRecorder $events) {}

    /**
     * The bot cannot write to this person. `$reason` outside {@see REASONS} is stored as `forbidden`; `$at` is the moment of the
     * refusal (the `date` of `my_chat_member`, the clock of the sender), now by default.
     */
    public function markUnreachable(int $telegramUserId, string $reason, ?CarbonImmutable $at = null, string $via = self::VIA_SEND): void
    {
        if ($telegramUserId <= 0) {
            return;
        }

        $reason = in_array($reason, self::REASONS, true) ? $reason : TelegramForbiddenException::REASON_FORBIDDEN;
        $at = ($at ?? CarbonImmutable::now())->utc()->format('Y-m-d H:i:s');

        // A dead account (docs/34 §4в п. 6) keeps no reminder pointer: the reminder dispatcher plans nothing for it. Should the person
        // ever write again, the flag goes and the dispatcher's backfill gives them a pointer anew.
        $dead = in_array($reason, self::DEAD_REASONS, true) ? ['next_reminder_at' => null] : [];

        $closed = Learner::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereNull('dm_blocked_at')
            ->update(['dm_blocked_at' => $at, 'dm_blocked_reason' => $reason, ...$dead]);

        if ($closed === 0) {
            // Already closed (or nobody): only the reason follows the latest answer, the first moment stays.
            Learner::query()
                ->where('telegram_user_id', $telegramUserId)
                ->whereNotNull('dm_blocked_at')
                ->where(fn (Builder $q): Builder => $q->whereNull('dm_blocked_reason')->orWhere('dm_blocked_reason', '<>', $reason))
                ->update(['dm_blocked_reason' => $reason, ...$dead]);

            return;
        }

        $this->transitioned($telegramUserId, ProductEventName::BotDmClosed, ['reason' => $reason, 'via' => $via]);
    }

    /** The person can be written to again: a DM, a tap, `/start` or `my_chat_member` `member`. */
    public function markReachable(int $telegramUserId, string $via): void
    {
        if ($telegramUserId <= 0) {
            return;
        }

        $opened = Learner::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereNotNull('dm_blocked_at')
            ->update(['dm_blocked_at' => null, 'dm_blocked_reason' => null]);

        if ($opened > 0) {
            $this->transitioned($telegramUserId, ProductEventName::BotDmReopened, ['via' => $via]);
        }
    }

    /** @param array<string, string> $props */
    private function transitioned(int $telegramUserId, ProductEventName $event, array $props): void
    {
        $this->events->record($event, $props, learner: Learner::query()->where('telegram_user_id', $telegramUserId)->first());

        DB::afterCommit(static function (): void {
            BotBlockedCounter::forget();
        });
    }
}

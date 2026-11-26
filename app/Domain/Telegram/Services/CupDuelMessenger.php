<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Cup\Enums\CupDuelDmKind;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\DisplayName;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\TwaScreen;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The DMs of a duel of a cup (docs/33 §5.7, §11.9.4 п. 3; D2), sent by {@see SendCupDuelDmJob} after the commit:
 *  - `challenged` - to the participant a PRIVATE challenge is addressed to: "{name} challenges you to a duel in the cup «{group}»: rated
 *    / friendly, until HH:MM" with a button into the challenge (`TwaScreen::CupDuel`; a public challenge is the message in the chat);
 *  - `accepted` - to the challenger: "{name} accepted your challenge - the match waits for you" with a button into the battle
 *    (`TwaScreen::Battle`): the room waits only `presence_grace_seconds` (30 s) for him.
 *
 * The rules of the senders of the bot (docs/34 §4в п. 8), as {@see FriendsMessenger} and {@see BattleMessenger}: the state is read again
 * when the job runs - a challenge no longer open (or past its time), a match no longer in its room, the cup switched off - says nothing;
 * a recipient who is blocked, deleted or whose DM is closed (`dm_blocked_at`) gets nothing. There is no opt-out of these DMs, as of the
 * DMs of friends and battles (an answer to an action of another learner, not a study reminder): they do not count in the limit of one
 * study message a day. At most once per (kind, duel): a `notifications_log` row `cup_duel_dm` is written BEFORE the call; a 403 deletes
 * it and closes the DM ({@see DmReachability::markUnreachable()}); another refusal (a 429 too) deletes it and goes on to the job
 * (`ThrottleTelegramOutbound` puts it back for `retry_after`); a timeout keeps it - a DM lost is better than a DM twice. The volume is
 * bounded by the core: 20 challenges a day per participant, 3 to the same one.
 *
 * ONE statement reads the duel, its cup and group, both names, the recipient and the match; texts `lang/{ru,uz}/cup.php` (`duel_dm.*`)
 * in the recipient's language, names as the bot prints them ({@see DisplayName::forChat()}).
 */
final class CupDuelMessenger
{
    public const NOTIFICATION_TYPE = 'cup_duel_dm';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmReachability $reachability,
        private readonly BotButtons $buttons,
    ) {}

    public function notify(CupDuelDmKind $kind, int $duelId, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $row = $this->read($kind, $duelId);
        if ($row === null || ! $this->stillHolds($kind, $row, $now)) {
            return;
        }

        /** @var Learner $recipient */
        $recipient = (new Learner)->newFromBuilder([
            'id' => $row->l_id,
            'telegram_user_id' => $row->l_telegram_user_id,
            'interface_locale' => $row->l_interface_locale,
            'language_code' => $row->l_language_code,
        ]);
        if ($row->l_deleted_at !== null || $row->l_blocked_at !== null || $row->l_dm_blocked_at !== null || (int) $recipient->telegram_user_id <= 0) {
            return;
        }

        $locale = $this->locales->forLearner($recipient);
        $chatId = (int) $recipient->telegram_user_id;
        $challenger = DisplayName::forChat((string) $row->challenger_name);
        $opponent = DisplayName::forChat((string) ($row->opponent_name ?? ''));
        $group = (string) $row->group_title;

        if ($kind === CupDuelDmKind::Challenged) {
            $expires = CarbonImmutable::parse((string) $row->expires_at, 'UTC')->setTimezone((string) $row->cup_timezone);
            $text = implode("\n", [
                __('cup.duel_dm.challenged', ['name' => $challenger, 'group' => $group], $locale),
                (bool) $row->rated_offered ? __('cup.duel_dm.rated', [], $locale) : __('cup.duel_dm.friendly', [], $locale),
                __('cup.duel.until', ['time' => $expires->format('H:i')], $locale),
            ]);
            $button = $this->buttons->open(__('buttons.cup_duel_open', [], $locale), TwaScreen::CupDuel, ['code' => (string) $row->public_code, 'duel' => $duelId], ['from' => 'cup_duel_dm']);
        } else {
            $text = __('cup.duel_dm.accepted', ['name' => $opponent, 'group' => $group], $locale);
            $button = $this->buttons->open(__('buttons.cup_duel_play', [], $locale), TwaScreen::Battle, ['id' => (int) $row->battle_id], ['from' => 'cup_duel_accepted']);
        }

        $this->deliver((int) $row->l_id, $chatId, $kind, $duelId, fn () => $this->telegram->sendMessage($chatId, $text, replyMarkup: $this->buttons->single($chatId, $button)));
    }

    /** The duel, its cup and group, the names of both players, the recipient and the match - ONE statement by primary keys. */
    private function read(CupDuelDmKind $kind, int $duelId): ?\stdClass
    {
        $recipient = $kind === CupDuelDmKind::Challenged ? 'd.opponent_participant_id' : 'd.challenger_participant_id';

        /** @var \stdClass|null $row */
        $row = DB::table('cup_duels as d')
            ->join('cups as cu', 'cu.id', '=', 'd.cup_id')
            ->join('telegram_groups as tg', 'tg.id', '=', 'cu.telegram_group_id')
            ->join('cup_participants as c', 'c.id', '=', 'd.challenger_participant_id')
            ->leftJoin('cup_participants as o', 'o.id', '=', 'd.opponent_participant_id')
            ->join('cup_participants as rp', 'rp.id', '=', $recipient)
            ->join('learners as l', 'l.id', '=', 'rp.learner_id')
            ->leftJoin('battles as b', 'b.id', '=', 'd.battle_id')
            ->where('d.id', $duelId)
            ->first([
                'd.status', 'd.is_public', 'd.rated_offered', 'd.expires_at', 'd.battle_id',
                'cu.public_code', 'cu.timezone as cup_timezone', 'tg.id as tg_id', 'tg.title as group_title', 'tg.meta as tg_meta',
                'c.display_name as challenger_name', 'o.display_name as opponent_name',
                'l.id as l_id', 'l.telegram_user_id as l_telegram_user_id', 'l.interface_locale as l_interface_locale',
                'l.language_code as l_language_code', 'l.blocked_at as l_blocked_at', 'l.dm_blocked_at as l_dm_blocked_at',
                'l.deleted_at as l_deleted_at', 'b.status as battle_status',
            ]);

        return $row;
    }

    /** The news is still true: the cup is on for the chat, a challenge still open (and private), a match still waiting in its room. */
    private function stillHolds(CupDuelDmKind $kind, \stdClass $row, CarbonImmutable $now): bool
    {
        /** @var TelegramGroup $group */
        $group = (new TelegramGroup)->newFromBuilder(['id' => $row->tg_id, 'meta' => $row->tg_meta]);
        if (! CupAccess::allowsGroup($group)) {
            return false;
        }

        return match ($kind) {
            CupDuelDmKind::Challenged => $row->status === CupDuelStatus::Open->value
                && ! (bool) $row->is_public
                && CarbonImmutable::parse((string) $row->expires_at, 'UTC')->gt($now),
            CupDuelDmKind::Accepted => $row->status === CupDuelStatus::Accepted->value
                && $row->battle_id !== null
                && $row->battle_status === BattleStatus::Room->value,
        };
    }

    /**
     * At most once per (kind, duel), as {@see FriendsMessenger::deliver()}: the marker before the call; 403 - the marker goes, the DM is
     * closed, the job ends; another refusal of Telegram - the marker goes and the job retries (a 429 after `retry_after`); a timeout -
     * the marker stays.
     */
    private function deliver(int $learnerId, int $telegramUserId, CupDuelDmKind $kind, int $duelId, callable $send): void
    {
        $key = $kind->value.':'.$duelId;
        $sent = NotificationLog::query()
            ->where('learner_id', $learnerId)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('payload->key', $key)
            ->exists();
        if ($sent) {
            return;
        }

        $log = NotificationLog::query()->create([
            'learner_id' => $learnerId,
            'type' => self::NOTIFICATION_TYPE,
            'payload' => ['key' => $key],
            'sent_at' => now(),
        ]);

        try {
            $send();
        } catch (TelegramForbiddenException $e) {
            $log->delete();
            $this->reachability->markUnreachable($telegramUserId, $e->reason);

            Log::warning('telegram.cup_duel_dm_forbidden', ['learner_id' => $learnerId, 'reason' => $e->reason]);
        } catch (TelegramApiException $e) {
            if ($e->isApiResponse()) {
                // Definitely not delivered (a 429 or another refusal): the retry must find no row and send.
                $log->delete();
            }

            throw $e;
        }
    }
}

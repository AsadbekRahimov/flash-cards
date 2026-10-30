<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\BattleDmKind;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Services\Battle\BattleSettings;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Jobs\SendBattleDmJob;
use App\Models\Battle;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * What the bot says about battles (docs/16 §8.7): the DM "{name} invites you to a battle: {format}, {course · lesson}. The
 * invitation is open for 5 minutes" with one `web_app` button "Open the battle" that leads to `{twa.base_url}/twa/battles/{id}`
 * (a private chat, so the button may open the Mini App itself). Texts: `lang/{ru,uz}/battle.php`, in the recipient's language.
 * The results are not sent: both players are in the app.
 *
 * {@see SendBattleDmJob} calls {@see notify()}. The battle is read again - a state that no longer holds (declined, withdrawn,
 * taken, expired) is not announced - and so is the recipient: a learner who is blocked or whose DM is closed gets nothing.
 */
final class BattleMessenger
{
    public const NOTIFICATION_TYPE = 'battle_dm';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $dm,
        private readonly BattleSettings $settings,
    ) {}

    /**
     * Background DM ({@see SendBattleDmJob}). Silence for an invitation that is not open any more; a Telegram 403 closes the DM
     * (`dm_blocked_at`, as with reminders) and ends the job.
     */
    public function notify(BattleDmKind $kind, int $battleId): void
    {
        match ($kind) {
            BattleDmKind::Invited => $this->notifyInvited($battleId),
        };
    }

    private function notifyInvited(int $battleId): void
    {
        /** @var Battle|null $battle */
        $battle = Battle::query()->find($battleId);
        if ($battle === null || $battle->status !== BattleStatus::Invited || $battle->invite_expires_at->lte(CarbonImmutable::now())) {
            return;
        }

        $recipient = $this->reachable($battle->opponent_id);
        $challenger = Learner::query()->find($battle->challenger_id);
        if ($recipient === null || ! $challenger instanceof Learner) {
            return;
        }

        $locale = $this->locales->forLearner($recipient);
        $this->deliver($recipient, BattleDmKind::Invited, $battle->id, fn () => $this->telegram->sendWebAppButton(
            $recipient->telegram_user_id,
            $this->invitedText($battle, $challenger, $locale),
            __('battle.dm.open', [], $locale),
            rtrim((string) config('twa.base_url'), '/').'/twa/battles/'.$battle->id,
        ));
    }

    /** "{name} invites you to a battle: {format}, {course · lesson}. {the invitation is open for N minutes}". */
    private function invitedText(Battle $battle, Learner $challenger, string $locale): string
    {
        $snapshot = Snapshot::fromArray($battle->snapshot);
        $place = $snapshot->courseTitleFor($locale);
        $lesson = $this->lessonLabel($snapshot, $locale);
        if ($lesson !== null) {
            $place .= ' · '.$lesson;
        }

        $minutes = max(1, intdiv($this->settings->inviteTtlSeconds() + 59, 60));

        return __('battle.dm.invited', [
            'name' => $this->nameOf($challenger, $locale),
            'format' => __('battle.mode.'.$battle->mode->value, [], $locale),
            'place' => $place,
            'validity' => trans_choice('battle.dm.validity', $minutes, [], $locale),
        ], $locale);
    }

    /** "Module 2 · Lesson 4" (the words of the app); null for a battle on the whole course. */
    private function lessonLabel(Snapshot $snapshot, string $locale): ?string
    {
        if ($snapshot->lessonNumber === null) {
            return null;
        }

        return $snapshot->lessonStage === null
            ? __('battle.dm.lesson_no_stage', ['number' => $snapshot->lessonNumber], $locale)
            : __('battle.dm.lesson', ['stage' => $snapshot->lessonStage, 'number' => $snapshot->lessonNumber], $locale);
    }

    /** The learner if a DM can reach them: exists, not blocked, DM not closed. */
    private function reachable(int $learnerId): ?Learner
    {
        $learner = Learner::query()->find($learnerId);

        return $learner instanceof Learner && $learner->blocked_at === null && $learner->dm_blocked_at === null ? $learner : null;
    }

    /**
     * At most once per (kind, battle): a `notifications_log` row of type `battle_dm` (`payload.key = invited:<battle_id>`) is
     * written BEFORE the Telegram call (as {@see FriendsMessenger::deliver()} does), so a job retried after the message was in
     * fact delivered (timeout, a worker killed after the send) only skips. A 403 deletes the row - nothing was delivered - and
     * means the person cannot be reached: they leave the DM selection (as with reminders) and the job ends.
     */
    private function deliver(Learner $learner, BattleDmKind $kind, int $battleId, callable $send): void
    {
        $key = $kind->value.':'.$battleId;

        $sent = NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('payload->key', $key)
            ->exists();
        if ($sent) {
            return;
        }

        $log = NotificationLog::query()->create([
            'learner_id' => $learner->id,
            'type' => self::NOTIFICATION_TYPE,
            'payload' => ['key' => $key],
            'sent_at' => now(),
        ]);

        try {
            $send();
        } catch (TelegramForbiddenException $e) {
            $log->delete();
            $this->dm->markBlocked($learner->telegram_user_id);

            Log::warning('telegram.battle_dm_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);
        }
    }

    private function nameOf(Learner $learner, string $locale): string
    {
        $name = trim((string) $learner->first_name);

        return $name !== '' ? $name : __('battle.anonymous', [], $locale);
    }
}

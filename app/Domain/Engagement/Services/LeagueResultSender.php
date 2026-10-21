<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Enums\LeagueResult;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\SendLeagueResultJob;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Sends the week result of one league member in a DM (docs/13 §7.3) — the body of
 * {@see SendLeagueResultJob}, kept here so it can be exercised directly.
 *
 * Quietly ends when the member / learner is gone, the week is not closed yet, the learner is
 * banned, opted out meanwhile, or DMs are blocked. At most once: a `notifications_log` row of
 * type `league_result` is written BEFORE the Telegram call (as ReminderSender does), so a retry
 * after an ambiguous error (timeout, 5xx) only skips; a 403 / `chat not found` deletes the row,
 * sets `dm_blocked_at` and ends the job without a retry.
 */
final class LeagueResultSender
{
    public const NOTIFICATION_TYPE = 'league_result';

    public const TWA_PATH = '/twa/league';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly LeagueService $leagues,
    ) {}

    /** @return 'sent'|'forbidden'|'skipped' */
    public function send(int $memberId, ?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        /** @var LeagueMember|null $member */
        $member = LeagueMember::query()->with(['learner', 'week', 'group'])->find($memberId);
        $learner = $member?->learner;
        $week = $member?->week;
        $group = $member?->group;
        $result = $member?->result();

        if ($member === null || $learner === null || $week === null || $group === null || $result === null
            || ! $week->isClosed() || $week->closed_at === null
            || $learner->blocked_at !== null || $learner->dm_blocked_at !== null
            || $this->leagues->isOptedOut($learner)
        ) {
            return 'skipped';
        }

        if ($this->alreadySent($learner, $week->closed_at)) {
            return 'skipped';
        }

        $locale = $this->locales->forLearner($learner);
        $log = NotificationLog::query()->create([
            'learner_id' => $learner->id,
            'type' => self::NOTIFICATION_TYPE,
            'payload' => ['member_id' => $member->id, 'week_id' => $week->id, 'result' => $result->value],
            'sent_at' => $now,
        ]);

        try {
            $this->telegram->sendWebAppButton(
                chatId: $learner->telegram_user_id,
                text: $this->text($member, $group->tier, $result, $locale),
                buttonText: __('leagues.open_button', [], $locale),
                url: rtrim((string) config('twa.base_url'), '/').self::TWA_PATH,
            );
        } catch (TelegramForbiddenException $e) {
            $log->delete();
            Learner::query()->whereKey($learner->id)->update(['dm_blocked_at' => $now->format('Y-m-d H:i:s')]);
            Log::warning('telegram.league_result_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

            return 'forbidden';
        }

        return 'sent';
    }

    private function text(LeagueMember $member, int $tier, LeagueResult $result, string $locale): string
    {
        $nextTier = max(0, min($this->leagues->topTier(), $member->tierAfter()));

        return __('leagues.result.'.$result->value, [
            'tier' => __('leagues.tiers.'.$this->leagues->tierKey($tier), [], $locale),
            'next_tier' => __('leagues.tiers.'.$this->leagues->tierKey($nextTier), [], $locale),
            'rank' => (int) $member->final_rank,
            'points' => (int) $member->final_points,
        ], $locale);
    }

    /** A league result already went to the learner since this week was closed (= this week's). */
    private function alreadySent(Learner $learner, DateTimeInterface $closedAt): bool
    {
        return NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('sent_at', '>=', $closedAt)
            ->exists();
    }
}

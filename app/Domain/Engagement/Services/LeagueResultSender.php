<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\DTO\LeagueResultMessage;
use App\Domain\Engagement\Enums\LeagueResult;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendLeagueResultsJob;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the week result of league members in DMs (docs/13 §7.3) — the body of {@see SendLeagueResultsJob},
 * kept here so it can be exercised directly.
 *
 * {@see send()} quietly ends when the member / learner is gone, the week is not closed yet, the learner is
 * banned, opted out meanwhile, or DMs are blocked. At most once: a `notifications_log` row of type
 * `league_result` is written BEFORE the Telegram call (as ReminderSender does), so a retry after an
 * ambiguous error (timeout, 5xx) only skips; a 403 / `chat not found` deletes the row, sets `dm_blocked_at`
 * and ends without a retry. {@see sendBatch()} runs it for a batch under the bot-wide send budget, and judges
 * every member (gone, opted out, already messaged ...) BEFORE it takes a slot of that budget: a member who
 * will be skipped must not use up a message of the second.
 */
final class LeagueResultSender
{
    public const NOTIFICATION_TYPE = 'league_result';

    public const TWA_PATH = '/twa/league';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly LeagueService $leagues,
        private readonly TelegramMessageRateLimiter $outbound,
    ) {}

    /** @return 'sent'|'forbidden'|'skipped' */
    public function send(int $memberId, ?CarbonImmutable $now = null): string
    {
        $message = $this->prepare($memberId);

        return $message === null ? 'skipped' : $this->deliver($message, ($now ?? CarbonImmutable::now())->utc());
    }

    /**
     * {@see send()} for a batch, in order. Every message first takes a slot of the bot-wide send budget
     * ({@see TelegramMessageRateLimiter::awaitGlobal()}, `telegram.outbound.sync_wait_ms` at most); a Bot API
     * failure of ONE member (`TelegramApiException`: transport error, 5xx, an odd 4xx) is reported and the
     * batch goes on — its log row stays, so the retry of the old one-member job and this loop lose that DM
     * alike rather than doubling it. Anything else (the database away) propagates and fails the job.
     *
     * Stops early — and returns the members not reached yet as `remaining` — when no slot came within the wait
     * or `$budgetSeconds` are spent; the caller queues them again.
     *
     * @param  list<int>  $memberIds
     * @return array{sent: int, skipped: int, forbidden: int, failed: int, remaining: list<int>}
     */
    public function sendBatch(array $memberIds, int $budgetSeconds, ?CarbonImmutable $now = null): array
    {
        $deadline = hrtime(true) + max(0, $budgetSeconds) * 1_000_000_000;
        $maxWaitMs = max(0, (int) config('telegram.outbound.sync_wait_ms', 2000));
        $outcome = ['sent' => 0, 'skipped' => 0, 'forbidden' => 0, 'failed' => 0, 'remaining' => []];

        foreach ($memberIds as $i => $memberId) {
            if ($i > 0 && hrtime(true) >= $deadline) {
                $outcome['remaining'] = array_slice($memberIds, $i);

                break;
            }

            // Everything that makes the member a skip is judged first: it costs no slot of the bot-wide budget.
            $message = $this->prepare($memberId);
            if ($message === null || $this->alreadySent($message)) {
                $outcome['skipped']++;

                continue;
            }

            if (! $this->outbound->awaitGlobal($maxWaitMs)) {
                $outcome['remaining'] = array_slice($memberIds, $i);

                break;
            }

            try {
                $outcome[$this->deliver($message, ($now ?? CarbonImmutable::now())->utc())]++;
            } catch (TelegramApiException $e) {
                $outcome['failed']++;
                report($e);
            }
        }

        return $outcome;
    }

    /**
     * The member's DM when it is worth sending: the member, its learner, closed week and group exist, the verdict is
     * known, the learner is neither banned nor DM-closed nor opted out. Null otherwise. Whether it already went out is
     * a separate question ({@see alreadySent()}).
     */
    private function prepare(int $memberId): ?LeagueResultMessage
    {
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
            return null;
        }

        return new LeagueResultMessage($member, $learner, $week, $group, $result, $week->closed_at);
    }

    /**
     * Writes the marker and sends. The marker is looked for once more right before it is written: a parallel sender
     * (a retried job, the old one-member job) may have won while this one waited for its slot.
     *
     * @return 'sent'|'forbidden'|'skipped'
     */
    private function deliver(LeagueResultMessage $message, CarbonImmutable $now): string
    {
        if ($this->alreadySent($message)) {
            return 'skipped';
        }

        $learner = $message->learner;
        $locale = $this->locales->forLearner($learner);
        $log = NotificationLog::query()->create([
            'learner_id' => $learner->id,
            'type' => self::NOTIFICATION_TYPE,
            'payload' => ['member_id' => $message->member->id, 'week_id' => $message->week->id, 'result' => $message->result->value],
            'sent_at' => $now,
        ]);

        try {
            $this->telegram->sendWebAppButton(
                chatId: $learner->telegram_user_id,
                text: $this->text($message->member, $message->group->tier, $message->result, $locale),
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
    private function alreadySent(LeagueResultMessage $message): bool
    {
        return NotificationLog::query()
            ->where('learner_id', $message->learner->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('sent_at', '>=', $message->closedAt)
            ->exists();
    }
}

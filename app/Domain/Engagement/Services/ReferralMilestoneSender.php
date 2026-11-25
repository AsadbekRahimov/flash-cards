<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\DmReachability;
use App\Listeners\Engagement\SendReferralMilestoneMessage;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The DM "N of your friends started — +7 days of Pro (until DATE). M more friends to the next reward" (docs/13 §7.1
 * "2C.9"): the body of {@see SendReferralMilestoneMessage}, which hangs on `ReferralMilestoneReached`. The tail about the
 * next reward is left out after the last milestone.
 *
 * At most once per (inviter, milestone), including retries of the listener and a repeated event: a `notifications_log`
 * row of type `referral_milestone` (key `milestone:<N>`) is written BEFORE the Telegram call, under a short lock, as
 * {@see AchievementSender}. A 403 deletes the row, closes the DMs of the learner (`dm_blocked_at`) and ends the job; a
 * definite refusal by the Bot API deletes it too, so the retry may deliver. Nothing is sent for a grant that was skipped
 * (perpetual Pro, deleted or blocked learner), for a blocked learner or one whose DM is closed.
 */
final class ReferralMilestoneSender
{
    public const NOTIFICATION_TYPE = 'referral_milestone';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly ReferralService $referrals,
        private readonly DmReachability $reachability,
    ) {}

    /** @return 'sent'|'forbidden'|'skipped' */
    public function send(int $learnerId, int $milestone, int $days, ?int $subscriptionId, ?string $skipped): string
    {
        if ($skipped !== null || $subscriptionId === null) {
            return 'skipped';
        }

        /** @var Learner|null $learner */
        $learner = Learner::query()->find($learnerId);
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($subscriptionId);

        if ($learner === null || $subscription === null || $subscription->current_period_ends_at === null
            || $learner->blocked_at !== null || $learner->dm_blocked_at !== null
        ) {
            return 'skipped';
        }

        return Cache::lock('referral-milestone:dm:'.$learner->id.':'.$milestone, 60)->block(10, function () use ($learner, $milestone, $days, $subscription): string {
            $key = 'milestone:'.$milestone;

            $alreadySent = NotificationLog::query()
                ->where('learner_id', $learner->id)
                ->where('type', self::NOTIFICATION_TYPE)
                ->where('payload->key', $key)
                ->exists();
            if ($alreadySent) {
                return 'skipped';
            }

            $text = $this->text($learner, $milestone, $days, $subscription);

            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => self::NOTIFICATION_TYPE,
                'payload' => ['key' => $key],
                'sent_at' => now(),
            ]);

            try {
                $this->telegram->sendMessage($learner->telegram_user_id, $text);
            } catch (TelegramForbiddenException $e) {
                $log->delete();
                $this->reachability->markUnreachable($learner->telegram_user_id, $e->reason);
                Log::warning('telegram.referral_milestone_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

                return 'forbidden';
            } catch (TelegramApiException $e) {
                // A transport failure may still have delivered (the marker stays); a definite refusal did not.
                if ($e->isApiResponse()) {
                    $log->delete();
                }

                throw $e;
            }

            return 'sent';
        });
    }

    private function text(Learner $learner, int $milestone, int $days, Subscription $subscription): string
    {
        $locale = $this->locales->forLearner($learner);
        $until = $subscription->current_period_ends_at?->toImmutable()->setTimezone($learner->timezone)->format('d.m.Y') ?? '';

        $text = trans_choice('referrals.milestone.reached', $milestone, [
            'count' => $milestone,
            'days' => trans_choice('referrals.days', $days, ['count' => $days], $locale),
            'date' => $until,
        ], $locale);

        // The next milestone after this one (nothing after the last); the friends counted by now may already be past this one.
        $next = null;
        foreach ($this->referrals->milestones() as $m) {
            if ($m > $milestone) {
                $next = $m;
                break;
            }
        }

        if ($next !== null) {
            $remaining = max(1, $next - max($milestone, $this->referrals->countedFor($learner)));
            $text .= "\n".trans_choice('referrals.milestone.next', $remaining, ['count' => $remaining], $locale);
        }

        return $text;
    }
}

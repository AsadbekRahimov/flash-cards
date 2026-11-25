<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Services\ProPitch;
use App\Domain\Telegram\Services\ProScreen;
use App\Jobs\SendTrialOfferJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The offers after the welcome trial (docs/34 §2б.4, spec §2д; owner's decision docs/31 §5г: on the day it ended, 3 and 10 days after,
 * no discount). {@see queueDue()} (the hourly `billing:trial-offers`) picks the trials whose step is due and whose learner's clock is in
 * the day window; {@see send()} (the job) re-reads everything and sends at most one message.
 *
 * Rules of {@see send()}, in this order:
 *  1. the subscription is still the trial that ended at `$endsAt` (not bought, promoted or extended since), the step is not stale;
 *  2. the learner is there, not blocked, the DM is open, the learner did not opt out (`meta.billing_offers_opt_out`);
 *  3. the plan resolved WITHOUT the cache is Free (a purchase, a promo, a seat of a center, a reward of friends stop the chain);
 *  4. nothing for sale: step 0 goes without prices ("Pro is coming" + the way through friends), steps 3 and 10 do not go;
 *  5. under the lock {@see proDmLock()} (taken by RenewalReminderSender too): no other message about Pro (`trial_offer`,
 *     `renewal_reminder`) in the learner's local day - otherwise nothing, the next run tries again; then the marker of the step
 *     (`notifications_log`, `type = trial_offer`, `payload.key`) is written BEFORE the send, and an existing one ends it;
 *  6. a 403 removes the marker and marks the DM unreachable; any other definite refusal (a 429 included) removes it and is rethrown;
 *     a timeout / 5xx keeps it (docs/34 §4в п. 8: better missed than sent twice).
 */
final class TrialOfferSender
{
    public const NOTIFICATION_TYPE = 'trial_offer';

    /** `learners.meta` key of "Do not send offers" (`bill1:offers_off`). */
    public const OPT_OUT_META = 'billing_offers_opt_out';

    /** `from=` of the buttons into the Mini App. */
    public const FROM = 'trial_offer';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmReachability $reachability,
        private readonly EntitlementResolver $entitlements,
        private readonly ProPitch $pitch,
        private readonly ProScreen $screen,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
    ) {}

    /** The lock of "one message about Pro a local day" of one learner (docs/34 §2д п. 4): this sender and RenewalReminderSender. */
    public static function proDmLock(int $learnerId): string
    {
        return 'billing:pro-dm:learner:'.$learnerId;
    }

    /**
     * The steps of the chain: `billing.trial_offers.after_days`, whole days >= 0, unique, ascending. Empty = switched off.
     *
     * @return list<int>
     */
    public static function steps(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config('billing.trial_offers.after_days', []);
        $steps = [];

        foreach ($configured as $day) {
            if (is_numeric($day) && (int) $day >= 0) {
                $steps[(int) $day] = (int) $day;
            }
        }

        ksort($steps);

        return array_values($steps);
    }

    /**
     * Queues {@see SendTrialOfferJob} for every trial whose step is due now: `T` (the end of the trial) in
     * (now − k days − max_age, now − k days − (k = 0 ? min_delay : 0)], the learner reachable and not opted out (the sender checks it
     * all again), and the learner's clock in [window_from_hour, window_to_hour). Queuing twice is harmless: the marker decides.
     *
     * @return int jobs queued
     */
    public function queueDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $maxAge = max(1, (int) config('billing.trial_offers.max_age_hours', 24));
        $minDelay = max(0, (int) config('billing.trial_offers.min_delay_minutes', 60));
        $queued = 0;

        foreach (self::steps() as $step) {
            $base = $now->subDays($step);
            $upper = $step === 0 ? $base->subMinutes($minDelay) : $base;
            $lower = $base->subHours($maxAge);

            Subscription::query()
                ->where('provider', SubscriptionProvider::Trial->value)
                ->where('subscriber_type', 'learner')
                ->where('current_period_ends_at', '>', $lower)
                ->where('current_period_ends_at', '<=', $upper)
                ->whereIn('subscriber_id', Learner::query()
                    ->select('id')
                    ->whereNull('blocked_at')
                    ->whereNull('dm_blocked_at')
                    ->where(static fn (Builder $q) => $q
                        ->whereNull('meta->'.self::OPT_OUT_META)
                        ->orWhere('meta->'.self::OPT_OUT_META, false)))
                ->orderBy('id')
                ->select(['id', 'subscriber_id', 'current_period_ends_at'])
                ->chunkById(500, function ($subscriptions) use ($step, $now, &$queued): void {
                    /** @var Collection<int, Subscription> $subscriptions */
                    $learners = Learner::query()
                        ->whereIn('id', $subscriptions->pluck('subscriber_id')->all())
                        ->get(['id', 'timezone'])
                        ->keyBy('id');

                    foreach ($subscriptions as $subscription) {
                        $learner = $learners->get($subscription->subscriber_id);

                        if ($learner === null || $subscription->current_period_ends_at === null || ! $this->inWindow($learner, $now)) {
                            continue;
                        }

                        SendTrialOfferJob::dispatch(
                            $subscription->id,
                            $step,
                            CarbonImmutable::instance($subscription->current_period_ends_at)->utc()->toIso8601String(),
                        );
                        $queued++;
                    }
                });
        }

        return $queued;
    }

    /** True when a message went out. */
    public function send(int $subscriptionId, int $step, CarbonImmutable $endsAt, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null || ! $this->stillDue($subscription, $step, $endsAt, $now)) {
            return false;
        }

        // A soft-deleted learner is not found.
        /** @var Learner|null $learner */
        $learner = Learner::query()->find($subscription->subscriber_id);

        if ($learner === null || $learner->blocked_at !== null || $learner->dm_blocked_at !== null || self::optedOut($learner)) {
            return false;
        }

        // Without the cache: the snapshot may lag a purchase or a seat of a center by its TTL.
        if ($this->entitlements->resolve($learner, $now)->plan !== Plan::Free) {
            return false;
        }

        ['selling' => $selling, 'catalog' => $catalog] = $this->pitch->offer($learner);

        // Nothing for sale: only step 0 is worth a message (what Free is now, the way through friends).
        if (! $selling && $step !== 0) {
            return false;
        }

        $locale = $this->locales->forLearner($learner);
        $text = $this->text($learner, $subscription, $step, $endsAt, $locale, $selling ? $this->pitch->prices($catalog, $locale) : null);
        $markup = $this->pitch->keyboard($learner, $locale, $selling, optOut: true, from: self::FROM);
        $key = $subscription->id.':'.$step.'@'.$endsAt->utc()->toIso8601String();

        $sent = Cache::lock(self::proDmLock($learner->id), 60)->block(10, function () use ($learner, $subscription, $step, $key, $text, $markup, $now): bool {
            $day = $this->clock->dayBoundsUtc($learner, $now);

            $alreadyToday = NotificationLog::query()
                ->where('learner_id', $learner->id)
                ->whereIn('type', [self::NOTIFICATION_TYPE, RenewalReminderSender::NOTIFICATION_TYPE])
                ->where('sent_at', '>=', $day->startUtc)
                ->where('sent_at', '<', $day->endUtc)
                ->exists();

            if ($alreadyToday) {
                return false;
            }

            $exists = NotificationLog::query()
                ->where('learner_id', $learner->id)
                ->where('type', self::NOTIFICATION_TYPE)
                ->where('payload->key', $key)
                ->exists();

            if ($exists) {
                return false;
            }

            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => self::NOTIFICATION_TYPE,
                'payload' => ['key' => $key, 'subscription_id' => $subscription->id, 'step' => $step],
                'sent_at' => $now,
            ]);

            try {
                $this->telegram->sendMessage($learner->telegram_user_id, $text, replyMarkup: $markup);
            } catch (TelegramForbiddenException $e) {
                // Certainly not delivered: it must not count, and the learner leaves the DM selections.
                $log->delete();
                $this->reachability->markUnreachable($learner->telegram_user_id, $e->reason);

                Log::warning('telegram.trial_offer_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

                return false;
            } catch (TelegramApiException $e) {
                if ($e->isApiResponse()) {
                    // Definitely not delivered (a 429 or another refusal): the retry must find no marker and send.
                    $log->delete();
                }

                // A timeout / 5xx may have been delivered: the marker stays (docs/34 §4в п. 8).
                throw $e;
            }

            return true;
        });

        if ($sent) {
            $this->events->record(ProductEventName::TrialOfferSent, [
                'step' => $step,
                'subscription_id' => $subscription->id,
                'priced' => $selling,
            ], learner: $learner);
        }

        return $sent;
    }

    /**
     * "Do not send offers" (`bill1:offers_off`): `meta.billing_offers_opt_out = true` under the row lock. True when it changed now
     * (the event `trial_offer.opted_out` is recorded then); a second tap changes and records nothing. Messages about a paid subscription
     * and the learning reminders are not affected.
     */
    public function optOut(Learner $learner): bool
    {
        $changed = DB::transaction(function () use ($learner): bool {
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner|null $fresh */
            $fresh = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->first();

            if ($fresh === null || self::optedOut($fresh)) {
                return false;
            }

            $fresh->meta = [...($fresh->meta ?? []), self::OPT_OUT_META => true];
            $fresh->save();

            return true;
        });

        if ($changed) {
            $learner->refresh();
            $this->events->record(ProductEventName::TrialOfferOptedOut, [], learner: $learner);
        }

        return $changed;
    }

    public static function optedOut(Learner $learner): bool
    {
        return ($learner->meta[self::OPT_OUT_META] ?? null) === true;
    }

    private function stillDue(Subscription $subscription, int $step, CarbonImmutable $endsAt, CarbonImmutable $now): bool
    {
        $period = $subscription->current_period_ends_at;
        $maxAge = max(1, (int) config('billing.trial_offers.max_age_hours', 24));

        return $subscription->provider === SubscriptionProvider::Trial->value
            && $subscription->subscriber_type === 'learner'
            && $period !== null
            && CarbonImmutable::instance($period)->equalTo($endsAt)
            && $now->isBefore($endsAt->addDays($step)->addHours($maxAge))
            && ! $now->isBefore($endsAt->addDays($step));
    }

    private function inWindow(Learner $learner, CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone($this->clock->timezone($learner))->format('G');

        return $hour >= (int) config('billing.trial_offers.window_from_hour', 11)
            && $hour < (int) config('billing.trial_offers.window_to_hour', 19);
    }

    /** The text of a step; `$prices` null = nothing for sale (step 0 only). */
    private function text(Learner $learner, Subscription $subscription, int $step, CarbonImmutable $endsAt, string $locale, ?string $prices): string
    {
        $date = $this->screen->date($endsAt, $learner);

        // Step 0: what Free is now; the last step of the chain: the last reminder; any step between: what the trial gave.
        $steps = self::steps();
        $head = match (true) {
            $step === 0 => $this->freeNow($learner, $locale),
            $steps !== [] && $step === max($steps) => (string) __('billing.trial_offer.last', ['date' => $date], $locale),
            default => $this->summary($learner, $subscription, $endsAt, $date, $locale),
        };

        $parts = [$head];

        $benefits = $this->pitch->benefits($learner, $locale);
        if ($benefits !== '') {
            $parts[] = $benefits;
        }

        if ($prices === null) {
            $tail = [(string) __('billing.pro.soon', [], $locale)];
            if ((array) config('referrals.milestones', []) !== []) {
                $tail[] = (string) __('referrals.hint', [], $locale);
            }
            $parts[] = implode("\n", $tail);
        } else {
            $parts[] = trim($prices."\n".__('billing.pro.choose', [], $locale));
        }

        return implode("\n\n", $parts);
    }

    /** Step 0: "The trial is over. You are on Free now — a day: 10 new words, a goal of up to 10 cards, 3 word games." */
    private function freeNow(Learner $learner, string $locale): string
    {
        $limits = [];

        $words = Plan::Free->newWordsPerDay();
        if ($words !== null) {
            $limits[] = trans_choice('billing.trial_offer.limit_words', $words, ['count' => $words], $locale);
        }

        $goal = Plan::Free->dailyGoalMax();
        if ($goal !== null) {
            $limits[] = trans_choice('billing.trial_offer.limit_goal', $goal, ['count' => $goal], $locale);
        }

        // Word games (practice) only while they are open to this learner and Free has a limit above zero.
        $games = config('practice.free_limits.sessions_per_day');
        if (is_numeric($games) && (int) $games > 0 && $this->pitch->practiceOpen($learner)) {
            $limits[] = trans_choice('billing.trial_offer.limit_games', (int) $games, ['count' => (int) $games], $locale);
        }

        return $limits === []
            ? (string) __('billing.trial_offer.ended_generic', [], $locale)
            : (string) __('billing.trial_offer.ended', ['limits' => implode(', ', $limits)], $locale);
    }

    /**
     * Step 3: what the learner did during the trial - the new words of the local days [start, end] (`learner_daily_stats`) and the
     * best streak (`learners.streak_best`) - and "bring Pro back".
     */
    private function summary(Learner $learner, Subscription $subscription, CarbonImmutable $endsAt, string $date, string $locale): string
    {
        $from = $this->clock->localDate($learner, CarbonImmutable::instance($subscription->starts_at));
        $to = $this->clock->localDate($learner, $endsAt);

        $words = (int) DB::table('learner_daily_stats')
            ->where('learner_id', $learner->id)
            ->where('date', '>=', $from)
            ->where('date', '<=', $to)
            ->sum('new_words_count');

        $lines = [(string) __('billing.trial_offer.ended_on', ['date' => $date], $locale)];

        if ($words > 0) {
            $lines[] = (string) __('billing.trial_offer.learned', [
                'words' => trans_choice('billing.trial_offer.words', $words, ['count' => $words], $locale),
            ], $locale);
        }

        if ($learner->streak_best > 0) {
            $lines[] = (string) __('billing.trial_offer.best_streak', [
                'streak' => trans_choice('referrals.days', $learner->streak_best, ['count' => $learner->streak_best], $locale),
            ], $locale);
        }

        $lines[] = (string) __('billing.trial_offer.back', [], $locale);

        return implode("\n", $lines);
    }
}

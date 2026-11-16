<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\Learner;
use App\Models\ProTrial;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The welcome trial (docs/13 §7.1 "2C.9", owner's decision of 16.11.2026): every learner CREATED after the launch gets
 * `billing.trial.days` of Pro, once per Telegram account for good.
 *
 * Two marks carry it:
 *   - `learners.meta.pro_trial_pending` — written by {@see LearnerRegistrar} into every
 *     row it creates (whatever the path: `/start`, the Mini App, a group event). Only rows created by code that knows
 *     the trial carry it, so the learners of before the launch never get one and no backfill is needed. The first
 *     SELF-INITIATED contact ({@see offer()}) turns it into a grant — or into nothing — and removes it either way; a
 *     row created by a passive event (somebody added the person to a group) keeps the clock stopped until the
 *     person really shows up;
 *   - `pro_trials.telegram_hash` (unique) — the book: an HMAC of the Telegram id, not the id (the row outlives the
 *     purge of the learner, docs/03 §8 rule 2, and must not keep a deleted person's id). "Delete the account,
 *     register again" makes a new row with the marker; the book refuses it (`trial.refused {already_used}`).
 *
 * One transaction: the learner row FOR UPDATE (two parallel first contacts — `/start` and the Mini App — serialize
 * here and the second finds the marker gone) → the book row (the unique key is the last word) → the subscription
 * ({@see SubscriptionService::grantTrial()}, which locks the learner again) → the marker and the one-shot notice
 * (`meta.pro_trial_notice`, consumed by the bot's welcome DM — the Mini App shows the trial from the entitlement and does
 * not take it — the pattern of AccountRestoreNotice). Lock order: `learners` → `pro_trials` → `subscriptions` → `product_events`.
 *
 * A failure never fails the registration ({@see offer()} reports and returns null); the marker stays, so the next
 * contact tries again.
 */
final class ProTrialService
{
    /** `learners.meta` key: the row is eligible, the trial was not decided yet. */
    public const META_PENDING = 'pro_trial_pending';

    /** `learners.meta` key: ISO end of the trial just granted, until a channel has told the learner. */
    public const META_NOTICE = 'pro_trial_notice';

    public const REFUSED_ALREADY_USED = 'already_used';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
    ) {}

    public function days(): int
    {
        return max(0, (int) config('billing.trial.days', 0));
    }

    public function enabled(): bool
    {
        return $this->days() > 0;
    }

    /** The in-memory check of the registrar: no query. */
    public function isPending(Learner $learner): bool
    {
        return ($learner->meta[self::META_PENDING] ?? null) === true;
    }

    /**
     * The hook of the first own contact: grants the trial to a learner whose row carries the pending marker, refuses
     * one whose Telegram account already had it, and leaves a blocked learner or a switched-off trial for later. Never
     * throws: the registration must not depend on it. Returns the trial subscription when one was granted now.
     */
    public function offer(Learner $learner, ?CarbonImmutable $now = null): ?Subscription
    {
        if (! $this->isPending($learner) || ! $this->enabled() || $learner->blocked_at !== null) {
            return null;
        }

        try {
            return $this->decide($learner, $now ?? CarbonImmutable::now());
        } catch (Throwable $e) {
            report($e);
            Log::error('billing.trial_grant_failed', ['learner_id' => $learner->id, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The end of a trial granted and not yet announced (reads the loaded `meta`, no query). */
    public function pendingNotice(Learner $learner): ?CarbonImmutable
    {
        $raw = $learner->meta[self::META_NOTICE] ?? null;

        return is_string($raw) && $raw !== '' ? CarbonImmutable::parse($raw) : null;
    }

    /**
     * Whether a notice that ends at `$endsAt` still tells the truth: the end is in the future and the learner's live
     * subscription is still the trial (not paid / promo over it). Reads one subscription; the caller has taken the notice
     * already, so a stale one is dropped silently.
     */
    public function noticeIsCurrent(Learner $learner, CarbonImmutable $endsAt, ?CarbonImmutable $now = null): bool
    {
        if ($endsAt->lessThanOrEqualTo($now ?? CarbonImmutable::now())) {
            return false;
        }

        $live = $this->subscriptions->liveFor($learner);

        return $live !== null && $live->provider === SubscriptionProvider::Trial->value;
    }

    /**
     * Takes the notice: the end of the trial when this call got it, null when there was none or another channel was
     * first. A short transaction under the row lock, like AccountRestoreNotice::consume().
     */
    public function consumeNotice(Learner $learner): ?CarbonImmutable
    {
        /** @var array{meta: array<string, mixed>, ends_at: string}|null $taken */
        $taken = DB::transaction(function () use ($learner): ?array {
            /** @var Learner|null $current */
            $current = Learner::query()->whereKey($learner->id)->lockForUpdate()->first();
            $raw = $current?->meta[self::META_NOTICE] ?? null;

            if ($current === null || ! is_string($raw) || $raw === '') {
                return null;
            }

            $meta = array_diff_key($current->meta, [self::META_NOTICE => true]);
            $current->meta = $meta;
            $current->save();

            return ['meta' => $meta, 'ends_at' => $raw];
        });

        $this->remember($learner, $taken['meta'] ?? array_diff_key($learner->meta, [self::META_NOTICE => true]));

        return $taken === null ? null : CarbonImmutable::parse($taken['ends_at']);
    }

    /** The book key of a Telegram account: an HMAC with `billing.trial.hash_key` (APP_KEY when empty; set it once and never change it, or a rotation of APP_KEY reopens the trial for everybody), so the raw id is never stored. */
    public static function hashFor(int $telegramUserId): string
    {
        return hash_hmac('sha256', 'pro_trial:'.$telegramUserId, (string) (config('billing.trial.hash_key') ?: config('app.key')));
    }

    private function decide(Learner $learner, CarbonImmutable $now): ?Subscription
    {
        $days = $this->days();
        $hash = self::hashFor($learner->telegram_user_id);

        /** @var array{meta: array<string, mixed>, subscription: Subscription|null, refused: string|null}|null $result */
        $result = DB::transaction(function () use ($learner, $now, $days, $hash): ?array {
            /** @var Learner|null $current */
            $current = Learner::query()->whereKey($learner->id)->lockForUpdate()->first();
            if ($current === null || ($current->meta[self::META_PENDING] ?? null) !== true) {
                return null; // a parallel contact decided it first (or the row is gone)
            }

            $meta = array_diff_key($current->meta, [self::META_PENDING => true]);

            if (! $this->claim($current, $hash, $days, $now)) {
                $current->meta = $meta;
                $current->save();

                return ['meta' => $meta, 'subscription' => null, 'refused' => self::REFUSED_ALREADY_USED];
            }

            $subscription = $this->subscriptions->grantTrial($current, $days, $now);
            $endsAt = $subscription->current_period_ends_at === null ? null : CarbonImmutable::instance($subscription->current_period_ends_at);

            ProTrial::query()
                ->where('telegram_hash', $hash)
                ->update(['subscription_id' => $subscription->id, 'ends_at' => $endsAt]);

            if ($endsAt !== null) {
                $meta[self::META_NOTICE] = $endsAt->toIso8601String();
            }
            $current->meta = $meta;
            $current->save();

            return ['meta' => $meta, 'subscription' => $subscription, 'refused' => null];
        });

        if ($result === null) {
            $learner->refresh();

            return null;
        }

        $this->remember($learner, $result['meta']);

        if ($result['refused'] !== null) {
            $this->events->record(ProductEventName::TrialRefused, ['reason' => $result['refused']], learner: $learner);
            Log::info('billing.trial_refused', ['learner_id' => $learner->id, 'reason' => $result['refused']]);

            return null;
        }

        /** @var Subscription $subscription */
        $subscription = $result['subscription'];
        $this->events->record(
            ProductEventName::TrialGranted,
            [
                'days' => $days,
                'subscription_id' => $subscription->id,
                'ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                'extended' => $subscription->provider !== SubscriptionProvider::Trial->value,
            ],
            learner: $learner,
        );

        return $subscription;
    }

    /**
     * Inside the transaction, the learner row held: writes the book row for this Telegram account. False when the
     * account already has one. The insert runs in a savepoint, so a unique violation (a book row committed between our
     * check and the insert — only another learner row of the same Telegram id could do that, which the unique
     * `learners.telegram_user_id` forbids) does not poison the outer PostgreSQL transaction.
     */
    private function claim(Learner $learner, string $hash, int $days, CarbonImmutable $now): bool
    {
        if (ProTrial::query()->where('telegram_hash', $hash)->exists()) {
            return false;
        }

        try {
            DB::transaction(static fn (): ProTrial => ProTrial::query()->create([
                'telegram_hash' => $hash,
                'learner_id' => $learner->id,
                'subscription_id' => null,
                'days' => $days,
                'granted_at' => $now,
                'ends_at' => null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $meta */
    private function remember(Learner $learner, array $meta): void
    {
        $learner->setAttribute('meta', $meta);
        $learner->syncOriginalAttribute('meta');
    }
}

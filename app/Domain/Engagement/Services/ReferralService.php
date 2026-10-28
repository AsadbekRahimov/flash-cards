<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Engagement\DTO\ReferralOverview;
use App\Domain\Engagement\Enums\ReferralRejectReason;
use App\Domain\Engagement\Enums\ReferralStatus;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\StoredDate;
use App\Jobs\RewardReferralJob;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * "Invite a friend" (docs/13 §1.5, §2.4, §7.1).
 *
 *   codeFor   — the inviter's `learners.referral_code`, generated lazily (unique, unguessable);
 *   link      — `/start ref_<code>`: a `referrals` row for a learner CREATED by that /start; the
 *               inviter's own code or an existing learner → `rejected` (first-touch attribution is
 *               LearnerRegistrar's business and stays as it is);
 *   evaluate  — after a finished session / a met daily goal: `pending` → `activated` once the friend
 *               has `activation.days` local days with ≥ `reviews_per_day` graded cards inside the
 *               `window_days` window; a conditional UPDATE, then the reward job;
 *   reward    — the claim (`activated` → `rewarded`, inviter's caps, burst flag) and then ONE
 *               transaction per side (`meta.rewards.<side>`), so a retry grants only what is missing.
 *
 * Lock order (docs/13 §3): `referrals` rows (FOR UPDATE, by id) → `learners` row (FOR UPDATE, inside
 * SubscriptionService::grantReward) → `subscriptions`. Two learners are never locked in one
 * transaction, and nothing here runs inside the grading transaction: `evaluate` is called after
 * commit, the writes of `link`/`evaluate` are single statements outside any learner lock.
 */
final class ReferralService
{
    public const AUDIT_UNFLAGGED = 'referral.unflagged';

    public const AUDIT_REJECTED = 'referral.rejected';

    /** `meta.rewards.<side>.skipped` values. */
    public const SKIPPED_PERPETUAL = 'perpetual';

    public const SKIPPED_LEARNER_MISSING = 'learner_missing';

    /** The inviter had reached `max_rewards_*`: their side gets nothing, the invited learner's reward is unaffected. */
    public const SKIPPED_REFERRER_CAPPED = 'referrer_capped';

    /** The inviter had already collected `max_invitee_rewards_per_30d` invited learners' rewards: the invited learner gets nothing, the inviter's reward is unaffected. */
    public const SKIPPED_INVITEE_CAPPED = 'invitee_capped';

    /** The learner was deleted (soft) or blocked by an administrator by the time the reward is granted. */
    public const SKIPPED_LEARNER_DELETED = 'learner_deleted';

    public const SKIPPED_LEARNER_BLOCKED = 'learner_blocked';

    /** `referral.rejected` reason for a link whose code belongs to nobody (no row: there is no inviter to reference). */
    public const REASON_UNKNOWN_CODE = 'unknown_code';

    private const CODE_ATTEMPTS = 8;

    private const CLAIM_CLAIMED = 'claimed';

    private const CLAIM_ALREADY = 'already';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
        private readonly LearnerClock $clock,
        private readonly ReferralCodeGenerator $codes,
    ) {}

    /**
     * The learner's invite code, generated on first use. A conditional UPDATE (`referral_code IS
     * NULL`) makes two parallel first calls agree on one code; a unique collision is retried with
     * a fresh code.
     */
    public function codeFor(Learner $learner): string
    {
        if ($learner->referral_code !== null) {
            return $learner->referral_code;
        }

        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = $this->codes->generate();

            try {
                // Savepoint: a unique violation must not poison an outer (PostgreSQL) transaction.
                $updated = DB::transaction(static fn (): int => Learner::withTrashed()
                    ->whereKey($learner->id)
                    ->whereNull('referral_code')
                    ->update(['referral_code' => $code]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            if ($updated === 0) {
                // Somebody else (a parallel request) generated it first.
                break;
            }

            $learner->forceFill(['referral_code' => $code])->syncOriginalAttributes(['referral_code']);

            return $code;
        }

        $existing = Learner::withTrashed()->whereKey($learner->id)->value('referral_code');
        if (! is_string($existing) || $existing === '') {
            throw new RuntimeException("Could not generate a referral code for learner {$learner->id}.");
        }

        $learner->forceFill(['referral_code' => $existing])->syncOriginalAttributes(['referral_code']);

        return $existing;
    }

    /** `t.me/<bot>?start=ref_<code>`; null while `TELEGRAM_BOT_USERNAME` is not configured. */
    public function inviteLink(string $code): ?string
    {
        $username = ltrim(trim((string) config('telegram.bot_username')), '@');

        return $username === '' ? null : "https://t.me/{$username}?start=ref_{$code}";
    }

    /**
     * `/start ref_<code>` of `$referee`. A row is written only once per invited learner
     * (`referee_learner_id` unique): a repeated /start returns the existing row untouched.
     *
     *   - unknown / blocked inviter → null, `referral.rejected {unknown_code}` (no row: nothing to reference);
     *   - the learner's own code → `rejected {self}`;
     *   - a learner who existed before this /start (`wasRecentlyCreated` false) → `rejected {existing_learner}`;
     *   - the inviter already has `flag_links_per_day` links in the last 24 h → `pending` + `flagged_at`
     *     (the reward waits for an admin);
     *   - otherwise `pending`.
     */
    public function link(Learner $referee, string $code, CarbonImmutable $now): ?Referral
    {
        /** @var Learner|null $referrer */
        $referrer = Learner::query()->where('referral_code', $code)->whereNull('blocked_at')->first();
        if ($referrer === null) {
            $this->events->record(ProductEventName::ReferralRejected, ['reason' => self::REASON_UNKNOWN_CODE], learner: $referee);

            return null;
        }

        $reason = match (true) {
            $referrer->id === $referee->id => ReferralRejectReason::SelfReferral,
            ! $referee->wasRecentlyCreated => ReferralRejectReason::ExistingLearner,
            default => null,
        };
        $flagged = $reason === null && $this->isBurst($referrer->id, $now);

        try {
            // Savepoint: the unique violation of a repeated /start must not poison an outer (PostgreSQL) transaction.
            /** @var Referral $referral */
            $referral = DB::transaction(static fn (): Referral => Referral::query()->create([
                'referrer_learner_id' => $referrer->id,
                'referee_learner_id' => $referee->id,
                'status' => $reason === null ? ReferralStatus::Pending->value : ReferralStatus::Rejected->value,
                'reject_reason' => $reason?->value,
                'linked_at' => $now,
                'flagged_at' => $flagged ? $now : null,
                'meta' => [],
            ]));
        } catch (UniqueConstraintViolationException) {
            /** @var Referral|null $existing */
            $existing = Referral::query()->where('referee_learner_id', $referee->id)->first();

            return $existing;
        }

        if ($reason !== null) {
            $this->events->record(
                ProductEventName::ReferralRejected,
                ['reason' => $reason->value, 'referral_id' => $referral->id, 'referrer_learner_id' => $referrer->id],
                learner: $referee,
            );

            return $referral;
        }

        if ($flagged) {
            Log::warning('referrals.link_burst', ['referrer_learner_id' => $referrer->id, 'referral_id' => $referral->id]);
        }

        $this->events->record(
            ProductEventName::ReferralLinked,
            ['referral_id' => $referral->id, 'referrer_learner_id' => $referrer->id, 'flagged' => $flagged],
            learner: $referee,
        );

        return $referral;
    }

    /**
     * Listener of `training.session_finished` / `daily_goal.met` for the invited learner: a pending
     * referral becomes `activated` when the activation rule holds. One indexed read for a learner
     * without a pending referral (the common case). Must be called OUTSIDE the grading transaction.
     */
    public function evaluate(Learner $referee, CarbonImmutable $now): void
    {
        /** @var Referral|null $referral */
        $referral = Referral::query()
            ->where('referee_learner_id', $referee->id)
            ->where('status', ReferralStatus::Pending->value)
            ->first();
        if ($referral === null) {
            return;
        }

        $days = $this->activeDays($referee, $referral);
        if (count($days) < max(1, (int) config('referrals.activation.days'))) {
            return;
        }

        $updated = Referral::query()
            ->whereKey($referral->id)
            ->where('status', ReferralStatus::Pending->value)
            ->update([
                'status' => ReferralStatus::Activated->value,
                'activated_at' => $now,
                'meta' => json_encode([...$referral->meta, 'active_days' => $days], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
        if ($updated === 0) {
            return; // a parallel evaluation won
        }

        $this->events->record(
            ProductEventName::ReferralActivated,
            ['referral_id' => $referral->id, 'referrer_learner_id' => $referral->referrer_learner_id, 'active_days' => count($days)],
            learner: $referee,
        );

        RewardReferralJob::dispatch($referral->id)->afterCommit();
    }

    /**
     * The reward, idempotent: the claim `activated` → `rewarded` (under the inviter's referral rows,
     * which also serializes the cap check), then one transaction per side. A side already present in
     * `meta.rewards` is skipped, so a job retry after a partial failure grants only the missing one.
     * A flagged referral is left as it is until an admin clears the flag; for a capped inviter the
     * referral is still `rewarded` but their side is closed `skipped: referrer_capped`; the invited learner
     * of an inviter who already has `max_invitee_rewards_per_30d` of them in 30 days gets `skipped: invitee_capped`.
     */
    public function reward(Referral $referral, CarbonImmutable $now): void
    {
        $outcome = DB::transaction(fn (): string => $this->claim($referral->id, $now));
        if (! in_array($outcome, [self::CLAIM_CLAIMED, self::CLAIM_ALREADY], true)) {
            return;
        }

        $days = max(1, (int) config('referrals.reward_days'));
        foreach ([Referral::SIDE_REFEREE, Referral::SIDE_REFERRER] as $side) {
            DB::transaction(fn () => $this->grantSide($referral->id, $side, $days, $now));
        }

        $referral->refresh();
    }

    /**
     * Recovery sweep (scheduler): a lost or failed {@see RewardReferralJob} — the queue was down at the
     * dispatch, the job exhausted its tries, an exception after the commit — leaves a referral that is
     * `activated`, or `rewarded` with a side still missing. Re-queues the job for those that have waited
     * `$olderThanMinutes` (a fresh one is still in flight). Flagged referrals wait for an admin. Safe to
     * repeat: the reward is idempotent by the data ({@see reward()}). Only rows activated / rewarded within
     * `referrals.recover_window_days` are looked at, so the sweep stays an index range scan; a row stuck for
     * longer is left for a manual look.
     *
     * @return int jobs queued
     */
    public function recoverStuck(CarbonImmutable $now, int $olderThanMinutes = 10, int $limit = 500): int
    {
        $before = $now->subMinutes(max(1, $olderThanMinutes));
        $since = $now->subDays(max(1, (int) config('referrals.recover_window_days', 14)));

        /** @var Collection<int, Referral> $stuck */
        $stuck = Referral::query()
            ->whereNull('flagged_at')
            ->where(function (Builder $q) use ($before, $since): void {
                $q->where(fn (Builder $a) => $a->where('status', ReferralStatus::Activated->value)->where('activated_at', '<', $before)->where('activated_at', '>=', $since))
                    ->orWhere(fn (Builder $r) => $r->where('status', ReferralStatus::Rewarded->value)
                        ->where('rewarded_at', '<', $before)
                        ->where('rewarded_at', '>=', $since)
                        ->where(fn (Builder $m) => $m->whereNull('meta->rewards->'.Referral::SIDE_REFEREE)->orWhereNull('meta->rewards->'.Referral::SIDE_REFERRER)));
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $queued = 0;
        foreach ($stuck as $referral) {
            if ($referral->status === ReferralStatus::Rewarded->value && $referral->fullyRewarded()) {
                continue;
            }
            RewardReferralJob::dispatch($referral->id);
            $queued++;
        }

        return $queued;
    }

    /**
     * Admin: clears `flagged_at` (audited `referral.unflagged`); an activated referral is rewarded right away.
     *
     * @return bool false when the referral was not flagged
     */
    public function unflag(Referral $referral, User $by, ?string $ip = null, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $cleared = DB::transaction(function () use ($referral, $by, $ip, $now): bool {
            /** @var Referral|null $row */
            $row = Referral::query()->whereKey($referral->id)->lockForUpdate()->first();
            if ($row === null || $row->flagged_at === null) {
                return false;
            }

            $row->forceFill(['flagged_at' => null])->save();
            $this->audit(self::AUDIT_UNFLAGGED, $row, $by, $ip, $now);
            $referral->setRawAttributes($row->getAttributes(), true);

            return true;
        });

        if ($cleared && $referral->status === ReferralStatus::Activated->value) {
            RewardReferralJob::dispatch($referral->id)->afterCommit();
        }

        return $cleared;
    }

    /**
     * Admin: refuses a flagged referral for good (`rejected {flagged}`, audited `referral.rejected`).
     *
     * @return bool false when it is already rewarded or rejected
     */
    public function rejectFlagged(Referral $referral, User $by, ?string $ip = null, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($referral, $by, $ip, $now): bool {
            /** @var Referral|null $row */
            $row = Referral::query()->whereKey($referral->id)->lockForUpdate()->first();
            if ($row === null || $row->flagged_at === null
                || in_array($row->status, [ReferralStatus::Rewarded->value, ReferralStatus::Rejected->value], true)
            ) {
                return false;
            }

            $row->forceFill(['status' => ReferralStatus::Rejected->value, 'reject_reason' => ReferralRejectReason::Flagged->value])->save();
            $this->audit(self::AUDIT_REJECTED, $row, $by, $ip, $now);
            $this->events->record(
                ProductEventName::ReferralRejected,
                ['reason' => ReferralRejectReason::Flagged->value, 'referral_id' => $row->id, 'referrer_learner_id' => $row->referrer_learner_id],
                learner: $row->referee,
            );
            $referral->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }

    /** What the TWA screen and `/invite` show: the code, the link, the counts and the invited learners (names only). */
    public function overview(Learner $learner, int $limit = 50): ReferralOverview
    {
        $code = $this->codeFor($learner);

        /** @var Collection<int, Referral> $rows */
        $rows = Referral::query()
            ->with('referee')
            ->where('referrer_learner_id', $learner->id)
            ->where('status', '<>', ReferralStatus::Rejected->value)
            ->orderByDesc('linked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        /** @var array<string, int> $byStatus */
        $byStatus = Referral::query()
            ->where('referrer_learner_id', $learner->id)
            ->where('status', '<>', ReferralStatus::Rejected->value)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        // Rewarded for the invited learner, but the inviter's own side was closed by the cap: not "rewarded" for them.
        $capped = Referral::query()
            ->where('referrer_learner_id', $learner->id)
            ->where('status', ReferralStatus::Rewarded->value)
            ->where('meta->rewards->'.Referral::SIDE_REFERRER.'->skipped', self::SKIPPED_REFERRER_CAPPED)
            ->count();

        $closed = $byStatus[ReferralStatus::Rewarded->value] ?? 0;
        $rewarded = max(0, $closed - $capped);
        $activated = ($byStatus[ReferralStatus::Activated->value] ?? 0) + $closed;
        $invited = $activated + ($byStatus[ReferralStatus::Pending->value] ?? 0);

        return new ReferralOverview(
            code: $code,
            link: $this->inviteLink($code),
            rewardDays: max(1, (int) config('referrals.reward_days')),
            activationDays: max(1, (int) config('referrals.activation.days')),
            activationReviewsPerDay: max(1, (int) config('referrals.activation.reviews_per_day')),
            activationWindowDays: max(1, (int) config('referrals.activation.window_days')),
            invited: $invited,
            activated: $activated,
            rewarded: $rewarded,
            capped: $capped,
            referrals: $rows,
        );
    }

    /**
     * Local days inside the activation window with enough graded cards, oldest first. The window
     * starts on the invited learner's local day of the link (day 1) and spans `window_days` days.
     *
     * @return list<string> `Y-m-d`
     */
    private function activeDays(Learner $referee, Referral $referral): array
    {
        $windowDays = max(1, (int) config('referrals.activation.window_days'));
        $reviewsPerDay = max(1, (int) config('referrals.activation.reviews_per_day'));

        $firstDay = $this->clock->localDate($referee, CarbonImmutable::instance($referral->linked_at));
        $lastDay = CarbonImmutable::parse($firstDay)->addDays($windowDays - 1)->toDateString();

        /** @var list<string> $days */
        $days = LearnerDailyStats::query()
            ->where('learner_id', $referee->id)
            ->where('reviews_count', '>=', $reviewsPerDay)
            ->where('date', '>=', StoredDate::of($firstDay))
            ->where('date', '<=', StoredDate::of($lastDay))
            ->orderBy('date')
            ->pluck('date')
            ->map(static fn (mixed $d): string => CarbonImmutable::parse($d)->toDateString())
            ->values()
            ->all();

        return $days;
    }

    /**
     * Inside a transaction. Locks the inviter's referral rows (by id, FOR UPDATE): the cap check and
     * the transition are atomic per inviter, and two parallel rewards of one referral serialize here.
     */
    private function claim(int $referralId, CarbonImmutable $now): string
    {
        $referrerId = Referral::query()->whereKey($referralId)->value('referrer_learner_id');
        if (! is_numeric($referrerId)) {
            return 'missing';
        }

        /** @var Collection<int, Referral> $rows */
        $rows = Referral::query()
            ->where('referrer_learner_id', (int) $referrerId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var Referral|null $row */
        $row = $rows->firstWhere('id', $referralId);
        if ($row === null) {
            return 'missing';
        }
        if ($row->status === ReferralStatus::Rewarded->value) {
            return self::CLAIM_ALREADY;
        }
        if ($row->status !== ReferralStatus::Activated->value) {
            return 'not_activated';
        }
        if ($row->flagged_at !== null) {
            return 'flagged';
        }

        $since = $now->subDays(30);
        // A referral whose inviter side was skipped by the cap took no reward from the inviter: it takes no slot either.
        $rewarded = $rows->filter(static fn (Referral $r): bool => $r->status === ReferralStatus::Rewarded->value
            && ($r->rewardOf(Referral::SIDE_REFERRER)['skipped'] ?? null) !== self::SKIPPED_REFERRER_CAPPED);
        $lifetime = $rewarded->count();
        $recent = $rewarded->filter(static fn (Referral $r): bool => $r->rewarded_at !== null && $r->rewarded_at->greaterThanOrEqualTo($since))->count();

        // The inviter's caps close ONLY the inviter's side (owner's decision, docs/13 §12 q.9).
        $capped = $lifetime >= max(0, (int) config('referrals.max_rewards_lifetime'))
            || $recent >= max(0, (int) config('referrals.max_rewards_per_30d'));

        // Round 2 (§12 q.17): no more than `max_invitee_rewards_per_30d` invited learners of one inviter are rewarded in a
        // rolling 30 days; the rest get nothing. Counted under the same row locks; a side closed `invitee_capped` took no slot.
        $inviteeRewarded = $rows->filter(static fn (Referral $r): bool => $r->status === ReferralStatus::Rewarded->value
            && $r->rewarded_at !== null && $r->rewarded_at->greaterThanOrEqualTo($since)
            && ($r->rewardOf(Referral::SIDE_REFEREE)['skipped'] ?? null) !== self::SKIPPED_INVITEE_CAPPED)->count();
        $inviteeCapped = $inviteeRewarded >= max(0, (int) config('referrals.max_invitee_rewards_per_30d'));

        // Sides are closed up front, so grantSide() leaves them alone. A referral with both sides closed stays
        // `rewarded` (it is processed, not refused): the reason sits in `meta.rewards.<side>.skipped`.
        $closed = [];
        if ($inviteeCapped) {
            $closed[Referral::SIDE_REFEREE] = ['subscription_id' => null, 'skipped' => self::SKIPPED_INVITEE_CAPPED];
        }
        if ($capped) {
            $closed[Referral::SIDE_REFERRER] = ['subscription_id' => null, 'skipped' => self::SKIPPED_REFERRER_CAPPED];
        }

        $row->forceFill([
            'status' => ReferralStatus::Rewarded->value,
            'rewarded_at' => $now,
            'meta' => [
                ...$row->meta,
                'rewards' => $closed,
                'reward_subscription_ids' => [],
            ],
        ])->save();

        return self::CLAIM_CLAIMED;
    }

    /**
     * Inside its own transaction: the referral row (FOR UPDATE) → the learner (FOR UPDATE, by
     * grantReward) → the subscription. Skips a side that is already in `meta.rewards`.
     */
    private function grantSide(int $referralId, string $side, int $days, CarbonImmutable $now): void
    {
        /** @var Referral|null $row */
        $row = Referral::query()->whereKey($referralId)->lockForUpdate()->first();
        if ($row === null || $row->status !== ReferralStatus::Rewarded->value || $row->rewardOf($side) !== null) {
            return;
        }

        $learnerId = $side === Referral::SIDE_REFEREE ? $row->referee_learner_id : $row->referrer_learner_id;
        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->find($learnerId);

        // Pro is not granted to somebody who is gone or banned; the side is closed with the reason, so a
        // retry or the recovery sweep does not come back to it.
        $skipped = match (true) {
            $learner === null => self::SKIPPED_LEARNER_MISSING,
            $learner->trashed() => self::SKIPPED_LEARNER_DELETED,
            $learner->blocked_at !== null => self::SKIPPED_LEARNER_BLOCKED,
            default => null,
        };

        if ($learner === null || $skipped !== null) {
            $entry = ['subscription_id' => null, 'skipped' => $skipped];
        } else {
            $subscription = $this->subscriptions->grantReward($learner, $days, 'referral:'.$row->id, $now);
            $entry = $subscription->current_period_ends_at === null
                ? ['subscription_id' => null, 'skipped' => self::SKIPPED_PERPETUAL]
                : ['subscription_id' => $subscription->id, 'skipped' => null];
        }

        $meta = $row->meta;
        $rewards = is_array($meta['rewards'] ?? null) ? $meta['rewards'] : [];
        $rewards[$side] = $entry;
        $ids = is_array($meta['reward_subscription_ids'] ?? null)
            ? array_values(array_filter($meta['reward_subscription_ids'], 'is_int'))
            : [];
        if ($entry['subscription_id'] !== null) {
            $ids[] = $entry['subscription_id'];
        }
        $meta['rewards'] = $rewards;
        $meta['reward_subscription_ids'] = array_values(array_unique($ids));

        $row->forceFill(['meta' => $meta])->save();

        if ($row->fullyRewarded()) {
            $this->events->record(
                ProductEventName::ReferralRewarded,
                [
                    'referral_id' => $row->id,
                    'referrer_learner_id' => $row->referrer_learner_id,
                    'days' => $days,
                    'referee_skipped' => $row->rewardOf(Referral::SIDE_REFEREE)['skipped'] ?? null,
                    'referrer_skipped' => $row->rewardOf(Referral::SIDE_REFERRER)['skipped'] ?? null,
                ],
                learner: $row->referee,
            );
        }
    }

    /** `flag_links_per_day` or more non-rejected links of the inviter in the last 24 hours. */
    private function isBurst(int $referrerId, CarbonImmutable $now): bool
    {
        $threshold = (int) config('referrals.flag_links_per_day');
        if ($threshold <= 0) {
            return false;
        }

        return Referral::query()
            ->where('referrer_learner_id', $referrerId)
            ->where('status', '<>', ReferralStatus::Rejected->value)
            ->where('linked_at', '>=', $now->subDay())
            ->where('linked_at', '<=', $now)
            ->count() >= $threshold;
    }

    private function audit(string $action, Referral $row, User $by, ?string $ip, CarbonImmutable $now): void
    {
        AuditLog::create([
            'user_id' => $by->id,
            'action' => $action,
            'entity_type' => Referral::class,
            'entity_id' => $row->id,
            'ip' => $ip,
            'meta' => [
                'referrer_learner_id' => $row->referrer_learner_id,
                'referee_learner_id' => $row->referee_learner_id,
                'status' => $row->status,
                'at' => $now->toIso8601String(),
            ],
        ]);
    }
}

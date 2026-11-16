<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Engagement\DTO\ReferralOverview;
use App\Domain\Engagement\Enums\ReferralRejectReason;
use App\Domain\Engagement\Enums\ReferralStatus;
use App\Domain\Engagement\Events\ReferralMilestoneReached;
use App\Domain\Game\Services\Economy\TicketService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Support\StoredDate;
use App\Jobs\GrantReferralMilestoneJob;
use App\Jobs\RewardReferralJob;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Referral;
use App\Models\ReferralMilestone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * "Invite a friend" (docs/13 §1.5, §2.4, §7.1; the milestone scheme of "Реализация 2C.9", owner's decision of 16.11.2026).
 *
 *   codeFor   — the inviter's `learners.referral_code`, generated lazily (unique, unguessable);
 *   link      — `/start ref_<code>`: a `referrals` row for a learner CREATED by that /start; the
 *               inviter's own code or an existing learner → `rejected` (first-touch attribution is
 *               LearnerRegistrar's business and stays as it is);
 *   evaluate  — after a graded day / a finished session: `pending` → `activated` once the friend has
 *               finished the onboarding and has `activation.days` local days with ≥ `reviews_per_day`
 *               graded cards inside the `window_days` window; a conditional UPDATE, then the reward job;
 *   reward    — the claim (`activated` → `rewarded` = COUNTED for the inviter) under the inviter's referral
 *               rows, the milestone rows it completes (`referral_milestones`, unique per inviter and
 *               milestone), then ONE transaction per owed milestone (`grantMilestone`) and the game tickets.
 *
 * The invited friend gets nothing here (every new learner has the welcome trial, ProTrialService); the
 * inviter is rewarded by milestones of counted friends — `referrals.milestone_days` of Pro at each value of
 * `referrals.milestones`, each once, nothing after the last. What counts: `rewarded` rows of the inviter —
 * a flagged referral is not claimed until an admin clears the flag, a rejected one never.
 *
 * Lock order (docs/13 §3 rule 4): the claim holds `referrals` rows (FOR UPDATE, the inviter's, by id) and inserts
 * `referral_milestones` (no learner lock); the grant holds a `referral_milestones` row (FOR UPDATE) → `learners`
 * (FOR UPDATE, inside SubscriptionService::grantReward) → `subscriptions`. Two learners are never locked in
 * one transaction, and nothing here runs inside the grading transaction: `evaluate` is called after commit,
 * the writes of `link`/`evaluate` are single statements outside any learner lock.
 */
final class ReferralService
{
    public const AUDIT_UNFLAGGED = 'referral.unflagged';

    public const AUDIT_REJECTED = 'referral.rejected';

    /** `referral_milestones.skipped` values: the milestone was settled without Pro, and why. */
    public const SKIPPED_PERPETUAL = 'perpetual';

    public const SKIPPED_LEARNER_MISSING = 'learner_missing';

    /** The inviter was deleted (soft) or blocked by an administrator by the time the reward is granted. */
    public const SKIPPED_LEARNER_DELETED = 'learner_deleted';

    public const SKIPPED_LEARNER_BLOCKED = 'learner_blocked';

    /** `referral.rejected` reason for a link whose code belongs to nobody (no row: there is no inviter to reference). */
    public const REASON_UNKNOWN_CODE = 'unknown_code';

    /** `subscriptions.provider_ref` of a milestone reward: `referral_milestone:<inviter>:<milestone>`. */
    public const REWARD_SOURCE_PREFIX = 'referral_milestone:';

    private const CODE_ATTEMPTS = 8;

    private const CLAIM_CLAIMED = 'claimed';

    private const CLAIM_ALREADY = 'already';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
        private readonly LearnerClock $clock,
        private readonly ReferralCodeGenerator $codes,
        private readonly TicketService $tickets,
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
     *     (the referral is not counted until an admin clears the flag);
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
     * Listener of `training.session_finished` / the day crossing `reviews_per_day` for the invited learner: a
     * pending referral becomes `activated` when the activation rule holds — the onboarding is done ("started
     * using" means the person went through the bot's setup, not only tapped cards in a group lesson) and enough
     * active local days are inside the window. One indexed read for a learner without a pending referral (the
     * common case). Must be called OUTSIDE the grading transaction.
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

        if ($referee->onboarding_state !== OnboardingService::DONE) {
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
     * The reward path, idempotent: the claim `activated` → `rewarded` under the inviter's referral rows (which
     * also serializes the counting — two friends activated at the same instant are counted one after the other),
     * the milestone rows the new count completes (unique per inviter and milestone), then one transaction per
     * milestone of this inviter still owed ({@see grantMilestone()}) — a job retry after a failure between the
     * claim and the grant settles only what is missing — and the game tickets for the counted friend. A flagged
     * referral is left as it is until an admin clears the flag.
     */
    public function reward(Referral $referral, CarbonImmutable $now): void
    {
        /** @var array{outcome: string, referrer_id: int|null} $claim */
        $claim = DB::transaction(fn (): array => $this->claim($referral->id, $now));
        if (! in_array($claim['outcome'], [self::CLAIM_CLAIMED, self::CLAIM_ALREADY], true) || $claim['referrer_id'] === null) {
            return;
        }

        /** @var list<int> $owed */
        $owed = ReferralMilestone::query()
            ->where('referrer_learner_id', $claim['referrer_id'])
            ->whereNull('granted_at')
            ->orderBy('milestone')
            ->pluck('id')
            ->all();
        foreach ($owed as $milestoneId) {
            $this->grantMilestone($milestoneId, $now);
        }

        $referral->refresh();

        // The tickets of the game to the inviter (docs/18 §12.7.3), per counted friend, in a transaction of their own, once per
        // referral (the key `referral:{id}`): a failure here is not swallowed - the job is idempotent and a retry grants only what is missing.
        $this->tickets->grantForReferral($referral, $now);
    }

    /**
     * Settles one reached milestone in its own transaction: the `referral_milestones` row (FOR UPDATE) → the inviter
     * (FOR UPDATE, by grantReward) → the subscription. A settled row is left alone, so the job retry, the recovery
     * sweep and a parallel reward of another friend cannot grant it twice. Pro is not granted to somebody who is gone
     * or banned, and cannot extend a perpetual grant: the row is then closed with `skipped`, and nobody comes back to it.
     * The product event is written inside; {@see ReferralMilestoneReached} is raised after the commit.
     */
    public function grantMilestone(int $milestoneId, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($milestoneId, $now): void {
            /** @var ReferralMilestone|null $row */
            $row = ReferralMilestone::query()->whereKey($milestoneId)->lockForUpdate()->first();
            if ($row === null || $row->isSettled()) {
                return;
            }

            /** @var Learner|null $learner */
            $learner = Learner::withTrashed()->find($row->referrer_learner_id);

            $skipped = match (true) {
                $learner === null => self::SKIPPED_LEARNER_MISSING,
                $learner->trashed() => self::SKIPPED_LEARNER_DELETED,
                $learner->blocked_at !== null => self::SKIPPED_LEARNER_BLOCKED,
                default => null,
            };

            $subscriptionId = null;
            if ($learner !== null && $skipped === null) {
                $subscription = $this->subscriptions->grantReward($learner, $row->days, self::REWARD_SOURCE_PREFIX.$row->referrer_learner_id.':'.$row->milestone, $now);
                if ($subscription->current_period_ends_at === null) {
                    $skipped = self::SKIPPED_PERPETUAL;
                } else {
                    $subscriptionId = $subscription->id;
                }
            }

            $row->forceFill(['granted_at' => $now, 'subscription_id' => $subscriptionId, 'skipped' => $skipped])->save();

            $this->events->record(
                ProductEventName::ReferralMilestoneReached,
                ['milestone' => $row->milestone, 'days' => $row->days, 'referral_id' => $row->referral_id, 'subscription_id' => $subscriptionId, 'skipped' => $skipped],
                learner: $learner === null || $learner->trashed() ? null : $learner,
            );

            $event = new ReferralMilestoneReached($row->referrer_learner_id, $row->milestone, $row->days, $subscriptionId, $skipped);
            DB::afterCommit(static function () use ($event): void {
                event($event);
            });
        });
    }

    /**
     * Recovery sweep (scheduler): a lost or failed job — the queue was down at the dispatch, the job exhausted
     * its tries, an exception after the commit — leaves a referral that is `activated` and not counted, or a
     * milestone reached and not granted. Re-queues {@see RewardReferralJob} / {@see GrantReferralMilestoneJob} for
     * those that have waited `$olderThanMinutes` (a fresh one is still in flight). Flagged referrals wait for an
     * admin. Safe to repeat: both jobs are idempotent by the data. Only rows activated / reached within
     * `referrals.recover_window_days` are looked at, so the sweep stays an index range scan; a row stuck for longer
     * is left for a manual look.
     *
     * @return int jobs queued
     */
    public function recoverStuck(CarbonImmutable $now, int $olderThanMinutes = 10, int $limit = 500): int
    {
        $before = $now->subMinutes(max(1, $olderThanMinutes));
        $since = $now->subDays(max(1, (int) config('referrals.recover_window_days', 14)));
        $limit = max(1, $limit);

        /** @var list<int> $referralIds */
        $referralIds = Referral::query()
            ->whereNull('flagged_at')
            ->where('status', ReferralStatus::Activated->value)
            ->where('activated_at', '<', $before)
            ->where('activated_at', '>=', $since)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
        foreach ($referralIds as $id) {
            RewardReferralJob::dispatch($id);
        }

        /** @var list<int> $milestoneIds */
        $milestoneIds = ReferralMilestone::query()
            ->whereNull('granted_at')
            ->where('reached_at', '<', $before)
            ->where('reached_at', '>=', $since)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
        foreach ($milestoneIds as $id) {
            GrantReferralMilestoneJob::dispatch($id);
        }

        return count($referralIds) + count($milestoneIds);
    }

    /**
     * Admin: clears `flagged_at` (audited `referral.unflagged`); an activated referral is counted right away.
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
     * @return bool false when it is already counted or rejected
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

    /**
     * The configured milestones, ascending and distinct; empty = no rewards at all.
     *
     * @return list<int>
     */
    public function milestones(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config('referrals.milestones', []);
        $milestones = array_values(array_unique(array_filter(array_map('intval', $configured), static fn (int $n): bool => $n > 0)));
        sort($milestones);

        return $milestones;
    }

    public function milestoneDays(): int
    {
        return max(1, (int) config('referrals.milestone_days', 7));
    }

    /** How many friends are COUNTED for the inviter (`rewarded`: the status that overview() reports as `counted`) — one query. */
    public function countedFor(Learner $learner): int
    {
        return Referral::query()
            ->where('referrer_learner_id', $learner->id)
            ->where('status', ReferralStatus::Rewarded->value)
            ->count();
    }

    /**
     * What the TWA screen and `/invite` show: the code, the link, the rule, the counts, the milestones with their
     * state, the next one, and the invited learners (names only).
     */
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

        $counted = $byStatus[ReferralStatus::Rewarded->value] ?? 0;
        $activated = ($byStatus[ReferralStatus::Activated->value] ?? 0) + $counted;
        $invited = $activated + ($byStatus[ReferralStatus::Pending->value] ?? 0);

        /** @var Collection<int, ReferralMilestone> $reached */
        $reached = ReferralMilestone::query()->where('referrer_learner_id', $learner->id)->get()->keyBy('milestone');
        $days = $this->milestoneDays();

        $milestones = [];
        $next = null;
        foreach ($this->milestones() as $m) {
            /** @var ReferralMilestone|null $row */
            $row = $reached->get($m);
            $milestones[] = [
                'friends' => $m,
                'days' => $row === null ? $days : $row->days,
                'reached_at' => $row === null ? null : CarbonImmutable::instance($row->reached_at),
                'rewarded' => $row !== null && $row->isSettled(),
            ];
            if ($row === null && $next === null) {
                $next = ['friends' => $m, 'remaining' => $m > $counted ? $m - $counted : 0];
            }
        }

        return new ReferralOverview(
            code: $code,
            link: $this->inviteLink($code),
            activationDays: max(1, (int) config('referrals.activation.days')),
            activationReviewsPerDay: max(1, (int) config('referrals.activation.reviews_per_day')),
            activationWindowDays: max(1, (int) config('referrals.activation.window_days')),
            milestoneDays: $days,
            invited: $invited,
            activated: $activated,
            counted: $counted,
            milestones: $milestones,
            next: $next,
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
     * Inside a transaction. Locks the inviter's referral rows (by id, FOR UPDATE): the transition, the count and
     * the milestone rows are atomic per inviter, and two parallel rewards of one referral — or of two friends of
     * one inviter — serialize here. The count is the `rewarded` rows after this one (rows of before 2C.9 count
     * too: a friend who started is a friend who started, and the milestone is a new reward, not a repeat of the
     * old one). A milestone row is inserted with `insertOrIgnore`, so the unique key — not the lock — is the last
     * word even on a driver without row locks.
     *
     * @return array{outcome: string, referrer_id: int|null}
     */
    private function claim(int $referralId, CarbonImmutable $now): array
    {
        $referrerId = Referral::query()->whereKey($referralId)->value('referrer_learner_id');
        if (! is_numeric($referrerId)) {
            return ['outcome' => 'missing', 'referrer_id' => null];
        }
        $referrerId = (int) $referrerId;

        /** @var Collection<int, Referral> $rows */
        $rows = Referral::query()
            ->where('referrer_learner_id', $referrerId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var Referral|null $row */
        $row = $rows->firstWhere('id', $referralId);
        if ($row === null) {
            return ['outcome' => 'missing', 'referrer_id' => null];
        }
        if ($row->status === ReferralStatus::Rewarded->value) {
            return ['outcome' => self::CLAIM_ALREADY, 'referrer_id' => $referrerId];
        }
        if ($row->status !== ReferralStatus::Activated->value) {
            return ['outcome' => 'not_activated', 'referrer_id' => $referrerId];
        }
        if ($row->flagged_at !== null) {
            return ['outcome' => 'flagged', 'referrer_id' => $referrerId];
        }

        $counted = $rows->filter(static fn (Referral $r): bool => $r->status === ReferralStatus::Rewarded->value)->count() + 1;

        $row->forceFill([
            'status' => ReferralStatus::Rewarded->value,
            'rewarded_at' => $now,
            'meta' => [...$row->meta, 'counted' => $counted],
        ])->save();

        $days = $this->milestoneDays();
        foreach ($this->milestones() as $milestone) {
            if ($counted < $milestone) {
                break;
            }
            ReferralMilestone::query()->insertOrIgnore([
                'referrer_learner_id' => $referrerId,
                'milestone' => $milestone,
                'days' => $days,
                'referral_id' => $row->id,
                'reached_at' => $now,
                'granted_at' => null,
                'subscription_id' => null,
                'skipped' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->events->record(
            ProductEventName::ReferralCounted,
            ['referral_id' => $row->id, 'referrer_learner_id' => $referrerId, 'counted' => $counted],
            learner: $row->referee,
        );

        return ['outcome' => self::CLAIM_CLAIMED, 'referrer_id' => $referrerId];
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

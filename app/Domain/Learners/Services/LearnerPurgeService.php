<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\AutoRenewal;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\DTO\LearnerPurgeReport;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Models\AuditLog;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Final deletion of soft-deleted learners (docs/03 §8 rule 2, docs/07 §10; owner's decision of 03.11.2026): a learner
 * whose `deleted_at` is older than `config('learners.purge.after_days')` (30) days loses the `learners` row, and with it,
 * by the FK cascades, every personal and learning record (progress, sessions, memberships, friends, challenges,
 * battles, leagues, achievements, referrals, offers, promo redemptions, the notification log); group exam answers and
 * results, product events and the referrer of the people they invited are anonymised (`ON DELETE SET NULL`).
 *
 * Payment records are never touched: `payment_orders`, `payments`, `provider_transactions` and `subscriptions` have
 * no FK on `learners` and keep the payer snapshot (`payer_*`, PayerSnapshot) — who paid and when stays readable in
 * the admin and usable for a Stars refund. Before the row goes, the live subscriptions are ended (`learner_purged`)
 * and the Stars renewal is canceled at Telegram, best-effort.
 *
 * Idempotent and safe to run twice or in parallel: the run holds `Cache::lock(LOCK_KEY)`, every learner is deleted in
 * a transaction of its own after a `SELECT … FOR UPDATE` that re-checks the due date — a learner who came back
 * (`LearnerRegistrar` restores by their own `/start` / `/auth`) between the pick and the lock is left alone.
 */
final class LearnerPurgeService
{
    public const LOCK_KEY = 'learners:purge';

    /** `audit_logs.action` of a deleted learner: `entity_id` is the old id, `meta.deleted_at` when the account was deleted. */
    public const ACTION_PURGED = 'learner.purged';

    /** `endNow` reason of the learner's live subscriptions (product event `subscription.expired {reason}`). */
    public const REASON_PURGED = 'learner_purged';

    private const LOCK_SECONDS = 1800;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly BillingProviderRegistry $providers,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /** Learners with `deleted_at` on or before this moment are due. */
    public function cutoff(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subDays(max(1, (int) config('learners.purge.after_days', 30)));
    }

    /**
     * Deletes every due learner, oldest `deleted_at` first, at most `$limit` (`learners.purge.max_per_run`) per run in
     * batches of `learners.purge.batch`. Under the purge lock: a second run meanwhile does nothing (`locked`).
     */
    public function purgeDue(?CarbonImmutable $now = null, ?int $limit = null): LearnerPurgeReport
    {
        $now ??= CarbonImmutable::now();
        $limit = max(1, $limit ?? (int) config('learners.purge.max_per_run', 500));
        $batch = max(1, min((int) config('learners.purge.batch', 50), $limit));
        $report = new LearnerPurgeReport;
        $started = hrtime(true);

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            $report->locked = true;
            Log::info('learners.purge_skipped_locked');

            return $report;
        }

        try {
            $cutoff = $this->cutoff($now);
            $handled = 0;

            while ($handled < $limit) {
                /** @var list<int> $ids */
                $ids = Learner::onlyTrashed()
                    ->where('deleted_at', '<=', $cutoff)
                    ->orderBy('deleted_at')
                    ->orderBy('id')
                    ->limit(min($batch, $limit - $handled))
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                foreach ($ids as $id) {
                    if ($this->purge($id, $now)) {
                        $report->purged++;
                    } else {
                        $report->skipped++;
                    }
                    $handled++;
                }

                if ($handled >= $limit) {
                    $report->capped = Learner::onlyTrashed()->where('deleted_at', '<=', $cutoff)->exists();
                }
            }
        } finally {
            $lock->release();
        }

        Log::info('learners.purge_finished', [...$report->toArray(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);

        return $report;
    }

    /**
     * Deletes one learner for good when they are still due. true = deleted by this call; false = nothing to do (not
     * soft-deleted, not due yet, restored meanwhile, or already gone).
     */
    public function purge(int $learnerId, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $this->cutoff($now);

        /** @var Learner|null $learner */
        $learner = Learner::onlyTrashed()->whereKey($learnerId)->where('deleted_at', '<=', $cutoff)->first();
        if ($learner === null) {
            return false;
        }

        // The provider call goes first and outside any transaction (docs/13 §3 rule 2): Telegram would otherwise keep
        // charging a Stars subscription whose learner no longer exists. A failure is logged and does not stop the purge —
        // a later renewal lands as a `failed / subscriber_missing` ledger row with the payer snapshot of its order.
        $this->stopRenewals($learner);

        return DB::transaction(function () use ($learner, $cutoff, $now): bool {
            /** @var Learner|null $locked */
            $locked = Learner::onlyTrashed()->whereKey($learner->id)->where('deleted_at', '<=', $cutoff)->lockForUpdate()->first();
            if ($locked === null) {
                // Restored by their own /start (or purged by a parallel run) between the pick and the lock.
                return false;
            }

            /** @var list<int> $organizationIds */
            $organizationIds = OrganizationMember::query()->where('learner_id', $locked->id)->distinct()->pluck('organization_id')->all();
            /** @var list<int> $leagueGroupIds */
            $leagueGroupIds = LeagueMember::query()->where('learner_id', $locked->id)->distinct()->pluck('league_group_id')->all();

            $ended = $this->endSubscriptions($locked, $now);
            $payments = Payment::query()->where('subscriber_type', 'learner')->where('subscriber_id', $locked->id)->count();

            AuditLog::create([
                'user_id' => null,
                'action' => self::ACTION_PURGED,
                'entity_type' => Learner::class,
                'entity_id' => $locked->id,
                'ip' => null,
                // Ids and counts only: this row outlives the learner by two years (docs/07 §13).
                'meta' => [
                    'deleted_at' => $locked->deleted_at?->toIso8601String(),
                    'subscriptions_ended' => $ended,
                    'payments_kept' => $payments,
                ],
            ]);

            // `forceDelete` on the builder: a plain `delete()` would soft-delete again. The FK cascades do the rest.
            $deleted = Learner::withTrashed()->whereKey($locked->id)->whereNotNull('deleted_at')->where('deleted_at', '<=', $cutoff)->forceDelete();
            if ($deleted !== 1) {
                throw new RuntimeException("Learner {$locked->id} was not deleted although its row was locked.");
            }

            $learnerId = $locked->id;
            DB::afterCommit(function () use ($learnerId, $organizationIds, $leagueGroupIds): void {
                $this->forgetCaches($learnerId, $organizationIds, $leagueGroupIds);
            });

            Log::info('learner.purged', ['learner_id' => $learnerId, 'deleted_at' => $locked->deleted_at?->toIso8601String(), 'subscriptions_ended' => $ended, 'payments_kept' => $payments]);

            return true;
        });
    }

    /** Telegram is asked to stop every running Stars renewal of the learner; best-effort. */
    private function stopRenewals(Learner $learner): void
    {
        $now = CarbonImmutable::now();

        foreach ($this->liveSubscriptions($learner)->get() as $subscription) {
            $provider = SubscriptionProvider::tryFrom($subscription->provider);

            if ($provider === null || ! AutoRenewal::isRunning($subscription, $now) || ! $this->providers->has($provider)) {
                continue;
            }

            try {
                $this->providers->for($provider)->cancelAutoRenew($subscription);
            } catch (Throwable $e) {
                report($e);
                Log::warning('learners.purge.stars_cancel_failed', ['learner_id' => $learner->id, 'subscription_id' => $subscription->id, 'error' => $e::class]);
            }
        }
    }

    /** @return int how many live subscriptions were ended now */
    private function endSubscriptions(Learner $learner, CarbonImmutable $now): int
    {
        $ended = 0;

        foreach ($this->liveSubscriptions($learner)->get() as $subscription) {
            try {
                $this->subscriptions->endNow($subscription, self::REASON_PURGED, null, $now);
                $ended++;
            } catch (SubscriptionException) {
                // Not live any more (`subscriptions:expire` got there first): nothing to end.
            }
        }

        return $ended;
    }

    /** @return Builder<Subscription> */
    private function liveSubscriptions(Learner $learner): Builder
    {
        return Subscription::query()
            ->where('subscriber_type', 'learner')
            ->where('subscriber_id', $learner->id)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->orderBy('id');
    }

    /**
     * docs/19 §3 rule 5: the entitlement of the old id (a token still in flight), the seat summaries of the learner's
     * organizations, and the standings of the league groups the learner was in.
     *
     * @param  list<int>  $organizationIds
     * @param  list<int>  $leagueGroupIds
     */
    private function forgetCaches(int $learnerId, array $organizationIds, array $leagueGroupIds): void
    {
        $this->entitlements->forget($learnerId);

        foreach ($organizationIds as $organizationId) {
            OrgOverviewService::forget($organizationId);
        }

        // The standings of the groups and the facts of this week's placement (docs/19 K9) - a token still in flight reads nothing of them.
        PureCache::forgetMany([
            ...array_map(static fn (int $groupId): string => LeagueService::CACHE_PREFIX.$groupId, $leagueGroupIds),
            LeagueService::memberKey($learnerId, CarbonImmutable::now()),
        ]);
    }
}

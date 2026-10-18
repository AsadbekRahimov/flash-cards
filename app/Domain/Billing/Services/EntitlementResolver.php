<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Effective plan of a learner (docs/12 §5.1):
 *
 *   max by rank of { Free,
 *                    the learner's own live subscription,
 *                    a live subscription of any active organization owning an
 *                    active group where the learner is an active member }.
 *
 * Ties are broken by the later end (perpetual wins), then personal over sponsor,
 * so "Pro until X" shown to the learner is the latest date that is actually true.
 *
 * Cached per learner ("entitlement:learner:{id}", config('billing.cache_ttl')).
 * A cached snapshot whose own end has passed is recomputed on read, so a plan
 * never outlives its subscription even if the scheduler is late. Every write
 * that changes the inputs calls forget*(): SubscriptionService, PromoCodeService,
 * GroupMembershipService::join/leave, TelegramGroupObserver (organization_id /
 * status), OrganizationObserver (status) — docs/12 §5.2.
 */
final class EntitlementResolver
{
    public const CACHE_KEY_PREFIX = 'entitlement:learner:';

    private const FORGET_CHUNK = 500;

    public function for(Learner $learner, ?CarbonImmutable $now = null): Entitlement
    {
        $now ??= CarbonImmutable::now();
        $key = self::cacheKey($learner->id);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            $entitlement = Entitlement::fromArray($cached);

            if ($entitlement->isCurrentAt($now)) {
                return $entitlement;
            }
        }

        $entitlement = $this->resolve($learner, $now);
        Cache::put($key, $entitlement->toArray(), $this->ttl());

        return $entitlement;
    }

    /** Uncached computation. */
    public function resolve(Learner $learner, ?CarbonImmutable $now = null): Entitlement
    {
        $now ??= CarbonImmutable::now();
        $best = Entitlement::free();

        foreach ($this->liveCandidates($learner, $now) as $candidate) {
            if ($this->beats($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public function forget(Learner|int $learner): void
    {
        Cache::forget(self::cacheKey($learner instanceof Learner ? $learner->id : $learner));
    }

    /** @param iterable<int> $learnerIds */
    public function forgetMany(iterable $learnerIds): void
    {
        $keys = [];
        foreach ($learnerIds as $id) {
            $keys[] = self::cacheKey($id);
        }

        foreach (array_chunk(array_values(array_unique($keys)), self::FORGET_CHUNK) as $chunk) {
            Cache::deleteMultiple($chunk);
        }
    }

    /** Drops the cache of every active member of every group of the organization. */
    public function forgetOrganization(Organization|int $organization): void
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        $this->forgetLearnerIds(
            DB::table('group_members as gm')
                ->join('telegram_groups as g', 'g.id', '=', 'gm.telegram_group_id')
                ->where('g.organization_id', $organizationId)
                ->where('gm.status', 'active'),
        );
    }

    /** Drops the cache of every active member of the group (its organization or status changed). */
    public function forgetGroup(TelegramGroup|int $group): void
    {
        $groupId = $group instanceof TelegramGroup ? $group->id : $group;

        $this->forgetLearnerIds(
            DB::table('group_members as gm')
                ->where('gm.telegram_group_id', $groupId)
                ->where('gm.status', 'active'),
        );
    }

    /**
     * Paywall settings for API responses (docs/12 §5.4): the support contact is
     * null when BILLING_SUPPORT_CONTACT is empty so clients can hide the sentence.
     *
     * @return array{mode:string, contact:string|null}
     */
    public function paywall(): array
    {
        $contact = trim((string) config('billing.paywall.contact', ''));

        return [
            'mode' => (string) config('billing.paywall.mode', 'coming_soon'),
            'contact' => $contact === '' ? null : $contact,
        ];
    }

    public static function cacheKey(int $learnerId): string
    {
        return self::CACHE_KEY_PREFIX.$learnerId;
    }

    /** @return list<Entitlement> */
    private function liveCandidates(Learner $learner, CarbonImmutable $now): array
    {
        $sponsorIds = DB::table('telegram_groups as g')
            ->join('group_members as gm', 'gm.telegram_group_id', '=', 'g.id')
            ->join('organizations as o', 'o.id', '=', 'g.organization_id')
            ->where('gm.learner_id', $learner->id)
            ->where('gm.status', 'active')
            ->where('g.status', 'active')
            ->where('o.status', 'active')
            ->select('g.organization_id');

        $subscriptions = Subscription::query()
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->where(function ($q) use ($learner, $sponsorIds): void {
                $q->where(fn ($own) => $own->where('subscriber_type', 'learner')->where('subscriber_id', $learner->id))
                    ->orWhere(fn ($sponsor) => $sponsor->where('subscriber_type', 'organization')->whereIn('subscriber_id', $sponsorIds));
            })
            ->orderBy('id')
            ->get();

        $candidates = [];

        foreach ($subscriptions as $subscription) {
            if (! SubscriptionLiveness::isLive($subscription, $now)) {
                continue;
            }

            $plan = Plan::tryFrom($subscription->plan);
            if ($plan === null) {
                continue; // a plan key removed from config no longer grants anything
            }

            $source = $subscription->subscriber_type === 'organization'
                ? Entitlement::sponsorSource($subscription->subscriber_id)
                : Entitlement::SOURCE_SUBSCRIPTION;

            $candidates[] = Entitlement::fromPlan($plan, $source, SubscriptionLiveness::liveUntil($subscription), $subscription->id);
        }

        return $candidates;
    }

    private function beats(Entitlement $candidate, Entitlement $current): bool
    {
        if ($candidate->plan->rank() !== $current->plan->rank()) {
            return $candidate->plan->rank() > $current->plan->rank();
        }

        // Same rank: the later end wins (null = perpetual beats any date) ...
        if ($candidate->until === null xor $current->until === null) {
            return $candidate->until === null;
        }

        if ($candidate->until !== null && $current->until !== null && ! $candidate->until->equalTo($current->until)) {
            return $candidate->until->greaterThan($current->until);
        }

        // ... and on an exact tie a personal subscription over a sponsor's.
        return $candidate->source === Entitlement::SOURCE_SUBSCRIPTION && $current->source !== Entitlement::SOURCE_SUBSCRIPTION;
    }

    private function forgetLearnerIds(Builder $members): void
    {
        $members
            ->select('gm.learner_id')
            ->distinct()
            ->orderBy('gm.learner_id')
            ->chunk(self::FORGET_CHUNK, function ($rows): void {
                $keys = [];
                foreach ($rows as $row) {
                    $keys[] = self::cacheKey((int) $row->learner_id);
                }

                if ($keys !== []) {
                    Cache::deleteMultiple($keys);
                }
            });
    }

    private function ttl(): int
    {
        return max(1, (int) config('billing.cache_ttl', 300));
    }
}

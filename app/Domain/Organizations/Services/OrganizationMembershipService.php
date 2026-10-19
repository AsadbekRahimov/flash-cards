<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Organizations\DTO\SeatsSummary;
use App\Domain\Organizations\Enums\JoinedVia;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Organizations\Events\OrganizationMemberSeated;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Models\AuditLog;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Membership of a learner in an organization and the seats of the organization
 * (docs/13 §1.3, §2.3, §6.1–6.2).
 *
 * A seat is a row `status = active AND seat_state = seated`. A join takes a seat when the
 * organization sponsors (live subscription) and `seated < seats_limit` (NULL limit on a live
 * subscription = unlimited); otherwise the member is `waitlisted` (plain Free). A freed seat
 * goes to the earliest waitlisted member in the same transaction (FIFO by `waitlisted_at`);
 * a tariff upgrade seats the waitlist through {@see self::reseat()} after the subscription
 * committed. A downgrade unseats nobody (the cabinet shows the excess); new joins wait.
 *
 * LOCK ORDER (docs/13 §3): every write here is one short transaction that takes
 * `organizations FOR UPDATE` first and then writes `organization_members` (and bumps
 * `organization_invites.uses_count`); the organization's subscription is only READ. The
 * subscription paths (`SubscriptionService::grantManual` / `applyCharge`) lock
 * `organizations -> subscriptions` and call `reseat()` after their commit, so nobody holding
 * `organization_members` waits for `subscriptions` or the other way round. Learner rows are
 * never locked here; the FK inserts (`organization_members`, `product_events`) only take
 * KEY SHARE on the learner, which a `grantManual(learner)` holding the learner FOR UPDATE never
 * waits on in reverse (it does not touch organizations).
 *
 * Every real change drops the cached entitlement of the learners involved after commit and
 * raises the domain events (bot messages, the B2B -> B2C offer of 2B.7 hang on them).
 */
final class OrganizationMembershipService
{
    public const AUDIT_MEMBER_ADDED = 'organization.member_added';

    public const AUDIT_MEMBER_REMOVED = 'organization.member_removed';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Redeems an invite code: upsert of an active membership, seat if one is free, `uses_count`
     * of the invite. Idempotent for an already active member (no second use is counted); a
     * member who joined through a group and now redeems a code becomes an invite member
     * (the membership then survives leaving the groups).
     *
     * @throws OrganizationMembershipException invite_invalid | invite_expired | invite_exhausted | organization_suspended
     */
    public function joinByInvite(Learner $learner, string $code, ?CarbonImmutable $now = null): OrganizationMember
    {
        $now ??= CarbonImmutable::now();
        $code = OrganizationInviteService::normalizeCode($code);

        // Unlocked pre-check: tells a wrong / dead code apart without touching the organization row.
        $invite = $this->usableInvite(OrganizationInvite::query()->where('code', $code)->first(), $code, $now);

        return DB::transaction(function () use ($learner, $invite, $code, $now): OrganizationMember {
            $organization = $this->lockOrganization($invite->organization_id) ?? throw OrganizationMembershipException::inviteInvalid();

            if (! $organization->isActive()) {
                throw OrganizationMembershipException::organizationSuspended($organization->id);
            }

            // Re-read under the organization lock: joins of one organization are serialized here,
            // so `uses_count` against `max_uses` cannot be checked against a stale count.
            $invite = $this->usableInvite(OrganizationInvite::query()->whereKey($invite->id)->first(), $code, $now);

            ['member' => $member, 'changed' => $changed] = $this->activate($organization, $learner, JoinedVia::Invite, $invite, $now);

            if ($changed) {
                $invite->increment('uses_count');
                $this->warnOnBurst($invite, $now);
            }

            return $member;
        });
    }

    /**
     * Membership through a Telegram group of the organization (joined_via = group); called by
     * GroupMembershipService::join after its own write, in a separate transaction. Null when the
     * group has no organization or the organization is suspended (best effort, never throws for
     * a business reason; `organizations:sync-members` repairs gaps).
     */
    public function joinFromGroup(Learner $learner, TelegramGroup $group, ?CarbonImmutable $now = null): ?OrganizationMember
    {
        $now ??= CarbonImmutable::now();
        $organizationId = $group->organization_id;

        if ($organizationId === null) {
            return null;
        }

        return DB::transaction(function () use ($learner, $organizationId, $now): ?OrganizationMember {
            $organization = $this->lockOrganization($organizationId);

            if ($organization === null || ! $organization->isActive()) {
                return null;
            }

            return $this->activate($organization, $learner, JoinedVia::Group, null, $now)['member'];
        });
    }

    /**
     * Added by staff / a superadmin (joined_via = admin); audited.
     *
     * @throws OrganizationMembershipException organization_suspended
     */
    public function joinByAdmin(Learner $learner, Organization $organization, User $by, ?string $ip = null, ?CarbonImmutable $now = null): OrganizationMember
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($learner, $organization, $by, $ip, $now): OrganizationMember {
            $locked = $this->lockOrganization($organization->id) ?? throw OrganizationMembershipException::organizationSuspended($organization->id);

            if (! $locked->isActive()) {
                throw OrganizationMembershipException::organizationSuspended($locked->id);
            }

            ['member' => $member, 'changed' => $changed] = $this->activate($locked, $learner, JoinedVia::Admin, null, $now);

            if ($changed) {
                AuditLog::create([
                    'user_id' => $by->id,
                    'action' => self::AUDIT_MEMBER_ADDED,
                    'entity_type' => OrganizationMember::class,
                    'entity_id' => $member->id,
                    'ip' => $ip,
                    'meta' => ['organization_id' => $locked->id, 'learner_id' => $learner->id, 'seat_state' => $member->seat_state],
                ]);
            }

            return $member;
        });
    }

    /**
     * Ends an active membership: `status` -> left | removed, `seat_state` -> none; a freed seat
     * goes to the earliest waitlisted member in the same transaction. After commit: the cached
     * entitlement of the leaver and of the promoted member is dropped, `OrganizationMemberLeft`
     * (with `wasSeated`, the hook of the post-organization offer, 2B.7) and
     * `OrganizationMemberSeated` are raised.
     *
     * @param  string  $status  `left` (the learner) or `removed` (staff / superadmin, audited when $by is given)
     *
     * @throws OrganizationMembershipException not_a_member
     */
    public function leave(Learner $learner, Organization $organization, string $status = 'left', ?User $by = null, ?string $ip = null, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        if (! in_array($status, [MemberStatus::Left->value, MemberStatus::Removed->value], true)) {
            throw new InvalidArgumentException("Membership end status must be left or removed, [{$status}] given.");
        }

        DB::transaction(function () use ($learner, $organization, $status, $by, $ip, $now): void {
            $locked = $this->lockOrganization($organization->id) ?? throw OrganizationMembershipException::notAMember($learner->id, $organization->id);

            /** @var OrganizationMember|null $member */
            $member = $this->activeRow($locked->id, $learner->id)->first();

            if ($member === null) {
                throw OrganizationMembershipException::notAMember($learner->id, $locked->id);
            }

            $wasSeated = $member->isSeated();

            $member->update([
                'status' => $status,
                'seat_state' => SeatState::None->value,
                'seated_at' => null,
                'waitlisted_at' => null,
                'left_at' => $now,
            ]);

            $promoted = $wasSeated ? $this->seatWaitlistLocked($locked, $now) : [];

            if ($by !== null) {
                AuditLog::create([
                    'user_id' => $by->id,
                    'action' => self::AUDIT_MEMBER_REMOVED,
                    'entity_type' => OrganizationMember::class,
                    'entity_id' => $member->id,
                    'ip' => $ip,
                    'meta' => ['organization_id' => $locked->id, 'learner_id' => $learner->id, 'status' => $status, 'was_seated' => $wasSeated],
                ]);
            }

            $this->events->record(
                ProductEventName::OrgMemberLeft,
                ['organization_id' => $locked->id, 'status' => $status, 'was_seated' => $wasSeated, 'joined_via' => $member->joined_via],
                learner: $learner,
                user: $by,
            );

            $event = new OrganizationMemberLeft($locked->id, $learner->id, $member->id, $status, $wasSeated, $by?->id);

            DB::afterCommit(function () use ($event): void {
                $this->entitlements->forget($event->learnerId);
                event($event);
            });
            $this->announceSeated($promoted);
        });
    }

    /**
     * GroupMembershipService::leave hook: a member who came through a group and has no active
     * membership left in any group of the organization leaves it (status = left). Invite /
     * admin memberships are untouched.
     */
    public function onGroupLeft(Learner $learner, TelegramGroup $group, ?CarbonImmutable $now = null): void
    {
        if ($group->organization_id === null) {
            return;
        }

        $this->leaveGroupOrphans($group->organization_id, [$learner->id], $now);
    }

    /**
     * For a group just unlinked from / deleted in an organization: every listed learner whose
     * membership is `joined_via = group` and who is in no other group of the organization leaves it.
     *
     * @param  list<int>  $learnerIds
     * @return int memberships ended
     */
    public function leaveGroupOrphans(int $organizationId, array $learnerIds, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $ended = 0;

        foreach ($learnerIds as $learnerId) {
            $member = OrganizationMember::query()
                ->where('organization_id', $organizationId)
                ->where('learner_id', $learnerId)
                ->where('status', MemberStatus::Active->value)
                ->where('joined_via', JoinedVia::Group->value)
                ->first();

            if ($member === null || $this->hasGroupOf($organizationId, $learnerId)) {
                continue;
            }

            /** @var Learner|null $learner */
            $learner = Learner::withTrashed()->find($learnerId);
            /** @var Organization|null $organization */
            $organization = Organization::query()->find($organizationId);

            if ($learner === null || $organization === null) {
                continue;
            }

            try {
                $this->leave($learner, $organization, MemberStatus::Left->value, null, null, $now);
                $ended++;
            } catch (OrganizationMembershipException) {
                // Already ended by a parallel path: nothing to do.
            }
        }

        return $ended;
    }

    /**
     * Every `joined_via = group` member of the organization who is in none of its groups any more
     * leaves it (a group of the organization was deleted — its `group_members` are gone with it).
     *
     * @return int memberships ended
     */
    public function releaseOrphans(int $organizationId, ?CarbonImmutable $now = null): int
    {
        /** @var list<int> $learnerIds */
        $learnerIds = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('status', MemberStatus::Active->value)
            ->where('joined_via', JoinedVia::Group->value)
            ->orderBy('id')
            ->pluck('learner_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $this->leaveGroupOrphans($organizationId, $learnerIds, $now);
    }

    /**
     * Creates `joined_via = group` memberships for the active members of a group of an
     * organization who have none (demo seed, repair after a failed hook, a group linked to an
     * organization with members already in it). Soft-deleted learners are skipped.
     *
     * @return int memberships created or re-activated
     */
    public function syncGroup(TelegramGroup $group, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $organizationId = $group->organization_id;

        if ($organizationId === null) {
            return 0;
        }

        $synced = 0;

        Learner::query()
            ->whereIn('learners.id', GroupMember::query()
                ->where('telegram_group_id', $group->id)
                ->where('status', 'active')
                ->select('learner_id'))
            ->whereNotIn('learners.id', OrganizationMember::query()
                ->where('organization_id', $organizationId)
                ->where('status', MemberStatus::Active->value)
                ->select('learner_id'))
            ->orderBy('learners.id')
            ->chunkById(200, function ($learners) use ($group, $now, &$synced): void {
                /** @var Learner $learner */
                foreach ($learners as $learner) {
                    if ($this->joinFromGroup($learner, $group, $now) !== null) {
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    /**
     * Seats the waitlist FIFO up to the limit (after a tariff upgrade or a new subscription);
     * called by SubscriptionService after its commit, in its own transaction. Idempotent.
     *
     * @return int members seated
     */
    public function reseat(Organization|int $organization, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return DB::transaction(function () use ($organizationId, $now): int {
            $locked = $this->lockOrganization($organizationId);

            if ($locked === null || ! $locked->isActive()) {
                return 0;
            }

            $promoted = $this->seatWaitlistLocked($locked, $now);
            $this->announceSeated($promoted);

            return count($promoted);
        });
    }

    /** Unlocked snapshot for the cabinet / admin widgets. */
    public function seatsSummary(Organization $organization, ?CarbonImmutable $now = null): SeatsSummary
    {
        $now ??= CarbonImmutable::now();
        $subscription = $this->liveSubscription($organization->id, $now);

        return new SeatsSummary(
            limit: $subscription?->seats_limit,
            seated: $this->countActive($organization->id, SeatState::Seated),
            waitlisted: $this->countActive($organization->id, SeatState::Waitlisted),
            sponsoring: $subscription !== null,
        );
    }

    /** The learner's active membership in the organization, if any. */
    public function activeMembership(Learner $learner, Organization $organization): ?OrganizationMember
    {
        /** @var OrganizationMember|null $member */
        $member = $this->activeRow($organization->id, $learner->id)->first();

        return $member;
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Upsert of an active membership under the organization lock.
     *
     * @return array{member: OrganizationMember, changed: bool}
     */
    private function activate(Organization $organization, Learner $learner, JoinedVia $via, ?OrganizationInvite $invite, CarbonImmutable $now): array
    {
        /** @var OrganizationMember|null $member */
        $member = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('learner_id', $learner->id)
            ->first();

        if ($member !== null && $member->isActive()) {
            // A group member redeeming a code keeps the membership after leaving the groups.
            if ($via === JoinedVia::Invite && $member->joined_via === JoinedVia::Group->value) {
                $member->update(['joined_via' => $via->value, 'invite_id' => $invite?->id]);

                return ['member' => $member, 'changed' => true];
            }

            return ['member' => $member, 'changed' => false];
        }

        $seat = $this->summaryLocked($organization, $now)->free() > 0 ? SeatState::Seated : SeatState::Waitlisted;
        $attributes = [
            'status' => MemberStatus::Active->value,
            'seat_state' => $seat->value,
            'joined_via' => $via->value,
            'invite_id' => $invite?->id,
            'seated_at' => $seat === SeatState::Seated ? $now : null,
            'waitlisted_at' => $seat === SeatState::Waitlisted ? $now : null,
            'joined_at' => $now,
            'left_at' => null,
        ];

        if ($member === null) {
            $member = OrganizationMember::query()->create([
                'organization_id' => $organization->id,
                'learner_id' => $learner->id,
                'meta' => [],
                ...$attributes,
            ]);
        } else {
            $member->update($attributes);
        }

        $this->events->record(
            ProductEventName::OrgMemberJoined,
            ['organization_id' => $organization->id, 'via' => $via->value, 'seat_state' => $seat->value, 'invite_id' => $invite?->id],
            learner: $learner,
        );

        $event = new OrganizationMemberJoined($organization->id, $learner->id, $member->id, $via->value, $seat->value);

        DB::afterCommit(function () use ($event): void {
            $this->entitlements->forget($event->learnerId);
            event($event);
        });

        return ['member' => $member, 'changed' => true];
    }

    /**
     * Earliest waitlisted members get the free seats (organization locked by the caller).
     *
     * @return list<OrganizationMember>
     */
    private function seatWaitlistLocked(Organization $organization, CarbonImmutable $now): array
    {
        $free = $this->summaryLocked($organization, $now)->free();

        if ($free <= 0) {
            return [];
        }

        $promoted = [];

        $waiting = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('status', MemberStatus::Active->value)
            ->where('seat_state', SeatState::Waitlisted->value)
            ->orderBy('waitlisted_at')
            ->orderBy('id')
            ->limit(min($free, 10_000))
            ->get();

        foreach ($waiting as $member) {
            $member->update(['seat_state' => SeatState::Seated->value, 'seated_at' => $now, 'waitlisted_at' => null]);
            $this->events->record(ProductEventName::OrgMemberSeated, ['organization_id' => $organization->id, 'member_id' => $member->id], learner: $member->learner);
            $promoted[] = $member;
        }

        return $promoted;
    }

    /** @param list<OrganizationMember> $promoted */
    private function announceSeated(array $promoted): void
    {
        if ($promoted === []) {
            return;
        }

        $events = array_map(
            static fn (OrganizationMember $m): OrganizationMemberSeated => new OrganizationMemberSeated($m->organization_id, $m->learner_id, $m->id),
            $promoted,
        );

        DB::afterCommit(function () use ($events): void {
            foreach ($events as $event) {
                $this->entitlements->forget($event->learnerId);
                event($event);
            }
        });
    }

    /** Same reads as {@see self::seatsSummary()}, but the caller holds the organization lock, so the counts are exact. */
    private function summaryLocked(Organization $organization, CarbonImmutable $now): SeatsSummary
    {
        return $this->seatsSummary($organization, $now);
    }

    /** `SELECT ... FOR UPDATE` on the organization row; null when it is gone. */
    private function lockOrganization(int $organizationId): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();

        return $organization;
    }

    /** The organization's live subscription (read without a lock — docs/13 §3 rule 3). */
    private function liveSubscription(int $organizationId, CarbonImmutable $now): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('subscriber_type', 'organization')
            ->where('subscriber_id', $organizationId)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->orderBy('id')
            ->first();

        if ($subscription === null || ! SubscriptionLiveness::isLive($subscription, $now)) {
            return null;
        }

        return $subscription;
    }

    private function countActive(int $organizationId, SeatState $state): int
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('status', MemberStatus::Active->value)
            ->where('seat_state', $state->value)
            ->count();
    }

    /** @return Builder<OrganizationMember> */
    private function activeRow(int $organizationId, int $learnerId): Builder
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('learner_id', $learnerId)
            ->where('status', MemberStatus::Active->value);
    }

    /** Whether the learner is still an active member of any group of the organization (group status aside). */
    private function hasGroupOf(int $organizationId, int $learnerId): bool
    {
        return GroupMember::query()
            ->where('learner_id', $learnerId)
            ->where('status', 'active')
            ->whereIn('telegram_group_id', TelegramGroup::query()->where('organization_id', $organizationId)->select('id'))
            ->exists();
    }

    /**
     * @throws OrganizationMembershipException invite_invalid | invite_expired | invite_exhausted
     */
    private function usableInvite(?OrganizationInvite $invite, string $code, CarbonImmutable $now): OrganizationInvite
    {
        if ($invite === null || ! $invite->is_active) {
            throw OrganizationMembershipException::inviteInvalid();
        }

        if ($invite->isExpiredAt($now)) {
            throw OrganizationMembershipException::inviteExpired($code);
        }

        if ($invite->isExhausted()) {
            throw OrganizationMembershipException::inviteExhausted($code);
        }

        return $invite;
    }

    /** docs/13 §6.1: more joins through one code in 24 h than `organizations.invite_burst_per_day` is logged, not blocked. */
    private function warnOnBurst(OrganizationInvite $invite, CarbonImmutable $now): void
    {
        $threshold = (int) config('organizations.invite_burst_per_day', 50);

        if ($threshold <= 0) {
            return;
        }

        $joins = OrganizationMember::query()
            ->where('invite_id', $invite->id)
            ->where('joined_at', '>', $now->subDay())
            ->count();

        if ($joins > $threshold) {
            Log::warning('organizations.join_burst', [
                'organization_id' => $invite->organization_id,
                'invite_id' => $invite->id,
                'joins_24h' => $joins,
                'threshold' => $threshold,
            ]);
        }
    }
}

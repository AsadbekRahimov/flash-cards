<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Organizations\DTO\SeatsSummary;
use App\Domain\Organizations\Enums\JoinedVia;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use App\Domain\Organizations\Events\OrganizationMemberInvited;
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
use App\Models\OrganizationMemberInvitation;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

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

    public const AUDIT_MEMBER_INVITED = 'organization.member_invited';

    public const AUDIT_INVITE_REVOKED = 'organization.member_invite_revoked';

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
     * Read-only check of a code for the bot's confirmation step ("Join center X?"): the same
     * refusals as {@see self::joinByInvite()} (without the locked re-read), nothing is written.
     * The invite comes back with its organization loaded.
     *
     * @throws OrganizationMembershipException invite_invalid | invite_expired | invite_exhausted | organization_suspended
     */
    public function previewInvite(string $code, ?CarbonImmutable $now = null): OrganizationInvite
    {
        $now ??= CarbonImmutable::now();
        $code = OrganizationInviteService::normalizeCode($code);

        $invite = $this->usableInvite(OrganizationInvite::query()->with('organization')->where('code', $code)->first(), $code, $now);

        $organization = $invite->organization;
        if (! $organization->isActive()) {
            throw OrganizationMembershipException::organizationSuspended($organization->id);
        }

        return $invite;
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
     * The learner a staff member named by `@username` / `username` (case-insensitive) or a numeric Telegram id — exact match
     * only, no browsing of the platform's learners. Null for anything else; the CALLER MUST NOT tell the staff whether it was
     * null (audit 2026-11-10 M2: the answer would say who uses LexiFlow).
     */
    public function findLearnerByTelegram(string $input): ?Learner
    {
        $handle = self::normalizeTelegramHandle($input);

        if ($handle === null) {
            return null;
        }

        if (ctype_digit($handle)) {
            /** @var Learner|null $learner */
            $learner = Learner::query()->where('telegram_user_id', (int) $handle)->first();

            return $learner;
        }

        /** @var Learner|null $learner */
        $learner = Learner::query()->whereRaw('LOWER(username) = ?', [substr($handle, 1)])->first();

        return $learner;
    }

    /**
     * What the staff typed, as the cabinet stores and shows it: `@username` in lower case (3–64 of `[A-Za-z0-9_]`, with or
     * without the `@`) or the digits of a Telegram id (≤ 18). Null for anything else — a FORMAT check only, decided before any
     * lookup, so the refusal says nothing about the platform's learners.
     */
    public static function normalizeTelegramHandle(string $input): ?string
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            return strlen($input) <= 18 ? ltrim($input, '0') ?: null : null;
        }

        $username = ltrim($input, '@');

        return preg_match('/^[A-Za-z0-9_]{3,64}$/', $username) === 1 ? '@'.strtolower($username) : null;
    }

    /**
     * "Add a learner" from the `/org` cabinet (audit 2026-11-10 M2, review finding B1): an INVITATION, never a membership, and a
     * row for EVERY attempt — found learner or not. The cabinet shows only the handle, the date and "waiting" / "expired"
     * ({@see OrganizationMemberInvitation::state()}); nothing it can read differs between a handle that matched nobody, a learner
     * who never answered, one who declined and one the bot could not reach. The returned row carries the internal `learner_id`:
     * the caller must not pass it on.
     *
     * A handle with an invitation still open in this organization gets no second row and no second DM — the open one comes
     * back, whatever its internal state. Otherwise a row is written; the "Join / Decline" DM ({@see OrganizationMemberInvited})
     * is reserved (`dm_sent_at`) only for a learner who exists, is onboarded, reachable, not blocked, not a member already and
     * who got no DM from this center within `organizations.member_invite.dm_cooldown_days` and did not decline one within
     * `redecline_cooldown_days` (W7: the invite → revoke → invite loop is not a channel for spam). The hourly limits per
     * organization and per staff member count every call.
     *
     * @throws OrganizationMembershipException rate_limited | organization_suspended | telegram_invalid
     */
    public function inviteByStaff(Organization $organization, string $telegram, User $by, ?string $ip = null, ?CarbonImmutable $now = null): OrganizationMemberInvitation
    {
        $now ??= CarbonImmutable::now();
        $this->hitInviteLimits($organization, $by);

        if (! $organization->isActive()) {
            throw OrganizationMembershipException::organizationSuspended($organization->id);
        }

        $handle = self::normalizeTelegramHandle($telegram) ?? throw OrganizationMembershipException::telegramInvalid();
        $learner = $this->findLearnerByTelegram($handle);

        return DB::transaction(function () use ($organization, $handle, $learner, $by, $ip, $now): OrganizationMemberInvitation {
            $locked = $this->lockOrganization($organization->id);
            if ($locked === null || ! $locked->isActive()) {
                throw OrganizationMembershipException::organizationSuspended($organization->id);
            }

            /** @var OrganizationMemberInvitation|null $open */
            $open = $this->openInvitations($locked->id, $now)->where('invited_handle', $handle)->orderByDesc('id')->first();
            if ($open !== null) {
                return $open;
            }

            // The learner the DM goes to now, or null: unknown handle, unreachable, a member already, or inside a cooldown.
            $recipient = $learner !== null && $this->mayDm($locked->id, $learner, $now) ? $learner : null;

            $invitation = OrganizationMemberInvitation::query()->create([
                'organization_id' => $locked->id,
                'invited_handle' => $handle,
                'learner_id' => $learner?->id,
                'invited_by_user_id' => $by->id,
                'expires_at' => $now->addDays(max(1, (int) config('organizations.member_invite.ttl_days', 7))),
                'dm_sent_at' => $recipient === null ? null : $now,
            ]);

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::AUDIT_MEMBER_INVITED,
                'entity_type' => OrganizationMemberInvitation::class,
                'entity_id' => $invitation->id,
                'ip' => $ip,
                'meta' => ['organization_id' => $locked->id, 'handle' => $handle, 'learner_id' => $learner?->id, 'dm' => $recipient !== null],
            ]);

            if ($recipient !== null) {
                $event = new OrganizationMemberInvited($locked->id, $invitation->id, $recipient->id);

                DB::afterCommit(function () use ($event): void {
                    $reported = false;
                    $this->guard('organizations.membership_event_failed', $event->organizationId, $event->learnerId, function () use ($event): void {
                        event($event);
                    }, $reported);
                });
            }

            return $invitation;
        });
    }

    /**
     * The learner tapped "Join" on a staff invitation: an active membership (`joined_via = admin`, seated if a seat is free; the
     * usual join event sends "You are in center X"), the invitation — and every other open one of this learner here — is marked
     * accepted. The row must be the learner's own and still answerable.
     *
     * @throws OrganizationMembershipException invite_not_pending | organization_suspended
     */
    public function acceptInvite(Learner $learner, int $invitationId, ?CarbonImmutable $now = null): OrganizationMember
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($learner, $invitationId, $now): OrganizationMember {
            $pending = $this->answerableInvitation($learner, $invitationId, $now);
            $organization = $this->lockOrganization($pending->organization_id) ?? throw OrganizationMembershipException::inviteNotPending($invitationId);

            if (! $organization->isActive()) {
                throw OrganizationMembershipException::organizationSuspended($organization->id);
            }

            // Re-read under the organization lock: a revoke or a parallel tap is serialized here.
            $this->answerableInvitation($learner, $invitationId, $now);

            return $this->activate($organization, $learner, JoinedVia::Admin, null, $now)['member'];
        });
    }

    /**
     * "Decline": the invitation is closed for the learner (`declined_at`) and stays "waiting" for the staff until it expires —
     * a decline must read exactly like silence in the cabinet. Nothing else changes.
     *
     * @throws OrganizationMembershipException invite_not_pending
     */
    public function declineInvite(Learner $learner, int $invitationId, ?CarbonImmutable $now = null): OrganizationMemberInvitation
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($learner, $invitationId, $now): OrganizationMemberInvitation {
            $pending = $this->answerableInvitation($learner, $invitationId, $now);
            $this->lockOrganization($pending->organization_id);
            $pending = $this->answerableInvitation($learner, $invitationId, $now);

            $pending->update(['declined_at' => $now]);

            $this->events->record(
                ProductEventName::OrgMemberDeclined,
                ['organization_id' => $pending->organization_id, 'invitation_id' => $pending->id],
                learner: $learner,
            );

            return $pending;
        });
    }

    /**
     * The staff takes an open invitation back: marked `revoked_at` (the trace stays — W7: a revoked and re-sent invitation is
     * still subject to the DM cooldown), audited; a tap on the old buttons then says the invitation is gone. Only an open row
     * of this organization.
     *
     * @throws OrganizationMembershipException invite_not_pending
     */
    public function revokeInvite(Organization $organization, OrganizationMemberInvitation $invitation, User $by, ?string $ip = null, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        DB::transaction(function () use ($organization, $invitation, $by, $ip, $now): void {
            $this->lockOrganization($organization->id);

            /** @var OrganizationMemberInvitation|null $current */
            $current = OrganizationMemberInvitation::query()->whereKey($invitation->id)->where('organization_id', $organization->id)->lockForUpdate()->first();

            if ($current === null || ! $current->isOpenAt($now)) {
                throw OrganizationMembershipException::inviteNotPending($invitation->id);
            }

            $current->update(['revoked_at' => $now]);

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::AUDIT_INVITE_REVOKED,
                'entity_type' => OrganizationMemberInvitation::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                'meta' => ['organization_id' => $organization->id, 'handle' => $current->invited_handle],
            ]);
        });
    }

    /** The learner's own invitation that they may still answer (for the bot: what "Join / Decline" act on), with its organization. */
    public function pendingOfferOf(Learner $learner, int $invitationId, ?CarbonImmutable $now = null): ?OrganizationMemberInvitation
    {
        $now ??= CarbonImmutable::now();

        /** @var OrganizationMemberInvitation|null $invitation */
        $invitation = OrganizationMemberInvitation::query()
            ->with('organization')
            ->whereKey($invitationId)
            ->where('learner_id', $learner->id)
            ->first();

        return $invitation === null || ! $invitation->isAnswerableAt($now) ? null : $invitation;
    }

    /**
     * Added by a superadmin from `/admin` (joined_via = admin, active at once); audited. The center's staff do not use this path
     * any more — they offer ({@see inviteByStaff()}).
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
                $reported = false;
                $this->afterCommitSteps($event->organizationId, $event->learnerId, $event, $reported);
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

        // Whatever brought the learner in (a staff invitation, a code, a group), the invitations of this center that still
        // waited for them are answered: the cabinet shows "accepted" next to the ordinary member it now lists.
        $this->openInvitations($organization->id, $now)->where('learner_id', $learner->id)->update(['accepted_at' => $now, 'updated_at' => $now]);

        $this->events->record(
            ProductEventName::OrgMemberJoined,
            ['organization_id' => $organization->id, 'via' => $via->value, 'seat_state' => $seat->value, 'invite_id' => $invite?->id],
            learner: $learner,
        );

        $event = new OrganizationMemberJoined($organization->id, $learner->id, $member->id, $via->value, $seat->value);

        DB::afterCommit(function () use ($event): void {
            $reported = false;
            $this->afterCommitSteps($event->organizationId, $event->learnerId, $event, $reported);
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
            $reported = false;
            foreach ($events as $event) {
                $this->afterCommitSteps($event->organizationId, $event->learnerId, $event, $reported);
            }
        });
    }

    /**
     * After commit: drop the learner's cached entitlement, then raise the event. Each step is guarded - a failure
     * (Redis down, a listener throwing) is logged and never stops the next step or the next learner of the loop.
     * `$reported` is shared by the whole loop: only its first failure goes to `report()` (a dead Redis would otherwise send
     * up to 2N events to Sentry, synchronously); the rest are warnings.
     */
    private function afterCommitSteps(int $organizationId, int $learnerId, object $event, bool &$reported): void
    {
        $this->guard('organizations.membership_entitlement_forget_failed', $organizationId, $learnerId, fn () => $this->entitlements->forget($learnerId), $reported);
        $this->guard('organizations.membership_event_failed', $organizationId, $learnerId, function () use ($event): void {
            event($event);
        }, $reported);
    }

    /** @param callable(): mixed $step */
    private function guard(string $failureLog, int $organizationId, int $learnerId, callable $step, bool &$reported): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            if (! $reported) {
                $reported = true;
                report($e);
            }
            Log::warning($failureLog, [
                'organization_id' => $organizationId,
                'learner_id' => $learnerId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The learner's own invitation they may still answer, under its row lock (the caller holds / takes the organization lock
     * around it).
     *
     * @throws OrganizationMembershipException invite_not_pending
     */
    private function answerableInvitation(Learner $learner, int $invitationId, CarbonImmutable $now): OrganizationMemberInvitation
    {
        /** @var OrganizationMemberInvitation|null $invitation */
        $invitation = OrganizationMemberInvitation::query()
            ->whereKey($invitationId)
            ->where('learner_id', $learner->id)
            ->lockForUpdate()
            ->first();

        if ($invitation === null || ! $invitation->isAnswerableAt($now)) {
            throw OrganizationMembershipException::inviteNotPending($invitationId);
        }

        return $invitation;
    }

    /**
     * Invitations of the organization the staff still see as "waiting": not accepted, not revoked, not expired. A declined one
     * is among them on purpose.
     *
     * @return Builder<OrganizationMemberInvitation>
     */
    private function openInvitations(int $organizationId, CarbonImmutable $now): Builder
    {
        return OrganizationMemberInvitation::query()
            ->where('organization_id', $organizationId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now);
    }

    /**
     * Whether the "Join / Decline" DM goes to this learner now (W7): they can be reached, are not a member, got no DM from this
     * center within `dm_cooldown_days` and declined none within `redecline_cooldown_days`. Read under the organization lock, so
     * two parallel invitations cannot both reserve a DM.
     */
    private function mayDm(int $organizationId, Learner $learner, CarbonImmutable $now): bool
    {
        if ($learner->blocked_at !== null || $learner->dm_blocked_at !== null || $learner->onboarding_state !== OnboardingService::DONE) {
            return false;
        }

        if ($this->activeRow($organizationId, $learner->id)->exists()) {
            return false;
        }

        $dmWindow = max(0, (int) config('organizations.member_invite.dm_cooldown_days', 7));
        $declineWindow = max(0, (int) config('organizations.member_invite.redecline_cooldown_days', 7));

        return ! OrganizationMemberInvitation::query()
            ->where('organization_id', $organizationId)
            ->where('learner_id', $learner->id)
            ->where(fn (Builder $q): Builder => $q
                ->where('dm_sent_at', '>', $now->subDays($dmWindow))
                ->orWhere('declined_at', '>', $now->subDays($declineWindow)))
            ->exists();
    }

    /**
     * Two counters per hour — the organization and the staff member — in the non-evicting limiter store; every call counts,
     * so probing by @username is as limited as real offers.
     *
     * @throws OrganizationMembershipException rate_limited
     */
    private function hitInviteLimits(Organization $organization, User $by): void
    {
        $limits = [
            'org-member-invite:org:'.$organization->id => max(1, (int) config('organizations.member_invite.per_organization_per_hour', 30)),
            'org-member-invite:user:'.$by->id => max(1, (int) config('organizations.member_invite.per_staff_per_hour', 20)),
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw OrganizationMembershipException::rateLimited(RateLimiter::availableIn($key));
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }
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

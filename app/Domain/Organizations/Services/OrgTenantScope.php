<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\Assignment;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a staff user sees inside ONE organization of the `/org` cabinet (docs/13 §6.3).
 *
 * Every query here is bound to the organization passed in — never to "the current tenant" read
 * from global state — so the resources, the policies, the dashboard and the bot's `/org` all use
 * the same rule and the rule is testable without a Filament request:
 *
 *  - `owner`   — everything of the organization;
 *  - `teacher` — only the groups of the organization they are assigned to (`teacher_groups`),
 *                the learners active in those groups, no invites / staff / billing / settings.
 *
 * A user who is not staff of the organization gets a query that matches nothing (`whereRaw('0 = 1')`
 * is spelled as `whereKey([])` here): the panel's tenant check (`canAccessTenant`) already stops
 * such a user with a 404, this is the belt to that braces.
 */
final class OrgTenantScope
{
    public function role(User $user, Organization $organization): ?OrganizationRole
    {
        return $user->organizationRole($organization);
    }

    public function isOwner(User $user, Organization $organization): bool
    {
        return $this->role($user, $organization) === OrganizationRole::Owner;
    }

    public function isStaff(User $user, Organization $organization): bool
    {
        return $this->role($user, $organization) !== null;
    }

    /**
     * Groups of the organization visible to the user.
     *
     * @return Builder<TelegramGroup>
     */
    public function groups(User $user, Organization $organization): Builder
    {
        $query = TelegramGroup::query()->where('telegram_groups.organization_id', $organization->id);

        return match ($this->role($user, $organization)) {
            OrganizationRole::Owner => $query,
            OrganizationRole::Teacher => $query->whereIn('telegram_groups.id', $this->teacherGroupIds($user, $organization)),
            null => $query->whereKey([]),
        };
    }

    /**
     * Ids of the organization's groups a teacher is assigned to (`teacher_groups`); for an owner —
     * every group of the organization.
     *
     * @return list<int>
     */
    public function teacherGroupIds(User $user, Organization $organization): array
    {
        $query = TelegramGroup::query()->where('telegram_groups.organization_id', $organization->id);

        if ($this->role($user, $organization) !== OrganizationRole::Owner) {
            $query->whereIn('telegram_groups.id', fn ($sub) => $sub
                ->select('telegram_group_id')
                ->from('teacher_groups')
                ->where('user_id', $user->id));
        }

        /** @var list<int> $ids */
        $ids = $query->pluck('telegram_groups.id')->map(fn ($id): int => (int) $id)->values()->all();

        return $ids;
    }

    /**
     * Membership rows of the organization visible to the user: owner — every row (the resource
     * filters by status itself); teacher — the active memberships of learners who are active in
     * one of the teacher's groups.
     *
     * @return Builder<OrganizationMember>
     */
    public function members(User $user, Organization $organization): Builder
    {
        $query = OrganizationMember::query()->where('organization_members.organization_id', $organization->id);

        return match ($this->role($user, $organization)) {
            OrganizationRole::Owner => $query,
            OrganizationRole::Teacher => $query
                ->where('organization_members.status', MemberStatus::Active->value)
                ->whereIn('organization_members.learner_id', $this->groupLearnerIdsSubquery($user, $organization)),
            null => $query->whereKey([]),
        };
    }

    /**
     * Learners the user may see: active members of the organization (owner) or active members
     * who are also active in one of the teacher's groups (teacher).
     *
     * @return Builder<Learner>
     */
    public function learners(User $user, Organization $organization): Builder
    {
        $query = Learner::query();

        if ($this->role($user, $organization) === null) {
            return $query->whereKey([]);
        }

        $query->whereIn('learners.id', fn ($sub) => $sub
            ->select('learner_id')
            ->from('organization_members')
            ->where('organization_id', $organization->id)
            ->where('status', MemberStatus::Active->value));

        if ($this->role($user, $organization) === OrganizationRole::Teacher) {
            $query->whereIn('learners.id', $this->groupLearnerIdsSubquery($user, $organization));
        }

        return $query;
    }

    /**
     * Invite codes: owner only.
     *
     * @return Builder<OrganizationInvite>
     */
    public function invites(User $user, Organization $organization): Builder
    {
        $query = OrganizationInvite::query()->where('organization_invites.organization_id', $organization->id);

        return $this->isOwner($user, $organization) ? $query : $query->whereKey([]);
    }

    /**
     * Staff rows: owner only.
     *
     * @return Builder<OrganizationUser>
     */
    public function staff(User $user, Organization $organization): Builder
    {
        $query = OrganizationUser::query()->where('organization_users.organization_id', $organization->id);

        return $this->isOwner($user, $organization) ? $query : $query->whereKey([]);
    }

    /**
     * Assignments of the organization visible to the user: owner — all; teacher — only those of
     * their own groups (a group is theirs through `teacher_groups`).
     *
     * @return Builder<Assignment>
     */
    public function assignments(User $user, Organization $organization): Builder
    {
        $query = Assignment::query()->where('assignments.organization_id', $organization->id);

        return match ($this->role($user, $organization)) {
            OrganizationRole::Owner => $query,
            OrganizationRole::Teacher => $query->whereIn('assignments.telegram_group_id', $this->teacherGroupIds($user, $organization)),
            null => $query->whereKey([]),
        };
    }

    /** Whether the user may see this assignment (same rule as assignments()). */
    public function canSeeAssignment(User $user, Organization $organization, Assignment $assignment): bool
    {
        if ($assignment->organization_id !== $organization->id) {
            return false;
        }

        return match ($this->role($user, $organization)) {
            OrganizationRole::Owner => true,
            OrganizationRole::Teacher => in_array($assignment->telegram_group_id, $this->teacherGroupIds($user, $organization), true),
            null => false,
        };
    }

    /** Whether the user may see this group (same rule as groups()). */
    public function canSeeGroup(User $user, Organization $organization, TelegramGroup $group): bool
    {
        if ($group->organization_id !== $organization->id) {
            return false;
        }

        return match ($this->role($user, $organization)) {
            OrganizationRole::Owner => true,
            OrganizationRole::Teacher => $group->teachers()->whereKey($user->id)->exists(),
            null => false,
        };
    }

    /** Whether the user may see this membership row (same rule as members()). */
    public function canSeeMember(User $user, Organization $organization, OrganizationMember $member): bool
    {
        if ($member->organization_id !== $organization->id) {
            return false;
        }

        return match ($this->role($user, $organization)) {
            OrganizationRole::Owner => true,
            OrganizationRole::Teacher => $member->isActive()
                && $this->learners($user, $organization)->whereKey($member->learner_id)->exists(),
            null => false,
        };
    }

    /** Whether the user may see this learner (same rule as learners()). */
    public function canSeeLearner(User $user, Organization $organization, Learner $learner): bool
    {
        return $this->learners($user, $organization)->whereKey($learner->id)->exists();
    }

    /**
     * Learners active (`group_members.status = active`) in one of the teacher's groups of the organization.
     *
     * @return \Closure(\Illuminate\Database\Query\Builder): void
     */
    private function groupLearnerIdsSubquery(User $user, Organization $organization): \Closure
    {
        $groupIds = $this->teacherGroupIds($user, $organization);

        return function ($sub) use ($groupIds): void {
            $sub->select('learner_id')
                ->from('group_members')
                ->where('status', 'active')
                ->whereIn('telegram_group_id', $groupIds);
        };
    }
}

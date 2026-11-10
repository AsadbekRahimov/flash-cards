<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\DTO\OrgChecklist;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberInvitation;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * "First steps" of the `/org` dashboard (audit 2026-11-10 §4 P1): five yes / no facts for the signed-in staff member, computed
 * in three aggregate queries whatever the size of the center (docs/20 §1.3 — no per-row work): one over `telegram_groups`
 * (linked? one with a course?), one `EXISTS` over `organization_members` ∪ `organization_member_invitations`, one `EXISTS` over
 * group trainings. Step 1 is the user row itself. The widget hides the list once everything is done; nothing is cached — three indexed lookups cost less than a
 * cache round trip would save, and the list disappears after the first week.
 */
final class OrgOnboardingChecklist
{
    public function build(User $user, Organization $organization): OrgChecklist
    {
        $groups = TelegramGroup::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->selectRaw('COUNT(*) AS linked, COUNT(course_id) AS with_course')
            ->first();

        $linked = (int) ($groups?->getAttribute('linked') ?? 0);
        $withCourse = (int) ($groups?->getAttribute('with_course') ?? 0);

        // "Learners invited" = an active member, or any invitation the staff sent (found learner or not: the step is the owner's
        // action, and the list must not say whether the handle matched anybody).
        $learners = OrganizationMember::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', MemberStatus::Active->value)
            ->selectRaw('1')
            ->union(OrganizationMemberInvitation::query()->where('organization_id', $organization->id)->selectRaw('1'))
            ->exists();

        // Always run (a fixed three queries, whatever the center looks like): the budget test counts them.
        $training = TrainingSession::query()
            ->where('mode', 'group_lesson')
            ->whereIn('telegram_group_id', TelegramGroup::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->select('id'))
            ->exists();

        return new OrgChecklist(
            telegramConnected: $user->hasVerifiedTelegram(),
            groupLinked: $linked > 0,
            courseChosen: $withCourse > 0,
            learnersInvited: $learners,
            trainingStarted: $training,
        );
    }
}

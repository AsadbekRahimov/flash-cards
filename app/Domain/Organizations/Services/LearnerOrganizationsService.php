<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Models\Learner;
use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Collection;

/**
 * "My centers" of one learner (docs/13 §6.2): the list shown in the TWA settings and in the bot's
 * `/settings`, and "Leave the center". The learner reaches only THEIR OWN active memberships — any
 * other id is `membership_not_found`, whoever it belongs to. The write itself is
 * {@see OrganizationMembershipService::leave()}.
 *
 * A `joined_via = group` membership cannot be left here: it is created again on the next entry to a
 * group chat of the center (and ends by itself when the learner leaves all of them), so a "Leave"
 * button would only lie. The clients explain that instead.
 */
final class LearnerOrganizationsService
{
    public function __construct(private readonly OrganizationMembershipService $memberships) {}

    /**
     * Active memberships, newest first, with the organization loaded.
     *
     * @return Collection<int, OrganizationMember>
     */
    public function memberships(Learner $learner): Collection
    {
        return OrganizationMember::query()
            ->with('organization')
            ->where('learner_id', $learner->id)
            ->where('status', MemberStatus::Active->value)
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->get();
    }

    public function hasMemberships(Learner $learner): bool
    {
        return OrganizationMember::query()
            ->where('learner_id', $learner->id)
            ->where('status', MemberStatus::Active->value)
            ->exists();
    }

    /**
     * One of the learner's own active memberships, with the organization loaded.
     *
     * @throws OrganizationMembershipException membership_not_found
     */
    public function find(Learner $learner, int $memberId): OrganizationMember
    {
        /** @var OrganizationMember|null $member */
        $member = OrganizationMember::query()
            ->with('organization')
            ->whereKey($memberId)
            ->where('learner_id', $learner->id)
            ->where('status', MemberStatus::Active->value)
            ->first();

        return $member ?? throw OrganizationMembershipException::membershipNotFound($memberId);
    }

    /**
     * The learner leaves a center (`status = left`).
     *
     * @throws OrganizationMembershipException membership_not_found | membership_via_group
     */
    public function leave(Learner $learner, int $memberId): OrganizationMember
    {
        $member = $this->find($learner, $memberId);

        if (! $member->isLeavableByLearner()) {
            throw OrganizationMembershipException::membershipViaGroup($member->id);
        }

        try {
            $this->memberships->leave($learner, $member->organization, MemberStatus::Left->value);
        } catch (OrganizationMembershipException $e) {
            // Ended by a parallel request between the read and the lock.
            if ($e->errorCode === OrganizationMembershipException::NOT_A_MEMBER) {
                throw OrganizationMembershipException::membershipNotFound($memberId);
            }

            throw $e;
        }

        return $member;
    }
}

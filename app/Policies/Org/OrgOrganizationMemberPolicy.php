<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\OrganizationMember;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Memberships (seats) of the tenant: the owner sees all rows and may add / remove members; a
 * teacher sees the active members of their groups, read-only. Rows are written only through
 * OrganizationMembershipService (`create` / `delete` guard the custom "add" / "remove" actions).
 */
final class OrgOrganizationMemberPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function view(User $user, OrganizationMember $member): bool
    {
        $tenant = $this->staffTenant($user);

        return $tenant !== null && $this->scope()->canSeeMember($user, $tenant, $member);
    }

    public function create(User $user): bool
    {
        return $this->ownerTenant($user) !== null;
    }

    public function update(User $user, OrganizationMember $member): bool
    {
        return false;
    }

    public function delete(User $user, OrganizationMember $member): bool
    {
        $tenant = $this->ownerTenant($user);

        return $tenant !== null && $member->organization_id === $tenant->id && $member->isActive();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

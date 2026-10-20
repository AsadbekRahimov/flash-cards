<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\OrganizationUser;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Staff rows of the tenant (Teachers screen): owner only. Writes go through
 * OrganizationStaffService (invite / role / groups / remove); `update` guards those actions,
 * `delete` the removal.
 */
final class OrgOrganizationUserPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->ownerTenant($user) !== null;
    }

    public function view(User $user, OrganizationUser $staff): bool
    {
        return $this->ownerTenant($user)?->id === $staff->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->ownerTenant($user) !== null;
    }

    public function update(User $user, OrganizationUser $staff): bool
    {
        return $this->ownerTenant($user)?->id === $staff->organization_id;
    }

    public function delete(User $user, OrganizationUser $staff): bool
    {
        return $this->ownerTenant($user)?->id === $staff->organization_id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

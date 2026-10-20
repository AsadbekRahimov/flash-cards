<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * The organization itself inside its own cabinet: staff may see it, the owner may edit the
 * settings (`EditTenantProfile`); it is never created, listed or deleted from here.
 */
final class OrgOrganizationPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->staffTenant($user)?->id === $organization->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->ownerTenant($user)?->id === $organization->id;
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

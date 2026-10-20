<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\OrganizationInvite;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/** Invite codes of the tenant: owner only (a teacher does not recruit for the center). */
final class OrgOrganizationInvitePolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->ownerTenant($user) !== null;
    }

    public function view(User $user, OrganizationInvite $invite): bool
    {
        return $this->ownerTenant($user)?->id === $invite->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->ownerTenant($user) !== null;
    }

    /** "Deactivate" — the only write; codes are never edited or deleted (members keep `invite_id`). */
    public function update(User $user, OrganizationInvite $invite): bool
    {
        return $this->ownerTenant($user)?->id === $invite->organization_id;
    }

    public function delete(User $user, OrganizationInvite $invite): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

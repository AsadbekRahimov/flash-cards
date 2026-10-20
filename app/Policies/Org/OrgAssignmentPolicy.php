<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\Assignment;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Assignments of the tenant: the owner works with all of them, a teacher only with those of their
 * own groups. "Close" is the only write after creation (`update`); nothing is deleted — the
 * snapshot is the report. Whether a group may be picked on creation is checked by the service
 * against the same scope (`OrgTenantScope::groups`).
 */
final class OrgAssignmentPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function view(User $user, Assignment $assignment): bool
    {
        $tenant = $this->staffTenant($user);

        return $tenant !== null && $this->scope()->canSeeAssignment($user, $tenant, $assignment);
    }

    public function create(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $this->view($user, $assignment);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

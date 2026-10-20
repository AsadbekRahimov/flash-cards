<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationMember;
use App\Models\User;

/**
 * Members and seats of a center (docs/13 §1.3): active admins only, through the organization's relation manager.
 * A membership is ended (`removed`) and added by OrganizationMembershipService, never edited or deleted as a row.
 */
final class OrganizationMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, OrganizationMember $member): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, OrganizationMember $member): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, OrganizationMember $member): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function manage(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }
}

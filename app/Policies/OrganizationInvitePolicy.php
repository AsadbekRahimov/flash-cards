<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationInvite;
use App\Models\User;

/** Invite links of a center (docs/13 §1.3): active admins only, through the organization's relation manager. A link is deactivated, never deleted. */
final class OrganizationInvitePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, OrganizationInvite $invite): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, OrganizationInvite $invite): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, OrganizationInvite $invite): bool
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

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/** Organizations (learning centers that sponsor Pro for their groups): active admins only. */
final class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->manage($user);
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

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Administration\Services\OrganizationAdminService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Organizations (learning centers that sponsor Pro for their groups): active admins only. One with a live
 * subscription or linked groups is not deleted (suspend it: status `suspended`).
 */
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

    /** A denied delete carries the reason: Filament shows it as the action's tooltip. */
    public function delete(User $user, Organization $organization): Response
    {
        if (! $this->manage($user)) {
            return Response::deny();
        }

        $blocker = app(OrganizationAdminService::class)->deletionBlocker($organization);

        return $blocker === null ? Response::allow() : Response::deny($blocker);
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

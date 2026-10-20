<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\TelegramGroup;
use App\Models\User;
use App\Policies\Org\Concerns\ResolvesOrgTenant;

/**
 * Groups of the tenant: the owner sees and manages all of them, a teacher only sees the groups
 * they are assigned to (`teacher_groups`). Linking / unlinking is a custom action authorized by
 * `update`; groups are never created or deleted from the cabinet.
 */
final class OrgTelegramGroupPolicy
{
    use ResolvesOrgTenant;

    public function viewAny(User $user): bool
    {
        return $this->staffTenant($user) !== null;
    }

    public function view(User $user, TelegramGroup $group): bool
    {
        $tenant = $this->staffTenant($user);

        return $tenant !== null && $this->scope()->canSeeGroup($user, $tenant, $group);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TelegramGroup $group): bool
    {
        $tenant = $this->ownerTenant($user);

        return $tenant !== null && $group->organization_id === $tenant->id;
    }

    public function delete(User $user, TelegramGroup $group): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

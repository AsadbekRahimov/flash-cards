<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClassCaravan;
use App\Models\User;

/**
 * The class caravans in `/admin` (docs/18 §12.10): read-only, for an active platform admin only; "Отменить" is a custom action, the same
 * right as the cancel of `ClassCaravanService`. The cabinet of a center has its own policy (`Org\OrgClassCaravanPolicy`).
 */
final class ClassCaravanPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function view(User $user, ClassCaravan $caravan): bool
    {
        return $this->admin($user);
    }

    public function cancel(User $user, ClassCaravan $caravan): bool
    {
        return $this->admin($user) && $caravan->isActive();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ClassCaravan $caravan): bool
    {
        return false;
    }

    public function delete(User $user, ClassCaravan $caravan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function admin(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }
}

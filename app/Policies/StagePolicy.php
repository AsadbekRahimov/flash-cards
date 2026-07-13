<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Stage;
use App\Models\User;

final class StagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    public function view(User $user, Stage $stage): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function update(User $user, Stage $stage): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Stage $stage): bool
    {
        return false;
    }
}

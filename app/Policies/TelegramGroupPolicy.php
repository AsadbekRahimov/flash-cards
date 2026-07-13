<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TelegramGroup;
use App\Models\User;

final class TelegramGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    public function view(User $user, TelegramGroup $group): bool
    {
        return $this->viewAny($user)
            && ($user->hasRole('admin') || $group->teachers()->whereKey($user->id)->exists());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TelegramGroup $group): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function delete(User $user, TelegramGroup $group): bool
    {
        return false;
    }
}

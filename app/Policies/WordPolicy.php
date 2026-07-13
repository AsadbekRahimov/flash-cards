<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Word;

final class WordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    public function view(User $user, Word $word): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function update(User $user, Word $word): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Word $word): bool
    {
        return false;
    }
}

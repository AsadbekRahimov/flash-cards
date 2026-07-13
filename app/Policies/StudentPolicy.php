<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

final class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    public function view(User $user, Student $student): bool
    {
        $group = $student->group;

        return $this->viewAny($user)
            && ($user->hasRole('admin') || ($group !== null && $group->teachers()->whereKey($user->id)->exists()));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Student $student): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function delete(User $user, Student $student): bool
    {
        return false;
    }
}

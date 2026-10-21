<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

final class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['admin', 'teacher']);
    }

    /** The content of a learner's private deck is for admins only (docs/13 §8.1). */
    public function view(User $user, Lesson $lesson): bool
    {
        return $this->viewAny($user) && ($user->hasRole('admin') || $lesson->stage?->course?->isLearnerOwned() !== true);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole('admin');
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return false;
    }
}
